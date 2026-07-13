<?php
/* api/quote_costs.php — capture the ACTUAL cost of each line of a PROJECT (or an
   invoiced job) into the relational project_costs table.

   During a project (no invoice yet) costs are pure DB — nothing hits Zoho. Once the
   job is billed (has an invoice number) each change is mirrored to its Zoho expense:
   new row -> POST, changed -> PUT, removed -> DELETE. Prices/profit are stripped
   server-side for non-admins.

   Access: admin, the quote's creator, or a user with 'costcap' — status project|invoiced.

   Actions (POST JSON):
     {action:'get', id}                                    -> {ok, admin, quote, config?}
     {action:'save_costs', id, lines:[{index, line_name, cost_rows:[{id?,category,description,qty,unit_cost}]}]}
                                                           -> {ok, admin, quote, warnings[]}
     {action:'config_get'}            (admin)              -> {ok, config}
     {action:'config_set', account_id, paid_through_account_id?}  (admin) -> {ok, config} */

session_start();
header('Content-Type: application/json; charset=utf-8');
if (empty($_SESSION['auth'])) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Not signed in.']); exit; }
require __DIR__ . '/../db.php';
require __DIR__ . '/../quote_pricing.php';
require __DIR__ . '/../project_costs.php';   // table + Zoho expense helpers (+ costcap_config via costcap_sync)
$cfg = require __DIR__ . '/../config.php';

$CATS = ['parts','labour','consumables','subcontract','other'];

try {
    $pdo   = db();
    pc_table($pdo);
    $me    = $_SESSION['user'] ?? '';
    $admin = !empty($_SESSION['is_admin']);
    $tabsS   = (string)($_SESSION['tabs'] ?? '');
    $costcap = ($tabsS === '*') || in_array('costcap', array_map('trim', explode(',', $tabsS)), true);

    $in = json_decode(file_get_contents('php://input'), true); if (!is_array($in)) $in = $_POST;
    $action = $in['action'] ?? 'get';

    /* --- admin-only booking config --- */
    if ($action === 'config_get') {
        if (!$admin) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Admins only.']); exit; }
        echo json_encode(['ok'=>true, 'config'=>costcap_config()]); exit;
    }
    if ($action === 'config_set') {
        if (!$admin) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Admins only.']); exit; }
        $c = costcap_config_save(trim((string)($in['account_id'] ?? '')), trim((string)($in['paid_through_account_id'] ?? '')));
        echo json_encode(['ok'=>true, 'config'=>$c]); exit;
    }

    /* load a project / invoiced job the caller may cost.
       Admins can cost any project or invoiced job. The team can only cost OPEN
       projects (status='project', not closed) — once you close it, they lose access. */
    $load = function($id) use ($pdo, $me, $admin, $costcap) {
        $st = $pdo->prepare("SELECT * FROM quotes WHERE id=?"); $st->execute([(int)$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new Exception('Job not found.');
        if ($admin) {
            if (!in_array($row['status'], ['project','invoiced'], true)) throw new Exception('Costs are captured on a project (or an invoiced job).');
            return $row;
        }
        if ($row['created_by'] !== $me && !$costcap) throw new Exception('You do not have access to this job.');
        if ($row['status'] !== 'project' || !empty($row['project_closed'])) throw new Exception('This project is closed — ask an admin to reopen it to add costs.');
        return $row;
    };

    /* assemble the response: line items (JSON) + their cost rows (table); price-stripped for non-admins */
    $assemble = function($quoteId) use ($pdo, $admin) {
        $st = $pdo->prepare("SELECT * FROM quotes WHERE id=?"); $st->execute([(int)$quoteId]);
        $q = quote_out($st->fetch(PDO::FETCH_ASSOC));
        $byLine = [];
        foreach (pc_for_quote($pdo, $quoteId) as $c) {
            $byLine[(int)$c['line_index']][] = [
                'id'=>(int)$c['id'], 'category'=>$c['category'], 'description'=>$c['description'],
                'qty'=>(float)$c['qty'], 'unit_cost'=>(float)$c['unit_cost'], 'amount'=>(float)$c['amount'],
            ];
        }
        foreach ($q['line_items'] as $i => &$it) { $it['cost_rows'] = $byLine[$i] ?? []; }
        unset($it);
        return $admin ? $q : quote_strip_prices($q);   // strip keeps cost_rows, zeroes prices/profit
    };

    if ($action === 'get') {
        $row = $load($in['id'] ?? 0);
        $out = ['ok'=>true, 'admin'=>$admin, 'quote'=>$assemble($row['id'])];
        if ($admin) $out['config'] = costcap_config();
        echo json_encode($out); exit;
    }

    if ($action === 'save_costs') {
        $row     = $load($in['id'] ?? 0);
        $quoteId = (int)$row['id'];
        $items   = json_decode($row['line_items'] ?: '[]', true) ?: [];
        $invNo   = trim((string)($row['zoho_invoice_number'] ?? ''));
        $conf    = costcap_config();
        $billed  = ($invNo !== '');
        $canPost = ($billed && ($conf['account_id'] ?? '') !== '');
        $warnings = [];
        if ($billed && ($conf['account_id'] ?? '') === '') {
            $warnings[] = 'Costs saved, but expense changes were not pushed to Zoho — an admin must set the cost expense account.';
        }

        foreach ((array)($in['lines'] ?? []) as $ln) {
            $idx = (int)($ln['index'] ?? -1);
            if ($idx < 0) continue;
            $lineName = substr(trim((string)($ln['line_name'] ?? ($items[$idx]['name'] ?? 'Item'))), 0, 190);

            // existing rows for this line, keyed by id
            $oldById = [];
            foreach (pc_for_quote($pdo, $quoteId) as $c) {
                if ((int)$c['line_index'] === $idx) $oldById[(int)$c['id']] = $c;
            }

            $keptIds = [];
            foreach ((array)($ln['cost_rows'] ?? []) as $r) {
                $desc = trim((string)($r['description'] ?? ''));
                $qty  = (float)($r['qty'] ?? 0); if ($qty <= 0) $qty = 1;
                $unit = (float)($r['unit_cost'] ?? 0);
                if ($desc === '' && $unit <= 0) continue;                 // empty row — skip
                $cat  = strtolower(trim((string)($r['category'] ?? 'other')));
                if (!in_array($cat, $CATS, true)) $cat = 'other';
                $desc = substr($desc, 0, 190);
                $amount = round($qty * max(0, $unit), 2);
                $rid  = (int)($r['id'] ?? 0);
                $old  = ($rid && isset($oldById[$rid])) ? $oldById[$rid] : null;

                if ($old) {
                    $keptIds[$rid] = true;
                    $expId = (string)($old['zoho_expense_id'] ?? '');
                    $changed = abs((float)$old['amount'] - $amount) > 0.005
                        || (string)$old['category'] !== $cat
                        || (string)$old['description'] !== $desc
                        || (string)$old['line_name'] !== $lineName;
                    if ($changed) {
                        $pdo->prepare("UPDATE project_costs SET line_name=?, category=?, description=?, qty=?, unit_cost=?, amount=? WHERE id=?")
                            ->execute([$lineName, $cat, $desc, $qty, max(0,$unit), $amount, $rid]);
                    }
                    if ($canPost) {
                        $ez = ['line_name'=>$lineName,'category'=>$cat,'description'=>$desc,'amount'=>$amount];
                        if ($expId === '') {   // not yet pushed (e.g. billed while account was unset) — push now
                            [$newExp,$err] = pc_zoho_create($ez, $invNo, $conf);
                            if ($newExp !== '') $pdo->prepare("UPDATE project_costs SET zoho_expense_id=? WHERE id=?")->execute([$newExp,$rid]);
                            else $warnings[] = 'Zoho expense post failed for "'.$lineName.'": '.$err;
                        } elseif ($changed) {
                            $err = pc_zoho_update($expId, $ez, $invNo, $conf);
                            if ($err) $warnings[] = 'Zoho expense update failed for "'.$lineName.'": '.$err;
                        }
                    }
                } else {
                    // new row
                    $pdo->prepare("INSERT INTO project_costs (quote_id,line_index,line_name,category,description,qty,unit_cost,amount,created_by) VALUES (?,?,?,?,?,?,?,?,?)")
                        ->execute([$quoteId,$idx,$lineName,$cat,$desc,$qty,max(0,$unit),$amount,$me]);
                    $newId = (int)$pdo->lastInsertId();
                    $keptIds[$newId] = true;
                    if ($canPost) {
                        [$newExp,$err] = pc_zoho_create(['line_name'=>$lineName,'category'=>$cat,'description'=>$desc,'amount'=>$amount], $invNo, $conf);
                        if ($newExp !== '') $pdo->prepare("UPDATE project_costs SET zoho_expense_id=? WHERE id=?")->execute([$newExp,$newId]);
                        else $warnings[] = 'Zoho expense post failed for "'.$lineName.'": '.$err;
                    }
                }
            }

            // delete rows removed from this line (and their Zoho expense if billed)
            foreach ($oldById as $oid => $oc) {
                if (empty($keptIds[$oid])) {
                    if ($canPost && !empty($oc['zoho_expense_id'])) { $err = pc_zoho_delete((string)$oc['zoho_expense_id']); if ($err) $warnings[] = 'Zoho expense delete failed: '.$err; }
                    $pdo->prepare("DELETE FROM project_costs WHERE id=?")->execute([$oid]);
                }
            }
        }

        pc_refresh_quote($pdo, $quoteId);

        require_once __DIR__ . '/../activity_store.php';
        activity_log($pdo, $me, 'captured job costs', ($invNo ?: ('#'.$quoteId)) . ' · ' . ($row['customer_name'] ?? '') . ' (cost ' . number_format(pc_total($pdo,$quoteId), 0) . ')');

        echo json_encode(['ok'=>true, 'admin'=>$admin, 'quote'=>$assemble($quoteId), 'warnings'=>$warnings]); exit;
    }

    throw new Exception('Unknown action.');
} catch (Exception $e) {
    echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
}

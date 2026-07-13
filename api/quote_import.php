<?php
/* api/quote_import.php — bring a Zoho estimate into the app as a local quote so it can
   be converted to a project, costed, and billed like any app quote. Admin only.

   Actions (POST JSON):
     {action:'search', q}          -> matching Zoho estimates (+ whether already imported)
     {action:'import', estimate_id} -> create (or return existing) local quote from the estimate

   Budgeted unit cost is 0 on import (Zoho has no internal cost); actual costs are captured
   later against the project. Idempotent: importing the same estimate returns the existing quote. */

session_start();
header('Content-Type: application/json; charset=utf-8');
if (empty($_SESSION['auth']) || empty($_SESSION['is_admin'])) {
    http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Admins only.']); exit;
}
require __DIR__ . '/../db.php';
require __DIR__ . '/../zoho.php';
require __DIR__ . '/../quote_pricing.php';
require __DIR__ . '/../project_costs.php';   // pc_table ensures is_project / actual_* columns
$cfg = require __DIR__ . '/../config.php';

/* map a Zoho estimate status to a local, actionable status */
function qimport_status($z){
    $z = strtolower(trim((string)$z));
    if ($z === 'accepted') return 'accepted';
    if ($z === 'sent')     return 'sent';
    return 'approved';   // draft / declined / expired -> ready to work with in the app
}

try {
    $pdo = db();
    pc_table($pdo);
    $me  = $_SESSION['user'] ?? '';
    $vat = (float)($cfg['vat_rate'] ?? 0.16);
    $in  = json_decode(file_get_contents('php://input'), true); if (!is_array($in)) $in = $_POST;
    $action = $in['action'] ?? 'search';

    if ($action === 'search') {
        $q = trim((string)($in['q'] ?? ''));
        if ($q === '') throw new Exception('Type a quote number, customer or reference to search Zoho.');
        [$d, $c] = zoho_api('GET', 'estimates', null, ['search_text' => $q, 'per_page' => 25]);
        if ($c >= 400) throw new Exception($d['message'] ?? ('Zoho error ' . $c));
        $ests = $d['estimates'] ?? [];
        // which of these are already imported locally?
        $ids = array_values(array_filter(array_map(fn($e)=>(string)($e['estimate_id'] ?? ''), $ests)));
        $importedMap = [];
        if ($ids) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $st = $pdo->prepare("SELECT id, zoho_estimate_id FROM quotes WHERE zoho_estimate_id IN ($ph)");
            $st->execute($ids);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $importedMap[(string)$r['zoho_estimate_id']] = (int)$r['id'];
        }
        $items = array_map(function($e) use ($importedMap){
            $zid = (string)($e['estimate_id'] ?? '');
            return [
                'estimate_id' => $zid,
                'number'      => (string)($e['estimate_number'] ?? ''),
                'customer'    => (string)($e['customer_name'] ?? ''),
                'date'        => substr((string)($e['date'] ?? ''), 0, 10),
                'status'      => strtolower((string)($e['status'] ?? '')),
                'total'       => (float)($e['total'] ?? 0),
                'currency'    => (string)($e['currency_code'] ?? 'KES'),
                'imported'    => isset($importedMap[$zid]),
                'local_id'    => $importedMap[$zid] ?? 0,
            ];
        }, $ests);
        echo json_encode(['ok'=>true, 'items'=>$items]); exit;
    }

    if ($action === 'import') {
        $eid = trim((string)($in['estimate_id'] ?? '')); if ($eid === '') throw new Exception('No estimate id.');

        [$d, $c] = zoho_api('GET', 'estimates/' . $eid);
        if ($c >= 400 || empty($d['estimate'])) throw new Exception($d['message'] ?? 'Could not load the estimate from Zoho.');
        $e = $d['estimate'];

        if (strtolower((string)($e['status'] ?? '')) === 'invoiced') {
            throw new Exception('This estimate is already invoiced in Zoho — import is for un-invoiced quotes.');
        }

        // map Zoho line items -> our line-item shape (budgeted cost 0).
        // NB: Zoho often returns name as an empty string (not null), so `??` won't fall back —
        // treat a blank name as missing and promote the description into the name.
        $rawItems = [];
        foreach (($e['line_items'] ?? []) as $li) {
            $nm   = trim((string)($li['name'] ?? ''));
            $desc = trim((string)($li['description'] ?? ''));
            if ($nm === '') { $nm = ($desc !== '') ? $desc : 'Item'; if ($nm === $desc) $desc = ''; }
            $taxed = (!empty($li['tax_id'])) || ((float)($li['tax_percentage'] ?? 0) > 0);
            $qty   = (float)($li['quantity'] ?? ($li['qty'] ?? 1));
            $rawItems[] = [
                'name'        => $nm,
                'description' => $desc,
                'qty'         => $qty > 0 ? $qty : 1,
                'rate'        => (float)($li['rate'] ?? ($li['item_total'] ?? 0)),
                'cost'        => 0,
                'tax'         => $taxed ? 'vat' : 'none',
            ];
        }
        if (!$rawItems) throw new Exception('That estimate has no line items.');

        [$items, $sub, $discAmt, $tax, $total, $costTotal, $profit] = quote_price($rawItems, $vat, 0, 'percent');
        if (!$items) throw new Exception('Could not read any line items from that estimate.');

        $number = (string)($e['estimate_number'] ?? '');
        $custNm = (string)($e['customer_name'] ?? '');
        $ref    = substr((string)($e['reference_number'] ?? ''), 0, 80);
        $subj   = substr(($ref !== '' ? $ref : ('Estimate ' . $number)) ?: 'Imported quote', 0, 190);
        $status = qimport_status($e['status'] ?? '');
        $notes  = substr((string)($e['notes'] ?? ''), 0, 1000);
        $terms  = substr((string)($e['terms'] ?? ''), 0, 2000);
        $qdate  = preg_replace('/[^0-9\-]/','',(string)($e['date'] ?? '')) ?: null;
        $edate  = preg_replace('/[^0-9\-]/','',(string)($e['expiry_date'] ?? '')) ?: null;
        $cur    = (string)($e['currency_code'] ?? 'KES');
        $custId = (string)($e['customer_id'] ?? '');
        $itemsJson = json_encode($items);
        $warnings = [];
        $zohoTotal = (float)($e['total'] ?? 0);
        if ($zohoTotal > 0 && abs($zohoTotal - $total) > 1.0) {
            $warnings[] = 'Zoho total (' . number_format($zohoTotal,0) . ') differs from the recomputed total (' . number_format($total,0) . ') — likely a Zoho discount not carried over. Check the quote before billing.';
        }

        // already imported? refresh its line items (so a bad earlier import self-heals),
        // unless it's already a live project/invoice — then leave it untouched.
        $st = $pdo->prepare("SELECT * FROM quotes WHERE zoho_estimate_id=? LIMIT 1"); $st->execute([$eid]);
        $ex = $st->fetch(PDO::FETCH_ASSOC);
        require_once __DIR__ . '/../activity_store.php';
        if ($ex) {
            if (in_array($ex['status'], ['project','invoiced'], true)) {
                echo json_encode(['ok'=>true, 'already'=>true, 'quote'=>quote_out($ex), 'warnings'=>$warnings]); exit;
            }
            $pdo->prepare("UPDATE quotes SET zoho_customer_id=?, customer_name=?, reference=?, subject=?, quote_date=?, expiry_date=?, currency=?, line_items=?, notes=?, terms=?, sub_total=?, tax_amount=?, total_cost=?, profit=?, total=?, status=? WHERE id=?")
                ->execute([$custId, $custNm, $ref, $subj, $qdate, $edate, $cur, $itemsJson, $notes, $terms, $sub, $tax, $costTotal, $profit, $total, $status, (int)$ex['id']]);
            $id = (int)$ex['id'];
            activity_log($pdo, $me, 're-imported quote from Zoho', $number . ' · ' . $custNm . ' (' . count($items) . ' items)');
            $st = $pdo->prepare("SELECT * FROM quotes WHERE id=?"); $st->execute([$id]);
            echo json_encode(['ok'=>true, 'already'=>true, 'refreshed'=>true, 'quote'=>quote_out($st->fetch(PDO::FETCH_ASSOC)), 'warnings'=>$warnings]); exit;
        }

        $pdo->prepare("INSERT INTO quotes
              (created_by, created_email, zoho_customer_id, customer_name, reference, subject, quote_date, expiry_date,
               currency, line_items, notes, terms, sub_total, tax_amount, discount_value, discount_type, discount_amount,
               total_cost, profit, total, status, zoho_estimate_id, zoho_estimate_number)
              VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([
               $me, $_SESSION['email'] ?? '', $custId, $custNm, $ref, $subj, $qdate, $edate,
               $cur, $itemsJson, $notes, $terms, $sub, $tax, 0, 'percent', 0, $costTotal, $profit, $total, $status, $eid, $number,
            ]);
        $id = (int)$pdo->lastInsertId();
        activity_log($pdo, $me, 'imported quote from Zoho', $number . ' · ' . $custNm . ' (' . count($items) . ' items)');

        $st = $pdo->prepare("SELECT * FROM quotes WHERE id=?"); $st->execute([$id]);
        echo json_encode(['ok'=>true, 'quote'=>quote_out($st->fetch(PDO::FETCH_ASSOC)), 'warnings'=>$warnings]); exit;
    }

    throw new Exception('Unknown action.');
} catch (Exception $e) {
    echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
}

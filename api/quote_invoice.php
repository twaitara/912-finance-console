<?php
require_once __DIR__ . '/../errors.php';
/* api/quote_invoice.php — "Bill client": turn a project/quote into a Zoho INVOICE and
   push its captured project_costs rows to Zoho as expenses attached to that invoice.
   Admins only. Idempotent: if already invoiced, returns the existing invoice number.
   POST JSON: {id}  (costs are read from the project_costs table, not the request)
   Returns {ok, invoice_number, warnings[]}. */
session_start();
require_once __DIR__ . '/../csrf.php'; csrf_guard();
header('Content-Type: application/json; charset=utf-8');
if (empty($_SESSION['auth'])) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Not signed in.']); exit; }
require __DIR__ . '/../db.php';
require __DIR__ . '/../zoho.php';
require __DIR__ . '/../project_costs.php';   // pc_* helpers (+ costcap_config)
@set_time_limit(120);

/* VAT tax-id helper: zoho_vat_tax_id() in zoho.php (required above). */

/* Decide what to do with the invoices Zoho returned for our dedupe reference, before
   creating a new one (fix #13). Pure/testable. Keeps only invoices for the same
   customer, exact reference, and not already claimed by another local quote.
   Returns ['action'=>'create'|'adopt'|'block', 'invoice'=>?] */
function bill_reconcile_decision(array $invoices, $customerId, $ref, array $claimedIds) {
    $matches = [];
    foreach ($invoices as $inv) {
        if ((string)($inv['customer_id'] ?? '') !== (string)$customerId) continue;
        if ((string)($inv['reference_number'] ?? '') !== (string)$ref) continue;
        $iid = (string)($inv['invoice_id'] ?? '');
        if ($iid === '' || in_array($iid, $claimedIds, true)) continue;   // claimed by another quote
        $matches[$iid] = $inv;
    }
    if (count($matches) === 1) return ['action'=>'adopt', 'invoice'=>reset($matches)];
    if (count($matches) >  1) return ['action'=>'block', 'invoice'=>null];
    return ['action'=>'create', 'invoice'=>null];
}

try {
    $pdo = db();
    pc_table($pdo);
    $me    = $_SESSION['user'] ?? '';
    if (empty($_SESSION['is_admin'])) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Only an admin can bill a client / create an invoice.']); exit; }

    foreach ([
        "ADD COLUMN zoho_invoice_id VARCHAR(64) DEFAULT '' AFTER zoho_estimate_number",
        "ADD COLUMN zoho_invoice_number VARCHAR(64) DEFAULT '' AFTER zoho_invoice_id",
    ] as $alter) { try { $pdo->exec("ALTER TABLE quotes $alter"); } catch (Exception $e) {} }

    $in = json_decode(file_get_contents('php://input'), true); if (!is_array($in)) $in = $_POST;
    $id = (int)($in['id'] ?? 0); if ($id <= 0) throw new Exception('No quote.');

    $st = $pdo->prepare("SELECT * FROM quotes WHERE id=?"); $st->execute([$id]);
    $q = $st->fetch(PDO::FETCH_ASSOC);
    if (!$q) throw new Exception('Quote not found.');
    if (empty($q['zoho_estimate_id'])) throw new Exception('Push the quote to Zoho first.');
    if (!in_array($q['status'], ['project','approved','sent','accepted','invoiced'], true)) {
        throw new Exception('Only a project (or approved quote) can be billed.');
    }

    // already converted?  (fast path)
    if (!empty($q['zoho_invoice_id'])) {
        echo json_encode(['ok'=>true, 'invoice_number'=>$q['zoho_invoice_number'], 'already'=>true, 'warnings'=>[]]);
        exit;
    }

    $items = json_decode($q['line_items'] ?: '[]', true) ?: [];
    if (!$items) throw new Exception('Quote has no line items.');

    // Deterministic, unique-per-quote reference so a created invoice is always findable (fix #13)
    $dedupeRef = trim((string)($q['reference'] ?? ''));
    if ($dedupeRef === '') $dedupeRef = trim((string)($q['zoho_estimate_number'] ?? '')) ?: ('Q-' . $id);

    // Serialize billing for this quote so two clicks / retries can't both create an invoice.
    $lockName = 'bill_q_' . $id;
    $got = (int)$pdo->query("SELECT GET_LOCK(" . $pdo->quote($lockName) . ", 8)")->fetchColumn();
    if ($got !== 1) throw new Exception('Billing is busy for this job — please try again in a moment.');

    $warnings = []; $adopted = false; $d = null; $invId = ''; $invNo = '';
    try {
        // Re-read under the lock: another request may have billed while we waited.
        $st = $pdo->prepare("SELECT * FROM quotes WHERE id=?"); $st->execute([$id]);
        $q = $st->fetch(PDO::FETCH_ASSOC) ?: $q;
        if (!empty($q['zoho_invoice_id'])) {
            $pdo->query("SELECT RELEASE_LOCK(" . $pdo->quote($lockName) . ")");
            echo json_encode(['ok'=>true, 'invoice_number'=>$q['zoho_invoice_number'], 'already'=>true, 'warnings'=>[]]);
            exit;
        }

        // Reconcile: did a prior (failed-to-save) attempt already create this invoice in Zoho?
        [$sd, $sc] = zoho_api('GET', 'invoices', null, ['reference_number' => $dedupeRef]);
        $claimed = $pdo->query("SELECT zoho_invoice_id FROM quotes WHERE zoho_invoice_id<>'' AND id<>" . (int)$id)
                        ->fetchAll(PDO::FETCH_COLUMN);
        $decision = bill_reconcile_decision(($sc < 400 ? ($sd['invoices'] ?? []) : []), $q['zoho_customer_id'], $dedupeRef, array_map('strval', $claimed));

        if ($decision['action'] === 'block') {
            throw new Exception('Multiple Zoho invoices already use reference "' . $dedupeRef . '" — open Zoho Books, confirm which one belongs to this job, and link it before billing again.');
        }

        if ($decision['action'] === 'adopt') {
            $inv   = $decision['invoice'];
            $invId = (string)$inv['invoice_id'];
            $invNo = (string)($inv['invoice_number'] ?? '');
            $adopted = true;
            $warnings[] = 'Recovered an invoice that was already created in Zoho for this job (no duplicate made). Please verify its payment and expenses in Zoho Books.';
        } else {
            // Build and create a fresh invoice from the quote's line items.
            $taxId = zoho_vat_tax_id();
            $lineItems = [];
            foreach ($items as $it) {
                $li = [
                    'name'        => mb_strtoupper(trim((string)($it['name'] ?? 'Item')), 'UTF-8'),
                    'description' => (string)($it['description'] ?? ''),
                    'rate'        => (float)($it['rate'] ?? 0),
                    'quantity'    => (float)($it['qty'] ?? 1),
                ];
                if (strtolower((string)($it['tax'] ?? 'vat')) !== 'none' && $taxId !== '') $li['tax_id'] = $taxId;
                $lineItems[] = $li;
            }
            $body = ['customer_id' => (string)$q['zoho_customer_id'], 'line_items' => $lineItems, 'reference_number' => $dedupeRef];
            if (!empty($q['notes']))      $body['notes'] = $q['notes'];
            if (!empty($q['terms']))      $body['terms'] = $q['terms'];
            if ((float)($q['discount_value'] ?? 0) > 0) {
                $body['discount']               = (($q['discount_type'] ?? 'percent') === 'amount') ? (float)$q['discount_value'] : ((float)$q['discount_value'] . '%');
                $body['discount_type']          = 'entity_level';
                $body['is_discount_before_tax'] = true;
            }
            [$d, $c] = zoho_api('POST', 'invoices', $body);
            if ($c >= 400 || empty($d['invoice']['invoice_id'])) {
                throw new Exception($d['message'] ?? 'Zoho could not create the invoice (check scope: ZohoBooks.invoices.CREATE).');
            }
            $invId = (string)$d['invoice']['invoice_id'];
            $invNo = (string)($d['invoice']['invoice_number'] ?? '');
        }

        $pdo->prepare("UPDATE quotes SET zoho_invoice_id=?, zoho_invoice_number=?, status='invoiced', last_synced_at=NOW() WHERE id=?")
            ->execute([$invId, $invNo, $id]);
    } finally {
        $pdo->query("SELECT RELEASE_LOCK(" . $pdo->quote($lockName) . ")");
    }

    // Push captured project costs to Zoho as expenses attached to this invoice.
    $conf = costcap_config();
    $vatTaxId = zoho_vat_tax_id();
    $rows = pc_for_quote($pdo, $id);
    if ($rows) {
        if (($conf['account_id'] ?? '') === '') {
            $warnings[] = 'Invoice created, but captured costs were not posted to Zoho — set the cost expense account, then open Capture costs and save to push them.';
        } else {
            foreach ($rows as $r) {
                if (!empty($r['zoho_expense_id']) || (float)$r['amount'] <= 0) continue;  // already pushed / empty
                [$expId, $err] = pc_zoho_create($r, $invNo, $conf, $vatTaxId);
                if ($expId !== '') $pdo->prepare("UPDATE project_costs SET zoho_expense_id=? WHERE id=?")->execute([$expId, (int)$r['id']]);
                else $warnings[] = 'Zoho expense failed for "'.$r['line_name'].'": '.$err;
            }
        }
    }
    pc_refresh_quote($pdo, $id, $vat);

    // Apply the client's recorded payments to this NEW Zoho invoice (what they've paid so far).
    // On the adopt/recovery path we don't know what the prior attempt already applied, so we
    // skip this (the "verify in Zoho" warning covers it) to avoid a double payment (fix #13).
    pp_table($pdo);
    $paid = pp_total($pdo, $id);
    if (!$adopted && $paid > 0) {
        $invTotal = (float)($d['invoice']['total'] ?? 0);
        $applied  = ($invTotal > 0) ? min($paid, $invTotal) : $paid;
        $depositAcc = (string)($conf['paid_through_account_id'] ?? '');
        if ($depositAcc === '') {
            $warnings[] = 'Invoice created, but the recorded payments were not applied in Zoho — set a "paid through" account in the cost booking, then record/apply the payment in Zoho.';
        } else {
            $payBody = [
                'customer_id'  => (string)$q['zoho_customer_id'],
                'payment_mode' => 'cash',
                'amount'       => round($applied, 2),
                'date'         => date('Y-m-d'),
                'account_id'   => $depositAcc,
                'reference_number' => $invNo,
                'invoices'     => [['invoice_id' => $invId, 'amount_applied' => round($applied, 2)]],
            ];
            [$pd, $pc] = zoho_api('POST', 'customerpayments', $payBody);
            if ($pc >= 400) $warnings[] = 'Invoice created, but Zoho did not accept the client payment ('.number_format($applied,0).'): '.($pd['message'] ?? ('HTTP '.$pc));
        }
    }

    require_once __DIR__ . '/../activity_store.php';
    activity_log($pdo, $me, 'billed client', $invNo . ' for ' . ($q['customer_name'] ?? '') . ' (from ' . ($q['zoho_estimate_number'] ?: ('#'.$id)) . ')');
    echo json_encode(['ok'=>true, 'invoice_number'=>$invNo, 'warnings'=>$warnings]);
} catch (Exception $e) {
    echo api_fail($e);
}

<?php
/* api/quote_invoice.php — "Bill client": turn a project/quote into a Zoho INVOICE and
   push its captured project_costs rows to Zoho as expenses attached to that invoice.
   Admins only. Idempotent: if already invoiced, returns the existing invoice number.
   POST JSON: {id}  (costs are read from the project_costs table, not the request)
   Returns {ok, invoice_number, warnings[]}. */
session_start();
header('Content-Type: application/json; charset=utf-8');
if (empty($_SESSION['auth'])) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Not signed in.']); exit; }
require __DIR__ . '/../db.php';
require __DIR__ . '/../zoho.php';
require __DIR__ . '/../project_costs.php';   // pc_* helpers (+ costcap_config)
@set_time_limit(120);

function quote_inv_vat_tax_id(){
    $cache = __DIR__ . '/../data/zoho_vat.json';
    if (is_file($cache) && (time() - filemtime($cache) < 86400)) {
        $t = json_decode(file_get_contents($cache), true);
        if (isset($t['tax_id'])) return (string)$t['tax_id'];
    }
    [$data, $code] = zoho_api('GET', 'settings/taxes');
    $id = '';
    if ($code < 400) {
        foreach (($data['taxes'] ?? []) as $tax) {
            $pct = (float)($tax['tax_percentage'] ?? 0);
            $nm  = strtolower((string)($tax['tax_name'] ?? ''));
            if (abs($pct - 16.0) < 0.01 || strpos($nm, 'vat') !== false) { $id = (string)($tax['tax_id'] ?? ''); if (abs($pct-16.0)<0.01) break; }
        }
        if (!is_dir(__DIR__ . '/../data')) @mkdir(__DIR__ . '/../data', 0775, true);
        @file_put_contents($cache, json_encode(['tax_id'=>$id]));
    }
    return $id;
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

    // already converted?
    if (!empty($q['zoho_invoice_id'])) {
        echo json_encode(['ok'=>true, 'invoice_number'=>$q['zoho_invoice_number'], 'already'=>true, 'warnings'=>[]]);
        exit;
    }

    $items = json_decode($q['line_items'] ?: '[]', true) ?: [];
    if (!$items) throw new Exception('Quote has no line items.');

    // Build the invoice from the quote's line items (Zoho fromestimate not available on this org).
    $taxId = quote_inv_vat_tax_id();
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
    $body = ['customer_id' => (string)$q['zoho_customer_id'], 'line_items' => $lineItems];
    if (!empty($q['reference']))  $body['reference_number'] = $q['reference'];
    if (!empty($q['notes']))      $body['notes']            = $q['notes'];
    if (!empty($q['terms']))      $body['terms']            = $q['terms'];
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

    $pdo->prepare("UPDATE quotes SET zoho_invoice_id=?, zoho_invoice_number=?, status='invoiced', last_synced_at=NOW() WHERE id=?")
        ->execute([$invId, $invNo, $id]);

    // Push captured project costs to Zoho as expenses attached to this invoice.
    $warnings = [];
    $conf = costcap_config();
    $rows = pc_for_quote($pdo, $id);
    if ($rows) {
        if (($conf['account_id'] ?? '') === '') {
            $warnings[] = 'Invoice created, but captured costs were not posted to Zoho — set the cost expense account, then open Capture costs and save to push them.';
        } else {
            foreach ($rows as $r) {
                if (!empty($r['zoho_expense_id']) || (float)$r['amount'] <= 0) continue;  // already pushed / empty
                [$expId, $err] = pc_zoho_create($r, $invNo, $conf);
                if ($expId !== '') $pdo->prepare("UPDATE project_costs SET zoho_expense_id=? WHERE id=?")->execute([$expId, (int)$r['id']]);
                else $warnings[] = 'Zoho expense failed for "'.$r['line_name'].'": '.$err;
            }
        }
    }
    pc_refresh_quote($pdo, $id);

    require_once __DIR__ . '/../activity_store.php';
    activity_log($pdo, $me, 'billed client', $invNo . ' for ' . ($q['customer_name'] ?? '') . ' (from ' . ($q['zoho_estimate_number'] ?: ('#'.$id)) . ')');
    echo json_encode(['ok'=>true, 'invoice_number'=>$invNo, 'warnings'=>$warnings]);
} catch (Exception $e) {
    echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
}

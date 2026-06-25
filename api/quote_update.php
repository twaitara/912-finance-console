<?php
/* api/quote_update.php — update an already-pushed quote in Zoho, but only while it is
   still awaiting approval (draft / pending_approval). Approved/declined/sent quotes are
   locked. After updating, it re-submits for approval so the edited version is re-checked.
   POST JSON: {id}
   Owner (or admin) only. */
session_start();
header('Content-Type: application/json; charset=utf-8');
if (empty($_SESSION['auth'])) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Not signed in.']); exit; }
require __DIR__ . '/../db.php';
require __DIR__ . '/../zoho.php';
@set_time_limit(90);

/* Find the org's 16% VAT tax_id (cached for a day). Returns '' if none found. */
function quote_vat_tax_id_u(){
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
    $me    = $_SESSION['user'] ?? '';
    $admin = !empty($_SESSION['is_admin']);

    $in = json_decode(file_get_contents('php://input'), true); if (!is_array($in)) $in = $_POST;
    $id = (int)($in['id'] ?? 0); if ($id <= 0) throw new Exception('No quote.');

    $st = $pdo->prepare("SELECT * FROM quotes WHERE id=?"); $st->execute([$id]);
    $q = $st->fetch(PDO::FETCH_ASSOC);
    if (!$q) throw new Exception('Quote not found.');
    if (!$admin && $q['created_by'] !== $me) throw new Exception('Not your quote.');
    if (empty($q['zoho_estimate_id'])) throw new Exception('This quote is not in Zoho yet — use Push.');
    if (!in_array($q['status'], ['draft', 'pending_approval'], true)) {
        throw new Exception('This Zoho quote can no longer be edited (status: ' . $q['status'] . ').');
    }

    $items = json_decode($q['line_items'] ?: '[]', true) ?: [];
    if (!$items) throw new Exception('Quote has no line items.');

    $taxId = quote_vat_tax_id_u();
    $lineItems = [];
    foreach ($items as $it) {
        $li = [
            'name'        => mb_strtoupper(trim((string)($it['name'] ?? 'Item')), 'UTF-8'),
            'description' => (string)($it['description'] ?? ''),
            'rate'        => (float)($it['rate'] ?? 0),
            'quantity'    => (float)($it['qty'] ?? 1),
        ];
        $lineTax = strtolower((string)($it['tax'] ?? 'vat'));
        if ($lineTax !== 'none' && $taxId !== '') $li['tax_id'] = $taxId;
        $lineItems[] = $li;
    }

    $body = [
        'customer_id' => (string)$q['zoho_customer_id'],
        'line_items'  => $lineItems,
    ];
    if (!empty($q['reference']))   $body['reference_number'] = $q['reference'];
    if (!empty($q['quote_date']))  $body['date']             = $q['quote_date'];
    if (!empty($q['expiry_date'])) $body['expiry_date']      = $q['expiry_date'];
    if (!empty($q['notes']))       $body['notes']            = $q['notes'];
    if (!empty($q['terms']))       $body['terms']            = $q['terms'];
    if ((float)($q['discount_value'] ?? 0) > 0) {
        $body['discount']             = (($q['discount_type'] ?? 'percent') === 'amount')
                                        ? (float)$q['discount_value']
                                        : ((float)$q['discount_value'] . '%');
        $body['discount_type']        = 'entity_level';
        $body['is_discount_before_tax'] = true;
    }

    [$d, $c] = zoho_api('PUT', 'estimates/' . rawurlencode($q['zoho_estimate_id']), $body);
    if ($c >= 400) {
        throw new Exception($d['message'] ?? 'Zoho rejected the update. If it is already submitted, recall it in Zoho first.');
    }
    $status = (string)($d['estimate']['status'] ?? $q['status']);

    // re-submit so the edited version goes back for approval
    $note = '';
    if ($status === 'draft') {
        [$ds, $cs] = zoho_api('POST', 'estimates/' . rawurlencode($q['zoho_estimate_id']) . '/submit');
        if ($cs < 400) $status = 'pending_approval';
        else $note = 'Updated, but could not re-submit for approval (' . ($ds['message'] ?? ('HTTP ' . $cs)) . ').';
    }

    $pdo->prepare("UPDATE quotes SET status=?, zoho_estimate_number=?, last_synced_at=NOW() WHERE id=?")
        ->execute([$status, (string)($d['estimate']['estimate_number'] ?? $q['zoho_estimate_number']), $id]);

    echo json_encode(['ok'=>true, 'status'=>$status, 'estimate_number'=>$q['zoho_estimate_number'], 'note'=>$note]);
} catch (Exception $e) {
    echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
}

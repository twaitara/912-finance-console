<?php
/* api/quote_push.php — push a local quote to Zoho Books as an Estimate, then submit it
   for approval so the boss can Approve/Decline natively in Zoho.
   POST JSON: {id}
   Flow: POST /estimates  ->  POST /estimates/{id}/submit
   Owner (or admin) only. Recomputes nothing — uses the stored, server-priced line items. */
session_start();
header('Content-Type: application/json; charset=utf-8');
if (empty($_SESSION['auth'])) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Not signed in.']); exit; }
require __DIR__ . '/../db.php';
require __DIR__ . '/../zoho.php';
@set_time_limit(90);

/* Find the org's 16% VAT tax_id (cached for a day). Returns '' if none found. */
function quote_vat_tax_id(){
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
    if (!empty($q['zoho_estimate_id'])) throw new Exception('Already pushed (Zoho #' . $q['zoho_estimate_number'] . ').');
    if (empty($q['zoho_customer_id'])) throw new Exception('This quote has no linked Zoho customer.');

    $items = json_decode($q['line_items'] ?: '[]', true) ?: [];
    if (!$items) throw new Exception('Quote has no line items.');

    $taxId = quote_vat_tax_id();
    $lineItems = [];
    foreach ($items as $it) {
        $li = [
            'name'        => (string)($it['name'] ?? 'Item'),
            'description' => (string)($it['description'] ?? ''),
            'rate'        => (float)($it['rate'] ?? 0),
            'quantity'    => (float)($it['qty'] ?? 1),
        ];
        if ($taxId !== '') $li['tax_id'] = $taxId;
        $lineItems[] = $li;
    }

    $body = [
        'customer_id' => (string)$q['zoho_customer_id'],
        'line_items'  => $lineItems,
    ];
    if (!empty($q['notes'])) $body['notes'] = $q['notes'];

    [$d, $c] = zoho_api('POST', 'estimates', $body);
    if ($c >= 400 || empty($d['estimate']['estimate_id'])) {
        throw new Exception($d['message'] ?? 'Zoho rejected the estimate (check scope: ZohoBooks.estimates.CREATE).');
    }
    $est    = $d['estimate'];
    $estId  = (string)$est['estimate_id'];
    $estNo  = (string)($est['estimate_number'] ?? '');
    $status = (string)($est['status'] ?? 'draft');

    // submit for approval so it lands as "pending approval" in Zoho
    $submitNote = '';
    [$ds, $cs] = zoho_api('POST', 'estimates/' . rawurlencode($estId) . '/submit');
    if ($cs < 400) {
        $status = 'pending_approval';
    } else {
        $submitNote = 'Pushed, but could not submit for approval (' . ($ds['message'] ?? ('HTTP ' . $cs)) . '). It is a Draft in Zoho — enable estimate Approvals in Zoho Books to use the approve/decline step.';
    }

    $pdo->prepare("UPDATE quotes SET zoho_estimate_id=?, zoho_estimate_number=?, status=?, last_synced_at=NOW() WHERE id=?")
        ->execute([$estId, $estNo, $status, $id]);

    echo json_encode(['ok'=>true, 'estimate_number'=>$estNo, 'status'=>$status, 'note'=>$submitNote]);
} catch (Exception $e) {
    echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
}

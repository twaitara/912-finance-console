<?php
/* api/statement_draft.php — consolidate selected unpaid invoices into draft invoice(s) in Zoho Books.
   One draft invoice per currency (a Zoho invoice holds a single currency), all billed to the chosen contact.
   Each ticked unpaid invoice becomes one line item (original number · client · date, balance as the amount). */
session_start();
if (empty($_SESSION['auth']))     { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Not signed in']); exit; }
if (empty($_SESSION['is_admin'])) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Admin only']); exit; }
require __DIR__ . '/../zoho.php';
header('Content-Type: application/json');

$body = json_decode(file_get_contents('php://input'), true) ?: [];
$customerId = trim((string)($body['customer_id'] ?? ''));
$items      = $body['invoices'] ?? [];

if (!$customerId) { echo json_encode(['ok'=>false,'error'=>'No bill-to client selected']); exit; }
if (!is_array($items) || !count($items)) { echo json_encode(['ok'=>false,'error'=>'No invoices selected']); exit; }

// ── Group selected invoices by currency ────────────────────────────────────
$groups = [];   // CUR => [ ['number'=>, 'client'=>, 'date'=>, 'balance'=>], ... ]
foreach ($items as $iv) {
    $bal = (float)($iv['balance'] ?? 0);
    if ($bal <= 0) continue;
    $cur = strtoupper(trim((string)($iv['currency'] ?? 'KES'))) ?: 'KES';
    $groups[$cur][] = [
        'number'  => (string)($iv['number'] ?? ''),
        'client'  => (string)($iv['customer_name'] ?? ''),
        'date'    => (string)($iv['date'] ?? ''),
        'balance' => $bal,
    ];
}
if (!$groups) { echo json_encode(['ok'=>false,'error'=>'Selected invoices have no outstanding balance']); exit; }

// ── Currency-code → currency_id map (only needed for non-base currencies) ───
$curMap = [];
[$cd, $cc] = zoho_api('GET', 'settings/currencies');
if ($cc < 400) {
    foreach ($cd['currencies'] ?? [] as $c) {
        $code = strtoupper((string)($c['currency_code'] ?? ''));
        if ($code) $curMap[$code] = (string)($c['currency_id'] ?? '');
    }
}

// ── Create one draft invoice per currency ──────────────────────────────────
$drafts = []; $errors = [];
foreach ($groups as $cur => $rows) {
    $lineItems = array_map(function($r) {
        $label = trim($r['number'] . ($r['client'] ? ' · ' . $r['client'] : ''));
        $desc  = 'Outstanding balance' . ($r['date'] ? ' · invoice dated ' . $r['date'] : '');
        return [
            'name'        => mb_substr($label !== '' ? $label : 'Outstanding invoice', 0, 200),
            'description' => $desc,
            'rate'        => round($r['balance'], 2),
            'quantity'    => 1,
        ];
    }, $rows);

    $total = array_sum(array_map(fn($r) => $r['balance'], $rows));
    $nums  = implode(', ', array_filter(array_map(fn($r) => $r['number'], $rows)));

    $payload = [
        'customer_id'      => $customerId,
        'line_items'       => $lineItems,
        'reference_number' => 'Consolidated statement',
        'notes'            => 'Consolidated statement of outstanding invoices: ' . $nums,
    ];
    // Override currency only when it differs from the contact's base (needs a known currency_id)
    if (!empty($curMap[$cur])) {
        $payload['currency_id'] = $curMap[$cur];
    }

    [$d, $code] = zoho_api('POST', 'invoices', $payload);
    if ($code >= 400 || empty($d['invoice'])) {
        $msg = $d['message'] ?? ('Zoho error ' . $code);
        $errors[] = ['currency'=>$cur, 'error'=>$msg, 'count'=>count($rows)];
        continue;
    }
    $drafts[] = [
        'currency' => $cur,
        'number'   => (string)($d['invoice']['invoice_number'] ?? ''),
        'id'       => (string)($d['invoice']['invoice_id'] ?? ''),
        'count'    => count($rows),
        'total'    => round($total, 2),
    ];
}

echo json_encode([
    'ok'     => count($drafts) > 0,
    'drafts' => $drafts,
    'errors' => $errors,
    'error'  => (count($drafts) === 0 && $errors) ? ($errors[0]['error'] ?? 'Failed to create draft') : '',
]);

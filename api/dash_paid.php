<?php
/* api/dash_paid.php — payments received + expenses in last 30 days for dashboard widget */
session_start();
if (empty($_SESSION['auth'])) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Not signed in']); exit; }
require __DIR__ . '/../zoho.php';
header('Content-Type: application/json');

$from  = date('Y-m-01');   // first day of current month
$to    = date('Y-m-d');    // today
$days  = (int)date('j');   // days elapsed in month (for the label)

// ── Customer payments (Net cash received) ──────────────────────────────────
$payments = [];
$page = 1;
do {
    [$pd, $pc] = zoho_api('GET', 'customerpayments', null, [
        'date_start' => $from, 'date_end' => $to, 'per_page' => 200, 'page' => $page,
    ]);
    if ($pc >= 400) break;
    foreach ($pd['customerpayments'] ?? [] as $p) $payments[] = $p;
    $more = $pd['page_context']['has_more_page'] ?? false;
    $page++;
} while ($more && $page <= 5);

$net   = array_sum(array_map(fn($p) => (float)($p['amount'] ?? 0), $payments));
$count = count($payments);

// Gross: sum of invoice_amount per payment invoice line (Zoho includes this in list)
$gross = 0;
foreach ($payments as $p) {
    foreach ($p['invoices'] ?? [] as $iv) {
        $gross += (float)($iv['invoice_amount'] ?? $iv['amount_applied'] ?? 0);
    }
}
if ($gross < $net) $gross = $net; // invoice_amount not always in list — fallback

// ── Expenses ───────────────────────────────────────────────────────────────
$expenses = 0;
$page = 1;
do {
    [$ed, $ec] = zoho_api('GET', 'expenses', null, [
        'date_start' => $from, 'date_end' => $to, 'per_page' => 200, 'page' => $page,
    ]);
    if ($ec >= 400) break;
    foreach ($ed['expenses'] ?? [] as $e) {
        $expenses += (float)($e['total'] ?? $e['amount'] ?? 0);
    }
    $more = $ed['page_context']['has_more_page'] ?? false;
    $page++;
} while ($more && $page <= 3);

// ── All payment rows, newest first ────────────────────────────────────────
usort($payments, fn($a, $b) => strcmp($b['date'] ?? '', $a['date'] ?? ''));
$rows = array_map(fn($p) => [
    'customer' => (string)($p['customer_name']    ?? ''),
    'amount'   => (float)($p['amount']            ?? 0),
    'date'     => (string)($p['date']             ?? ''),
    'number'   => (string)($p['payment_number']   ?? ''),
    'mode'     => (string)($p['payment_mode']     ?? ''),
    'ref'      => (string)($p['reference_number'] ?? ''),
    'desc'     => mb_strimwidth((string)($p['description'] ?? $p['notes'] ?? ''), 0, 60, '…'),
    'invoices' => array_map(fn($iv) => (string)($iv['invoice_number'] ?? ''),
                    array_filter($p['invoices'] ?? [], fn($iv) => !empty($iv['invoice_number']))),
], $payments);

echo json_encode([
    'ok'       => true,
    'from'     => $from,
    'to'       => $to,
    'days'     => $days,
    'count'    => $count,
    'gross'    => round($gross, 2),
    'net'      => round($net,   2),
    'expenses' => round($expenses, 2),
    'profit'   => round($net - $expenses, 2),
    'rows'     => $rows,
]);

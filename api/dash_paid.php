<?php
/* api/dash_paid.php — payments received + expenses in last 30 days for dashboard widget */
session_start();
if (empty($_SESSION['auth'])) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Not signed in']); exit; }
require __DIR__ . '/../zoho.php';
require __DIR__ . '/../fx.php';
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

// ── Outstanding: all unpaid invoice balances, by currency ──────────────────
$cacheDir = __DIR__ . '/../data';
if (!is_dir($cacheDir)) @mkdir($cacheDir, 0775, true);
$cfg = function_exists('zoho_config') ? zoho_config() : [];
$fx  = usd_kes_rate($cacheDir, $cfg);   // ['rate'=>float, 'src'=>string, 'asOf'=>iso]

$dueKES = 0.0; $dueUSD = 0.0; $unpaidCount = 0;
$page = 1;
do {
    [$ud, $uc] = zoho_api('GET', 'invoices', null, [
        'filter_by' => 'Status.Unpaid', 'per_page' => 200, 'page' => $page,
    ]);
    if ($uc >= 400) break;
    foreach ($ud['invoices'] ?? [] as $iv) {
        if (($iv['status'] ?? '') === 'void') continue;
        $bal = (float)($iv['balance'] ?? 0);
        if ($bal <= 0) continue;
        $unpaidCount++;
        $cur = strtoupper((string)($iv['currency_code'] ?? 'KES'));
        if ($cur === 'USD') $dueUSD += $bal;
        else                $dueKES += $bal;
    }
    $more = $ud['page_context']['has_more_page'] ?? false;
    $page++;
} while ($more && $page <= 15);

$rate = (float)($fx['rate'] ?? 0);
$dueTotalKES = $dueKES + ($rate > 0 ? $dueUSD * $rate : 0);
$dueTotalUSD = $dueUSD + ($rate > 0 ? $dueKES / $rate : 0);

// ── All payment rows, newest first ────────────────────────────────────────
usort($payments, fn($a, $b) => strcmp($b['date'] ?? '', $a['date'] ?? ''));
$rows = array_map(function($p) {
    // Collect invoice numbers: from invoices[] sub-array first, then fallback to top-level invoice_number
    $invNums = array_values(array_unique(array_filter(
        array_map(fn($iv) => (string)($iv['invoice_number'] ?? ''),
            $p['invoices'] ?? [])
    )));
    if (!$invNums && !empty($p['invoice_number'])) {
        $invNums = [(string)$p['invoice_number']];
    }
    return [
        'customer' => (string)($p['customer_name']    ?? ''),
        'amount'   => (float)($p['amount']            ?? 0),
        'date'     => (string)($p['date']             ?? ''),
        'number'   => (string)($p['payment_number']   ?? ''),
        'mode'     => (string)($p['payment_mode']     ?? ''),
        'ref'      => (string)($p['reference_number'] ?? ''),
        'invoices' => $invNums,
    ];
}, $payments);

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
    'unpaid'   => [
        'count'    => $unpaidCount,
        'kes'      => round($dueKES, 2),       // KES-invoice balances
        'usd'      => round($dueUSD, 2),       // USD-invoice balances
        'totalKES' => round($dueTotalKES, 2),  // everything expressed in KES
        'totalUSD' => round($dueTotalUSD, 2),  // everything expressed in USD
        'rate'     => $rate,
        'rateSrc'  => $fx['src'] ?? '',
    ],
]);

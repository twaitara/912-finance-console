<?php
/* api/late_payers.php — rank customers by how consistently they pay late over the last 52 weeks.
   Lateness is judged per invoice raised in the window:
     • paid invoice  → late if the (last) payment date is after the due date; daysLate = paid − due
     • unpaid + past due → currently overdue; daysLate = today − due
     • not yet due / draft / void → ignored (lateness not determinable)
   A customer must have ≥2 determinable invoices and ≥2 late ones to count as a "consistent" late payer. */
session_start();
require_once __DIR__ . '/../csrf.php'; csrf_guard();
if (empty($_SESSION['auth'])) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Not signed in']); exit; }
require __DIR__ . '/../zoho.php';
require __DIR__ . '/../fx.php';
header('Content-Type: application/json');
@set_time_limit(120);

$to    = date('Y-m-d');
$from  = date('Y-m-d', strtotime('-52 weeks'));
$today = $to;

// ── Build invoice_id → latest payment date map (reliable settle date) ───────
$payMap = [];
$page = 1;
do {
    [$pd, $pc] = zoho_api('GET', 'customerpayments', null, [
        'date_start' => $from, 'date_end' => $to, 'per_page' => 200, 'page' => $page,
    ]);
    if ($pc >= 400) break;
    foreach ($pd['customerpayments'] ?? [] as $p) {
        $pdate = substr((string)($p['date'] ?? ''), 0, 10);
        foreach ($p['invoices'] ?? [] as $pi) {
            $iid = (string)($pi['invoice_id'] ?? '');
            if ($iid === '' || $pdate === '') continue;
            if (!isset($payMap[$iid]) || strcmp($pdate, $payMap[$iid]) > 0) $payMap[$iid] = $pdate;
        }
    }
    $more = $pd['page_context']['has_more_page'] ?? false;
    $page++;
} while ($more && $page <= 20);

// ── FX for converting overdue balances to KES ──────────────────────────────
$cacheDir = __DIR__ . '/../data';
if (!is_dir($cacheDir)) @mkdir($cacheDir, 0775, true);
$cfg  = function_exists('zoho_config') ? zoho_config() : [];
$fx   = usd_kes_rate($cacheDir, $cfg);
$rate = (float)($fx['rate'] ?? 0);

// ── Walk invoices raised in the window, aggregate per customer ─────────────
$agg = [];
$page = 1;
do {
    [$d, $c] = zoho_api('GET', 'invoices', null, [
        'date_start' => $from, 'date_end' => $to, 'per_page' => 200, 'page' => $page, 'sort_column' => 'date',
    ]);
    if ($c >= 400) break;
    foreach ($d['invoices'] ?? [] as $iv) {
        $status = $iv['status'] ?? '';
        if ($status === 'void' || $status === 'draft') continue;
        $due = substr((string)($iv['due_date'] ?? ''), 0, 10);
        if (!$due) continue;

        $iid   = (string)($iv['invoice_id'] ?? '');
        $cid   = (string)($iv['customer_id'] ?? '');
        $cname = (string)($iv['customer_name'] ?? '');
        $cur   = strtoupper((string)($iv['currency_code'] ?? 'KES'));
        $bal   = (float)($iv['balance'] ?? 0);
        $balKES = ($cur === 'USD' && $rate > 0) ? $bal * $rate : $bal;

        $paidDate = $payMap[$iid] ?? substr((string)($iv['last_payment_date'] ?? ''), 0, 10);
        $isPaid   = ($status === 'paid');

        $determinable = false; $late = false; $daysLate = 0; $overdueOpen = false;
        if ($isPaid && $paidDate) {
            $determinable = true;
            $dl = (int)floor((strtotime($paidDate) - strtotime($due)) / 86400);
            if ($dl > 0) { $late = true; $daysLate = $dl; }
        } elseif ($bal > 0 && strcmp($due, $today) < 0) {
            $determinable = true; $late = true; $overdueOpen = true;
            $daysLate = (int)floor((strtotime($today) - strtotime($due)) / 86400);
        } elseif ($isPaid && !$paidDate) {
            $determinable = true;          // paid, settle date unknown → treat as on-time (can't prove late)
        }
        if (!$determinable) continue;

        if (!isset($agg[$cid])) $agg[$cid] = ['id'=>$cid,'name'=>$cname,'considered'=>0,'late'=>0,'sumDays'=>0,'maxDays'=>0,'overdueOpen'=>0,'overdueKES'=>0.0];
        $a =& $agg[$cid];
        if ($cname) $a['name'] = $cname;
        $a['considered']++;
        if ($late) { $a['late']++; $a['sumDays'] += $daysLate; if ($daysLate > $a['maxDays']) $a['maxDays'] = $daysLate; }
        if ($overdueOpen) { $a['overdueOpen']++; $a['overdueKES'] += $balKES; }
        unset($a);
    }
    $more = $d['page_context']['has_more_page'] ?? false;
    $page++;
} while ($more && $page <= 25);

// ── Rank: a "consistent" late payer pays late often (frequency × proportion) and by a lot (avg days) ──
$rows = [];
foreach ($agg as $a) {
    if ($a['considered'] < 2 || $a['late'] < 2) continue;
    $latePct = $a['considered'] ? $a['late'] / $a['considered'] : 0;
    $avgDays = $a['late'] ? $a['sumDays'] / $a['late'] : 0;
    $score   = $a['late'] * $avgDays * (0.5 + $latePct);
    $rows[] = [
        'customer_id'     => $a['id'],
        'customer_name'   => $a['name'] ?: '(no name)',
        'considered'      => $a['considered'],
        'lateCount'       => $a['late'],
        'onTime'          => $a['considered'] - $a['late'],
        'latePct'         => (int)round($latePct * 100),
        'avgDaysLate'     => round($avgDays, 1),
        'maxDaysLate'     => $a['maxDays'],
        'overdueOpen'     => $a['overdueOpen'],
        'overdueValueKES' => round($a['overdueKES'], 2),
        'score'           => round($score, 1),
    ];
}
usort($rows, fn($x, $y) => $y['score'] <=> $x['score']);

echo json_encode([
    'ok'          => true,
    'weeks'       => 52,
    'from'        => $from,
    'to'          => $to,
    'rate'        => $rate,
    'top'         => array_slice($rows, 0, 3),
    'all'         => $rows,
    'count'       => count($rows),
    'generatedAt' => date('c'),
]);

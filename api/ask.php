<?php
/* api/ask.php — "Ask your books": natural-language finance analysis over the Zoho data.
   Admin only. Builds a compact books summary (cached 15 min) and asks Claude to answer. */
session_start();
header('Content-Type: application/json; charset=utf-8');
if (empty($_SESSION['auth']) || empty($_SESSION['is_admin'])) {
    http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Admins only.']); exit;
}
require __DIR__ . '/../zoho.php';
require __DIR__ . '/../fx.php';
@set_time_limit(120);

$cfg = function_exists('zoho_config') ? zoho_config() : [];
$KEY = trim((string)($cfg['anthropic_api_key'] ?? getenv('ANTHROPIC_API_KEY') ?: ''));
if ($KEY === '') {
    echo json_encode(['ok'=>false,'error'=>'AI isn’t set up yet. Add your Anthropic API key as $config[\'anthropic_api_key\'] in config.php on the server.']);
    exit;
}

$in       = json_decode(file_get_contents('php://input'), true) ?: [];
$question = trim((string)($in['question'] ?? ''));
$history  = is_array($in['history'] ?? null) ? $in['history'] : [];
if ($question === '') { echo json_encode(['ok'=>false,'error'=>'Ask a question about your books.']); exit; }

/* ---------- Build (and cache) the books context ---------- */
$dir = __DIR__ . '/../data'; if (!is_dir($dir)) @mkdir($dir, 0775, true);
$ctxFile = $dir . '/ask_context.json';
$context = null;
if (empty($in['refresh']) && is_file($ctxFile) && (time() - filemtime($ctxFile) < 900)) {
    $j = json_decode(@file_get_contents($ctxFile), true);
    if (is_array($j) && !empty($j['text'])) $context = $j['text'];
}

if ($context === null) {
    $to   = date('Y-m-d');
    $from = date('Y-m-d', strtotime('-120 days'));
    $fx   = usd_kes_rate($dir, $cfg);
    $rate = (float)($fx['rate'] ?? 0);
    $toK  = function($amt, $cur) use ($rate) { return (strtoupper((string)$cur) === 'USD' && $rate > 0) ? (float)$amt * $rate : (float)$amt; };

    // Invoices in the window
    $rev = []; $invRows = []; $page = 1;
    do {
        [$d, $c] = zoho_api('GET', 'invoices', null, ['created_date_start'=>$from, 'created_date_end'=>$to, 'per_page'=>200, 'page'=>$page, 'sort_column'=>'date']);
        if ($c >= 400) break;
        foreach ($d['invoices'] ?? [] as $iv) {
            if (($iv['status'] ?? '') === 'void' || ($iv['status'] ?? '') === 'draft') continue;
            $cur = strtoupper((string)($iv['currency_code'] ?? 'KES'));
            $tK  = $toK((float)($iv['total'] ?? 0), $cur);
            $date= substr((string)($iv['date'] ?? ''), 0, 10);
            $m   = substr($date, 0, 7);
            $rev[$m] = ($rev[$m] ?? 0) + $tK;
            $num = (string)($iv['invoice_number'] ?? '');
            $invRows[$num] = ['num'=>$num, 'client'=>(string)($iv['customer_name'] ?? ''), 'total'=>$tK, 'date'=>$date, 'status'=>(string)($iv['status'] ?? '')];
        }
        $more = $d['page_context']['has_more_page'] ?? false; $page++;
    } while ($more && $page <= 10);

    // Expenses in the window — by month, by account, and matched to invoices
    $exp = []; $expByAcct = []; $costByInv = []; $page = 1;
    do {
        [$ed, $ec] = zoho_api('GET', 'expenses', null, ['date_start'=>$from, 'date_end'=>$to, 'per_page'=>200, 'page'=>$page, 'sort_column'=>'date']);
        if ($ec >= 400) break;
        foreach ($ed['expenses'] ?? [] as $e) {
            $amt = (float)($e['total_without_tax'] ?? $e['total'] ?? 0);
            $m   = substr((string)($e['date'] ?? ''), 0, 7);
            $exp[$m] = ($exp[$m] ?? 0) + $amt;
            $acct = (string)($e['account_name'] ?? 'Uncategorised');
            $expByAcct[$acct] = ($expByAcct[$acct] ?? 0) + $amt;
            $ref = trim((string)($e['reference_number'] ?? ''));
            if ($ref !== '') $costByInv[$ref] = ($costByInv[$ref] ?? 0) + $amt;
        }
        $more = $ed['page_context']['has_more_page'] ?? false; $page++;
    } while ($more && $page <= 10);

    // Payments received in the window
    $payTotal = 0; $payCount = 0; $page = 1;
    do {
        [$pd, $pc] = zoho_api('GET', 'customerpayments', null, ['date_start'=>$from, 'date_end'=>$to, 'per_page'=>200, 'page'=>$page]);
        if ($pc >= 400) break;
        foreach ($pd['customerpayments'] ?? [] as $p) { $payTotal += (float)($p['amount'] ?? 0); $payCount++; }
        $more = $pd['page_context']['has_more_page'] ?? false; $page++;
    } while ($more && $page <= 8);

    // Outstanding (unpaid) totals
    $dueK = 0; $dueU = 0; $dueN = 0; $page = 1;
    do {
        [$ud, $uc] = zoho_api('GET', 'invoices', null, ['filter_by'=>'Status.Unpaid', 'per_page'=>200, 'page'=>$page]);
        if ($uc >= 400) break;
        foreach ($ud['invoices'] ?? [] as $iv) {
            if (($iv['status'] ?? '') === 'void') continue;
            $b = (float)($iv['balance'] ?? 0); if ($b <= 0) continue; $dueN++;
            if (strtoupper((string)($iv['currency_code'] ?? 'KES')) === 'USD') $dueU += $b; else $dueK += $b;
        }
        $more = $ud['page_context']['has_more_page'] ?? false; $page++;
    } while ($more && $page <= 12);

    // Compose the text context
    ksort($rev); ksort($exp);
    $months = array_values(array_unique(array_merge(array_keys($rev), array_keys($exp)))); sort($months);
    $kfmt = fn($n) => number_format(round($n), 0);
    $lines = [];
    $lines[] = "PERIOD: $from to $to. Currency KES. USD invoices converted at 1 USD = KES " . round($rate, 2) . ".";
    $lines[] = "PROFIT RULE (house rule): profit = invoice total − cost booked against it. VAT is NOT netted out.";
    $lines[] = "";
    $lines[] = "MONTHLY P&L (KES):";
    foreach ($months as $m) {
        $r = $rev[$m] ?? 0; $x = $exp[$m] ?? 0; $p = $r - $x;
        $lines[] = "  $m  revenue " . $kfmt($r) . "  costs+expenses " . $kfmt($x) . "  profit " . $kfmt($p);
    }
    // Per-invoice profit → top losses
    $perInv = [];
    foreach ($invRows as $num => $iv) {
        $cost = $costByInv[$num] ?? 0;
        $perInv[] = ['num'=>$num, 'client'=>$iv['client'], 'rev'=>$iv['total'], 'cost'=>$cost, 'profit'=>$iv['total'] - $cost, 'date'=>$iv['date']];
    }
    usort($perInv, fn($a, $b) => $a['profit'] <=> $b['profit']);
    $lines[] = "";
    $lines[] = "LOWEST-PROFIT INVOICES IN PERIOD (revenue − cost, KES):";
    foreach (array_slice($perInv, 0, 8) as $r) {
        $lines[] = "  {$r['num']}  {$r['client']}  {$r['date']}  rev " . $kfmt($r['rev']) . "  cost " . $kfmt($r['cost']) . "  profit " . $kfmt($r['profit']);
    }
    arsort($expByAcct);
    $lines[] = "";
    $lines[] = "TOP EXPENSE CATEGORIES IN PERIOD (KES):";
    foreach (array_slice($expByAcct, 0, 10, true) as $acct => $amt) $lines[] = "  " . $acct . "  " . $kfmt($amt);
    $lines[] = "";
    $lines[] = "PAYMENTS RECEIVED IN PERIOD: KES " . $kfmt($payTotal) . " across $payCount payments.";
    $lines[] = "OUTSTANDING (unpaid invoices, all-time): $dueN invoices, KES " . $kfmt($dueK) . " + USD " . number_format(round($dueU)) . ".";

    $context = implode("\n", $lines);
    @file_put_contents($ctxFile, json_encode(['text'=>$context, 'builtAt'=>date('c')]));
}

/* ---------- Ask Claude ---------- */
$system = "You are the finance analyst for the Waitara Holdings Group of Companies (912). "
    . "Answer the user's question in clear, plain English, grounded ONLY in the books data below. "
    . "Be specific and quantify with KES figures; cite the invoices, costs, or expense categories responsible. "
    . "When asked why profits fell, compare months and name the biggest drivers (rising costs, low-margin invoices, unpaid receivables). "
    . "Be concise and practical — a few short paragraphs or a tight list. If the data can't answer it, say so plainly. "
    . "Today is " . date('Y-m-d') . ".\n\n=== BOOKS DATA ===\n" . $context;

$messages = [];
foreach (array_slice($history, -8) as $h) {
    $role = ($h['role'] ?? '') === 'assistant' ? 'assistant' : 'user';
    $txt  = trim((string)($h['content'] ?? ''));
    if ($txt !== '') $messages[] = ['role'=>$role, 'content'=>$txt];
}
$messages[] = ['role'=>'user', 'content'=>$question];

$payload = [
    'model'      => 'claude-opus-4-8',
    'max_tokens' => 2000,
    'system'     => $system,
    'messages'   => $messages,
];

$ch = curl_init('https://api.anthropic.com/v1/messages');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 90,
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => [
        'x-api-key: ' . $KEY,
        'anthropic-version: 2023-06-01',
        'content-type: application/json',
    ],
    CURLOPT_POSTFIELDS     => json_encode($payload),
]);
$res  = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$cerr = curl_error($ch);
curl_close($ch);

if ($res === false) { echo json_encode(['ok'=>false,'error'=>'Could not reach the AI service: ' . $cerr]); exit; }
$d = json_decode($res, true);
if ($code >= 400) {
    $msg = $d['error']['message'] ?? ('AI error ' . $code);
    echo json_encode(['ok'=>false,'error'=>$msg]); exit;
}
$answer = '';
foreach ($d['content'] ?? [] as $blk) { if (($blk['type'] ?? '') === 'text') $answer .= $blk['text']; }
if ($answer === '') { echo json_encode(['ok'=>false,'error'=>'The AI returned an empty answer — try rephrasing.']); exit; }

echo json_encode(['ok'=>true, 'answer'=>$answer]);

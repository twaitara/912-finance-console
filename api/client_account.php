<?php
require_once __DIR__ . '/../errors.php';
/* api/email_statement.php?customer_id=XXX[&from=YYYY-MM-DD&to=YYYY-MM-DD]
   Builds a statement for a client over a period.
   Smart default period (when from/to omitted):
     from = invoice date of the OLDER of the client's last two PAID invoices
     to   = invoice date of the client's LATEST UNPAID invoice (else today) */
session_start();
require_once __DIR__ . '/../csrf.php'; csrf_guard();
header('Content-Type: application/json; charset=utf-8');
if (empty($_SESSION['auth'])) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Not signed in.']); exit; }

require __DIR__ . '/../zoho.php';
@set_time_limit(120);

try {
    $c = zoho_config();
    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) $body = $_POST;
    $cidRaw  = $body['customer_id'] ?? ($_GET['customer_id'] ?? '');
    $cid     = preg_replace('/[^0-9]/', '', $cidRaw);
    if ($cid === '') { echo json_encode(['ok'=>false,'error'=>'No client selected.']); exit; }
    $reqFrom = preg_replace('/[^0-9\-]/', '', $body['from'] ?? ($_GET['from'] ?? ''));
    $reqTo   = preg_replace('/[^0-9\-]/', '', $body['to'] ?? ($_GET['to'] ?? ''));

    [$cd, $cc] = zoho_api('GET', 'contacts/' . $cid);
    $client = [
        'name'  => $cd['contact']['contact_name'] ?? '',
        'email' => $cd['contact']['email'] ?? '',
    ];

    $all = []; $page = 1;
    do {
        [$d, $code] = zoho_api('GET', 'invoices', null, [
            'customer_id' => $cid,
            'per_page'    => 200,
            'page'        => $page,
            'sort_column' => 'date',
        ]);
        if ($code >= 400) throw new Exception($d['message'] ?? 'Zoho error (invoices)');
        foreach (($d['invoices'] ?? []) as $inv) {
            if (($inv['status'] ?? '') === 'void') continue;
            $cur     = $inv['currency_code'] ?? $c['currency'];
            $rate    = (float)($inv['exchange_rate'] ?? 1); if ($rate <= 0) $rate = 1;
            $foreign = strtoupper($cur) !== strtoupper($c['currency']);   // base is KES
            $oTotal  = (float)($inv['total'] ?? 0);
            $oBal    = (float)($inv['balance'] ?? 0);
            $all[] = [
                'id'         => $inv['invoice_id'] ?? '',
                'number'     => $inv['invoice_number'] ?? '',
                'date'       => substr($inv['date'] ?? '', 0, 10),
                'dueDate'    => substr($inv['due_date'] ?? '', 0, 10),
                'status'     => $inv['status'] ?? '',
                'currency'   => $cur,
                'rate'       => $rate,
                'foreign'    => $foreign,
                'origTotal'  => $oTotal,
                'origBalance'=> $oBal,
                'total'      => $foreign ? $oTotal * $rate : $oTotal,   // KES
                'balance'    => $foreign ? $oBal   * $rate : $oBal,     // KES
            ];
        }
        $more = $d['page_context']['has_more_page'] ?? false;
        $page++;
    } while ($more && $page <= 25);

    // smart default period
    $paid = array_values(array_filter($all, fn($i) => $i['status'] === 'paid' && $i['date']));
    usort($paid, fn($a,$b) => strcmp($b['date'], $a['date']));          // newest first
    $lastTwoPaid = array_slice($paid, 0, 2);
    $smartFrom = '';
    if (count($lastTwoPaid)) $smartFrom = end($lastTwoPaid)['date'];    // older of the two
    if ($smartFrom === '') {
        $allDates = array_filter(array_map(fn($i)=>$i['date'], $all));
        $smartFrom = $allDates ? min($allDates) : date('Y-m-d', strtotime('-1 year'));
    }
    $unpaidDates = array_filter(array_map(fn($i)=>$i['date'], array_filter($all, fn($i)=>$i['balance'] > 0)));
    $smartTo = $unpaidDates ? max($unpaidDates) : date('Y-m-d');

    $from = $reqFrom ?: $smartFrom;
    $to   = $reqTo   ?: $smartTo;
    if ($from > $to) { $tmp = $from; $from = $to; $to = $tmp; }
    // cap the period at a maximum of 1 year (keep the END date, pull START forward)
    $minFrom = date('Y-m-d', strtotime($to . ' -1 year +1 day'));
    if ($from < $minFrom) $from = $minFrom;

    $inRange = array_values(array_filter($all, fn($i) => $i['date'] >= $from && $i['date'] <= $to));
    usort($inRange, fn($a,$b) => strcmp($a['date'], $b['date']) ?: strcmp($a['number'], $b['number']));

    $today = date('Y-m-d');
    $billed = 0; $totalDue = 0; $overdueDue = 0;

    // first line-item description per invoice (cached, since the list endpoint omits line items)
    $descCacheFile = __DIR__ . '/../data/inv_desc_cache.json';
    $descCache = is_file($descCacheFile) ? (json_decode(@file_get_contents($descCacheFile), true) ?: []) : [];
    $descDirty = false;

    foreach ($inRange as &$iv) {
        $billed += $iv['total'];
        $totalDue += $iv['balance'];
        if ($iv['balance'] > 0 && $iv['dueDate'] && $iv['dueDate'] < $today) $overdueDue += $iv['balance'];
        $iv['unpaid']   = $iv['balance'] > 0;

        $iid = $iv['id'] ?? '';
        $desc = '';
        if ($iid !== '') {
            if (array_key_exists($iid, $descCache)) {
                $desc = $descCache[$iid];
            } else {
                try {
                    [$dv, $dc] = zoho_api('GET', 'invoices/' . rawurlencode($iid), null, []);
                    if ($dc < 400) {
                        $li = $dv['invoice']['line_items'][0] ?? null;
                        $desc = trim($li['description'] ?? ($li['name'] ?? ''));
                    }
                } catch (Exception $e) { $desc = ''; }
                $descCache[$iid] = $desc; $descDirty = true;
            }
        }
        // keep it short for the statement
        if (mb_strlen($desc) > 90) $desc = mb_substr($desc, 0, 88) . '…';
        $iv['desc'] = $desc;
    }
    unset($iv);
    if ($descDirty) { if (!is_dir(__DIR__ . '/../data')) @mkdir(__DIR__ . '/../data', 0775, true); @file_put_contents($descCacheFile, json_encode($descCache)); }

    echo json_encode([
        'ok'        => true,
        'client'    => $client,
        'invoices'  => $inRange,
        'count'     => count($inRange),
        'billed'    => $billed,
        'totalDue'  => $totalDue,
        'overdueDue'=> $overdueDue,
        'currency'  => $c['currency'],
        'period'    => ['from'=>$from, 'to'=>$to, 'smartFrom'=>$smartFrom, 'smartTo'=>$smartTo],
        'asOf'      => date('c'),
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo api_fail($e);
}

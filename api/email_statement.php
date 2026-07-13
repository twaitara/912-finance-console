<?php
require_once __DIR__ . '/../errors.php';
/* api/email_statement.php?customer_id=XXX[&from=YYYY-MM-DD&to=YYYY-MM-DD]
   Builds a statement for a client over a period.
   Smart default period (when from/to omitted):
     from = invoice date of the OLDER of the client's last two PAID invoices
     to   = invoice date of the client's LATEST UNPAID invoice (else today)
   Invoices in range are matched to a WorkDrive PDF where possible. */
session_start();
header('Content-Type: application/json; charset=utf-8');
if (empty($_SESSION['auth'])) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Not signed in.']); exit; }

require __DIR__ . '/../zoho.php';
require __DIR__ . '/../workdrive.php';
@set_time_limit(120);

try {
    $c = zoho_config();
    $cid = preg_replace('/[^0-9]/', '', $_GET['customer_id'] ?? '');
    if ($cid === '') throw new Exception('No client selected.');
    $reqFrom = preg_replace('/[^0-9\-]/', '', $_GET['from'] ?? '');
    $reqTo   = preg_replace('/[^0-9\-]/', '', $_GET['to'] ?? '');

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
            $all[] = [
                'number'   => $inv['invoice_number'] ?? '',
                'date'     => substr($inv['date'] ?? '', 0, 10),
                'dueDate'  => substr($inv['due_date'] ?? '', 0, 10),
                'status'   => $inv['status'] ?? '',
                'total'    => (float)($inv['total'] ?? 0),
                'balance'  => (float)($inv['balance'] ?? 0),
                'currency' => $inv['currency_code'] ?? $c['currency'],
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

    $inRange = array_values(array_filter($all, fn($i) => $i['date'] >= $from && $i['date'] <= $to));
    usort($inRange, fn($a,$b) => strcmp($a['date'], $b['date']) ?: strcmp($a['number'], $b['number']));

    $fileMap = [];
    try { $fileMap = wd_filename_file_map(wd_default_folders()); } catch (Exception $e) { $fileMap = []; }

    $today = date('Y-m-d');
    $billed = 0; $totalDue = 0; $overdueDue = 0;
    foreach ($inRange as &$iv) {
        $billed += $iv['total'];
        $totalDue += $iv['balance'];
        if ($iv['balance'] > 0 && $iv['dueDate'] && $iv['dueDate'] < $today) $overdueDue += $iv['balance'];
        $core = ltrim(preg_replace('/\D/', '', $iv['number']), '0');
        $f = $fileMap[$core] ?? null;
        $iv['hasFile']  = $f ? true : false;
        $iv['fileName'] = $f['name'] ?? '';
        $iv['fileLink'] = $f['link'] ?? '';
        $iv['unpaid']   = $iv['balance'] > 0;
    }
    unset($iv);

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

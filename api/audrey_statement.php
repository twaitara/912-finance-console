<?php
/* api/audrey_statement.php  (PUBLIC) — POST { client | customer_id, from?, to? }
   Full statement for ONE Dunhill client over a period. Locked to the Dunhill
   list so a public page can never pull a non-Dunhill client.
   Smart default period: from = older of last two PAID invoices; to = latest UNPAID (else today). */
header('Content-Type: application/json; charset=utf-8');
require __DIR__ . '/../zoho.php';
require __DIR__ . '/audrey_clients.php';
@set_time_limit(120);

try {
    $c = zoho_config();
    $in = json_decode(file_get_contents('php://input'), true);
    if (!is_array($in)) $in = $_POST;
    $name    = trim($in['client'] ?? '');
    $reqFrom = preg_replace('/[^0-9\-]/', '', $in['from'] ?? '');
    $reqTo   = preg_replace('/[^0-9\-]/', '', $in['to'] ?? '');

    $set = audrey_client_set();
    if ($name === '' || !isset($set[audrey_norm($name)])) {
        echo json_encode(['ok'=>false, 'error'=>'Client not in the Dunhill list.']); exit;
    }
    $name = $set[audrey_norm($name)]; // canonical name

    // all invoices for this client (any status) by customer_name
    $all = []; $page = 1;
    do {
        [$d, $code] = zoho_api('GET', 'invoices', null, [
            'customer_name' => $name,
            'per_page'      => 200,
            'page'          => $page,
            'sort_column'   => 'date',
        ]);
        if ($code >= 400) throw new Exception($d['message'] ?? 'Zoho error');
        foreach (($d['invoices'] ?? []) as $inv) {
            if (($inv['status'] ?? '') === 'void') continue;
            if (audrey_norm($inv['customer_name'] ?? '') !== audrey_norm($name)) continue; // exact client only
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
    usort($paid, fn($a,$b) => strcmp($b['date'], $a['date']));
    $lastTwoPaid = array_slice($paid, 0, 2);
    $allDates = array_filter(array_map(fn($i)=>$i['date'], $all));

    $smartFrom = '';
    if (count($lastTwoPaid)) $smartFrom = end($lastTwoPaid)['date'];   // older of the last two paid
    if ($smartFrom === '') $smartFrom = $allDates ? min($allDates) : date('Y-m-d', strtotime('-1 year'));

    // End at the latest UNPAID invoice; if none are unpaid, end at the latest
    // invoice of any kind (so fully-paid clients still get a full statement).
    $unpaidDates = array_filter(array_map(fn($i)=>$i['date'], array_filter($all, fn($i)=>$i['balance'] > 0)));
    if ($unpaidDates) {
        $smartTo = max($unpaidDates);
    } elseif ($allDates) {
        $smartTo = max($allDates);
    } else {
        $smartTo = date('Y-m-d');
    }

    $from = $reqFrom ?: $smartFrom;
    $to   = $reqTo   ?: $smartTo;
    if ($from > $to) { $t = $from; $from = $to; $to = $t; }

    $inRange = array_values(array_filter($all, fn($i) => $i['date'] >= $from && $i['date'] <= $to));
    usort($inRange, fn($a,$b) => strcmp($a['date'], $b['date']) ?: strcmp($a['number'], $b['number']));

    $today = date('Y-m-d');
    $billed = 0; $totalDue = 0; $overdueDue = 0;
    foreach ($inRange as &$iv) {
        $billed += $iv['total'];
        $totalDue += $iv['balance'];
        if ($iv['balance'] > 0 && $iv['dueDate'] && $iv['dueDate'] < $today) $overdueDue += $iv['balance'];
        $iv['unpaid'] = $iv['balance'] > 0;
    }
    unset($iv);

    echo json_encode([
        'ok'        => true,
        'client'    => $name,
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
    echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
}

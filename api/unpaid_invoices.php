<?php
/* api/unpaid_invoices.php — all unpaid invoices across all clients for bulk-pay */
session_start();
if (empty($_SESSION['auth'])) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Not signed in']); exit; }
require __DIR__ . '/../zoho.php';
header('Content-Type: application/json');

$all = [];
$page = 1;
do {
    [$d, $code] = zoho_api('GET', 'invoices', null, [
        'filter_by'   => 'Status.Unpaid',
        'per_page'    => 200,
        'page'        => $page,
        'sort_column' => 'customer_name',
        'sort_order'  => 'A',
    ]);
    if ($code >= 400) break;
    foreach ($d['invoices'] ?? [] as $iv) {
        if (($iv['status'] ?? '') === 'void') continue;
        $bal = (float)($iv['balance'] ?? 0);
        if ($bal <= 0) continue;
        $all[] = [
            'id'            => (string)($iv['invoice_id']     ?? ''),
            'number'        => (string)($iv['invoice_number'] ?? ''),
            'customer_id'   => (string)($iv['customer_id']   ?? ''),
            'customer_name' => (string)($iv['customer_name'] ?? ''),
            'date'          => substr($iv['date']     ?? '', 0, 10),
            'due_date'      => substr($iv['due_date'] ?? '', 0, 10),
            'balance'       => $bal,
            'total'         => (float)($iv['total'] ?? 0),
            'currency'      => (string)($iv['currency_code'] ?? 'KES'),
        ];
    }
    $more = ($d['page_context']['has_more_page'] ?? false);
    $page++;
} while ($more && $page <= 15);

echo json_encode(['ok' => true, 'invoices' => $all, 'count' => count($all)]);

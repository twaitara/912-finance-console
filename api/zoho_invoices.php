<?php
/* api/zoho_invoices.php — browse Zoho invoices with filters */
session_start();
require_once __DIR__ . '/../csrf.php'; csrf_guard();
if (empty($_SESSION['auth'])) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Not signed in']); exit; }
require __DIR__ . '/../zoho.php';
header('Content-Type: application/json');

$status = trim($_GET['status'] ?? '');   // Draft|Sent|Overdue|Paid|PartiallyPaid|Void|Unpaid
$from   = trim($_GET['from']   ?? '');
$to     = trim($_GET['to']     ?? '');
$sort   = trim($_GET['sort']   ?? 'date');
$order  = strtoupper(trim($_GET['order'] ?? 'D'));
$page   = max(1, (int)($_GET['page'] ?? 1));

$params = [
    'per_page'    => 200,
    'page'        => $page,
    'sort_column' => in_array($sort, ['date','due_date','total','customer_name','invoice_number']) ? $sort : 'date',
    'sort_order'  => $order === 'A' ? 'A' : 'D',
];
$statusMap = ['overdue'=>'OverDue','unpaid'=>'Unpaid','sent'=>'Sent','paid'=>'Paid','partiallypaid'=>'PartiallyPaid','draft'=>'Draft','void'=>'Void'];
if ($status && isset($statusMap[$status])) $params['filter_by'] = 'Status.' . $statusMap[$status];
if ($from)   $params['date_start'] = preg_replace('/[^0-9\-]/','',$from);
if ($to)     $params['date_end']   = preg_replace('/[^0-9\-]/','',$to);

[$d, $code] = zoho_api('GET', 'invoices', null, $params);
if ($code >= 400) { echo json_encode(['ok'=>false,'error'=>$d['message']??'Zoho error '.$code]); exit; }

$items = array_map(fn($iv) => [
    'id'       => (string)($iv['invoice_id']     ?? ''),
    'number'   => (string)($iv['invoice_number'] ?? ''),
    'customer' => (string)($iv['customer_name']  ?? ''),
    'date'     => substr((string)($iv['date']         ?? ''), 0, 10),
    'dueDate'  => substr((string)($iv['due_date']     ?? ''), 0, 10),
    'status'   => strtolower((string)($iv['status']   ?? '')),
    'total'    => (float)($iv['total']                ?? 0),
    'balance'  => (float)($iv['balance']              ?? 0),
    'currency' => (string)($iv['currency_code']       ?? 'KES'),
    'ref'      => (string)($iv['reference_number']    ?? ''),
], $d['invoices'] ?? []);

echo json_encode([
    'ok'      => true,
    'items'   => $items,
    'count'   => count($items),
    'hasMore' => (bool)($d['page_context']['has_more_page'] ?? false),
    'page'    => $page,
]);

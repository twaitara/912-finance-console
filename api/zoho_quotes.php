<?php
/* api/zoho_quotes.php — browse Zoho estimates/quotes with filters */
session_start();
require_once __DIR__ . '/../csrf.php'; csrf_guard();
if (empty($_SESSION['auth'])) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Not signed in']); exit; }
require __DIR__ . '/../zoho.php';
header('Content-Type: application/json');

$status = trim($_GET['status'] ?? '');   // Draft|Sent|Accepted|Declined|Invoiced|Expired
$from   = trim($_GET['from']   ?? '');
$to     = trim($_GET['to']     ?? '');
$sort   = trim($_GET['sort']   ?? 'date');
$order  = strtoupper(trim($_GET['order'] ?? 'D'));
$page   = max(1, (int)($_GET['page'] ?? 1));

$params = [
    'per_page'    => 200,
    'page'        => $page,
    'sort_column' => in_array($sort, ['date','total','customer_name','estimate_number']) ? $sort : 'date',
    'sort_order'  => $order === 'A' ? 'A' : 'D',
];
$statusMap = ['draft'=>'Draft','sent'=>'Sent','accepted'=>'Accepted','declined'=>'Declined','invoiced'=>'Invoiced','expired'=>'Expired'];
if ($status && isset($statusMap[$status])) $params['filter_by'] = 'Status.' . $statusMap[$status];
if ($from)   $params['date_start'] = preg_replace('/[^0-9\-]/','',$from);
if ($to)     $params['date_end']   = preg_replace('/[^0-9\-]/','',$to);

[$d, $code] = zoho_api('GET', 'estimates', null, $params);
if ($code >= 400) { echo json_encode(['ok'=>false,'error'=>$d['message']??'Zoho error '.$code]); exit; }

$items = array_map(fn($e) => [
    'id'       => (string)($e['estimate_id']     ?? ''),
    'number'   => (string)($e['estimate_number'] ?? ''),
    'customer' => (string)($e['customer_name']   ?? ''),
    'date'     => substr((string)($e['date']          ?? ''), 0, 10),
    'expiry'   => substr((string)($e['expiry_date']   ?? ''), 0, 10),
    'status'   => strtolower((string)($e['status']    ?? '')),
    'total'    => (float)($e['total']                 ?? 0),
    'currency' => (string)($e['currency_code']        ?? 'KES'),
    'ref'      => (string)($e['reference_number']     ?? ''),
], $d['estimates'] ?? []);

echo json_encode([
    'ok'      => true,
    'items'   => $items,
    'count'   => count($items),
    'hasMore' => (bool)($d['page_context']['has_more_page'] ?? false),
    'page'    => $page,
]);

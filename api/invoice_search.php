<?php
/* api/invoice_search.php — live invoice lookup by number/customer for the cost form. */
session_start();
header('Content-Type: application/json; charset=utf-8');
if (empty($_SESSION['auth'])) { http_response_code(401); echo json_encode(['ok'=>false,'invoices'=>[]]); exit; }
require __DIR__ . '/../zoho.php';
try {
    $q = trim((string)($_GET['q'] ?? ''));
    if ($q === '') { echo json_encode(['ok'=>true,'invoices'=>[]]); exit; }
    [$d, $c] = zoho_api('GET', 'invoices', null, ['search_text'=>$q, 'per_page'=>25, 'sort_column'=>'date', 'sort_order'=>'D']);
    if ($c >= 400) throw new Exception($d['message'] ?? 'Zoho error.');
    $out = [];
    foreach (($d['invoices'] ?? []) as $i) {
        $out[] = [
            'number'    => $i['invoice_number'] ?? '',
            'id'        => $i['invoice_id'] ?? '',
            'client'    => $i['customer_name'] ?? '',
            'reference' => $i['reference_number'] ?? '',
            'total'     => (float)($i['total'] ?? 0),
            'status'    => $i['status'] ?? '',
        ];
    }
    echo json_encode(['ok'=>true, 'invoices'=>$out]);
} catch (Exception $e) {
    echo json_encode(['ok'=>false, 'error'=>$e->getMessage(), 'invoices'=>[]]);
}

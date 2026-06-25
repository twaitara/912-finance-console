<?php
/* api/payment_accounts.php — returns Zoho Books bank/deposit accounts for the payment form */
session_start();
if (empty($_SESSION['auth'])) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Not signed in']); exit; }
require __DIR__ . '/../zoho.php';
header('Content-Type: application/json');

[$data, $code] = zoho_api('GET', 'bankaccounts?filter_by=BankAccount.All&per_page=100');
if ($code >= 400) {
    echo json_encode(['ok'=>false,'error'=>'Zoho error '.$code, 'accounts'=>[]]);
    exit;
}

$accounts = array_map(function($a) {
    return [
        'id'   => (string)($a['account_id'] ?? ''),
        'name' => (string)($a['account_name'] ?? ''),
        'type' => (string)($a['account_type'] ?? ''),
    ];
}, $data['bankaccounts'] ?? []);

echo json_encode(['ok'=>true, 'accounts'=>$accounts]);

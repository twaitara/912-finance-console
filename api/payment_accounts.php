<?php
/* api/payment_accounts.php — returns Zoho Books bank/cash accounts for the Deposit To field */
session_start();
if (empty($_SESSION['auth'])) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Not signed in']); exit; }
require __DIR__ . '/../zoho.php';
header('Content-Type: application/json');

$accounts = [];

// Primary: bank accounts endpoint
[$data, $code] = zoho_api('GET', 'bankaccounts', null, ['per_page' => 100]);
if ($code < 400 && !empty($data['bankaccounts'])) {
    foreach ($data['bankaccounts'] as $a) {
        $id   = (string)($a['account_id']   ?? '');
        $name = (string)($a['account_name'] ?? '');
        if ($id && $name) $accounts[] = ['id' => $id, 'name' => $name, 'type' => 'bank'];
    }
}

// Fallback / supplement: chart of accounts (bank + cash types)
if (empty($accounts)) {
    [$coa, $coaCode] = zoho_api('GET', 'chartofaccounts', null, ['per_page' => 200]);
    if ($coaCode < 400) {
        foreach (($coa['chartofaccounts'] ?? []) as $a) {
            $type = strtolower($a['account_type'] ?? '');
            if (!in_array($type, ['bank', 'cash', 'other_current_asset'])) continue;
            $id   = (string)($a['account_id']   ?? '');
            $name = (string)($a['account_name'] ?? '');
            if ($id && $name) $accounts[] = ['id' => $id, 'name' => $name, 'type' => $type];
        }
    }
}

echo json_encode(['ok' => true, 'accounts' => $accounts]);

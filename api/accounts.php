<?php
require_once __DIR__ . '/../errors.php';
/* api/accounts.php — list Zoho expense accounts + paid-through (cash/bank) accounts
   for the "add a cost to an invoice" form. Admin only. */
session_start();
require_once __DIR__ . '/../csrf.php'; csrf_guard();
header('Content-Type: application/json; charset=utf-8');
if (empty($_SESSION['auth']) || empty($_SESSION['is_admin'])) {
    http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Admins only.']); exit;
}
require __DIR__ . '/../zoho.php';
try {
    [$d, $c] = zoho_api('GET', 'chartofaccounts', null, ['per_page' => 200]);
    if ($c >= 400) throw new Exception($d['message'] ?? 'Zoho error (accounts).');
    $exp = []; $paid = [];
    foreach (($d['chartofaccounts'] ?? []) as $a) {
        $t = $a['account_type'] ?? '';
        $row = ['id' => (string)$a['account_id'], 'name' => $a['account_name'] ?? '', 'type' => $t];
        if (in_array($t, ['expense','cost_of_goods_sold','other_expense'], true)) $exp[] = $row;
        if (in_array($t, ['cash','bank'], true)) $paid[] = $row;
    }
    $def = [];
    $f = __DIR__ . '/../data/expense_defaults.json';
    if (is_file($f)) { $j = json_decode(@file_get_contents($f), true); if (is_array($j)) $def = $j; }
    echo json_encode(['ok'=>true, 'expense'=>$exp, 'paid'=>$paid, 'defaults'=>$def]);
} catch (Exception $e) {
    echo api_fail($e);
}

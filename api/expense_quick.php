<?php
require_once __DIR__ . '/../errors.php';
/* api/expense_quick.php — log a general Zoho Books expense from the dashboard.
   Unlike expense_add.php this is NOT tied to an invoice (no reference_number by default),
   so it won't be counted as a specific invoice's cost in the Profit report. Admin only. KES. */
session_start();
require_once __DIR__ . '/../csrf.php'; csrf_guard();
header('Content-Type: application/json; charset=utf-8');
if (empty($_SESSION['auth']) || empty($_SESSION['is_admin'])) {
    http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Admins only.']); exit;
}
require __DIR__ . '/../zoho.php';
try {
    $in = json_decode(file_get_contents('php://input'), true); if (!is_array($in)) $in = $_POST;
    $amount = (float)($in['amount'] ?? 0);                 if ($amount <= 0) throw new Exception('Enter an amount greater than zero.');
    $accId  = trim((string)($in['account_id'] ?? ''));     if ($accId === '') throw new Exception('Choose an expense account.');
    $paid   = trim((string)($in['paid_through_account_id'] ?? ''));
    $date   = ((string)($in['date'] ?? '')) ?: date('Y-m-d');
    $desc   = substr(trim((string)($in['description'] ?? '')), 0, 500);
    $ref    = substr(trim((string)($in['reference_number'] ?? '')), 0, 100);

    $body = [
        'account_id'       => $accId,
        'date'             => $date,
        'amount'           => $amount,
        'description'      => $desc !== '' ? $desc : 'Expense',
        'is_inclusive_tax' => false,
    ];
    if ($ref  !== '') $body['reference_number']       = $ref;
    if ($paid !== '') $body['paid_through_account_id'] = $paid;

    [$d, $c] = zoho_api('POST', 'expenses', $body);
    if ($c >= 400) {
        throw new Exception($d['message'] ?? 'Zoho rejected the expense (check scope: ZohoBooks.expenses.CREATE).');
    }
    // remember last-used accounts so they pre-select next time (shared with expense_add.php)
    $dir = __DIR__ . '/../data'; if (!is_dir($dir)) @mkdir($dir, 0775, true);
    @file_put_contents($dir . '/expense_defaults.json', json_encode(['account_id'=>$accId,'paid_through_account_id'=>$paid]));

    echo json_encode(['ok'=>true, 'expense_id'=>$d['expense']['expense_id'] ?? null]);
} catch (Exception $e) {
    echo api_fail($e);
}

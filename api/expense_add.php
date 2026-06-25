<?php
/* api/expense_add.php — create a Zoho Books expense booked against a specific invoice.
   The expense's reference_number = the invoice number, so the Profit report (which sums
   expenses by reference_number) counts it as that invoice's cost. Admin only. KES. */
session_start();
header('Content-Type: application/json; charset=utf-8');
if (empty($_SESSION['auth']) || empty($_SESSION['is_admin'])) {
    http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Admins only.']); exit;
}
require __DIR__ . '/../zoho.php';
try {
    $in = json_decode(file_get_contents('php://input'), true); if (!is_array($in)) $in = $_POST;
    $invoiceNo = trim((string)($in['invoice_number'] ?? '')); if ($invoiceNo === '') throw new Exception('Pick an invoice.');
    $amount    = (float)($in['amount'] ?? 0);                  if ($amount <= 0) throw new Exception('Enter an amount greater than zero.');
    $accId     = trim((string)($in['account_id'] ?? ''));     if ($accId === '') throw new Exception('Choose an expense account.');
    $paid      = trim((string)($in['paid_through_account_id'] ?? ''));
    $date      = ((string)($in['date'] ?? '')) ?: date('Y-m-d');
    $desc      = substr(trim((string)($in['description'] ?? '')), 0, 500);

    $body = [
        'account_id'       => $accId,
        'date'             => $date,
        'amount'           => $amount,
        'reference_number' => $invoiceNo,           // <-- ties the cost to this invoice
        'description'      => $desc !== '' ? $desc : ('Manual cost for ' . $invoiceNo),
        'is_inclusive_tax' => false,
    ];
    if ($paid !== '') $body['paid_through_account_id'] = $paid;

    [$d, $c] = zoho_api('POST', 'expenses', $body);
    if ($c >= 400) {
        throw new Exception($d['message'] ?? 'Zoho rejected the expense (check scope: ZohoBooks.expenses.CREATE).');
    }
    // remember last-used accounts so they pre-select next time
    $dir = __DIR__ . '/../data'; if (!is_dir($dir)) @mkdir($dir, 0775, true);
    @file_put_contents($dir . '/expense_defaults.json', json_encode(['account_id'=>$accId,'paid_through_account_id'=>$paid]));

    echo json_encode(['ok'=>true, 'expense_id'=>$d['expense']['expense_id'] ?? null]);
} catch (Exception $e) {
    echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
}

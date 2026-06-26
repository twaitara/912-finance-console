<?php
/* api/payment_record.php — record a customer payment in Zoho Books */
session_start();
if (empty($_SESSION['auth']))    { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Not signed in']); exit; }
if (empty($_SESSION['is_admin'])){ http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Admin only']); exit; }
require __DIR__ . '/../zoho.php';
header('Content-Type: application/json');

$body = json_decode(file_get_contents('php://input'), true) ?: [];

$customerId    = trim((string)($body['customer_id']        ?? ''));
$amount        = (float)($body['amount']                   ?? 0);
$date          = trim((string)($body['date']               ?? date('Y-m-d')));
$mode          = trim((string)($body['mode']               ?? 'bankremittance'));
$reference     = trim((string)($body['reference']          ?? ''));
$notes         = trim((string)($body['notes']              ?? ''));
$bankCharges   = (float)($body['bank_charges']             ?? 0);
$depositAccId  = trim((string)($body['deposit_account_id'] ?? ''));
$invoices      = $body['invoices']                         ?? [];

if (!$customerId) { echo json_encode(['ok'=>false,'error'=>'No client selected']); exit; }
if ($amount <= 0) { echo json_encode(['ok'=>false,'error'=>'Amount must be greater than zero']); exit; }
if (!$invoices)   { echo json_encode(['ok'=>false,'error'=>'No invoices selected']); exit; }

// Each invoice gets the cash applied (amount_applied) plus any tax withheld (tax_amount_withheld).
// Zoho clears the invoice by amount_applied + tax_amount_withheld — WHT is recorded, not left owing.
// The payment-level `amount` is the cash received, so sum(amount_applied) is capped to it.
$totalApplied = 0;
$zohoInvoices = [];
foreach ($invoices as $iv) {
    $ivId  = trim((string)($iv['invoice_id'] ?? ''));
    $apply = (float)($iv['amount_applied'] ?? 0);
    $wht   = (float)($iv['tax_amount_withheld'] ?? 0);
    if (!$ivId || ($apply <= 0 && $wht <= 0)) continue;
    if ($apply > 0) {
        $remaining = $amount - $totalApplied;
        if ($remaining < $apply) $apply = max(0, $remaining);
        $totalApplied += $apply;
    }
    $row = ['invoice_id' => $ivId, 'amount_applied' => round($apply, 2)];
    if ($wht > 0) $row['tax_amount_withheld'] = round($wht, 2);
    $zohoInvoices[] = $row;
}

if (!$zohoInvoices) { echo json_encode(['ok'=>false,'error'=>'No valid invoice amounts to apply']); exit; }

$payload = [
    'customer_id'      => $customerId,
    'payment_mode'     => $mode,
    'amount'           => round($amount, 2),
    'date'             => $date,
    'reference_number' => $reference,
    'description'      => $notes,
    'bank_charges'     => round($bankCharges, 2),
    'invoices'         => $zohoInvoices,
];
if ($depositAccId) $payload['account_id'] = $depositAccId;

[$data, $code] = zoho_api('POST', 'customerpayments', $payload);

if ($code >= 400 || empty($data['customerpayment'])) {
    $msg = $data['message'] ?? ($data['error'] ?? 'Zoho returned error '.$code);
    echo json_encode(['ok'=>false, 'error'=>$msg]);
    exit;
}

echo json_encode(['ok'=>true, 'payment'=>$data['customerpayment']]);

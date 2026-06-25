<?php
/* api/quote_invoice.php — convert a quote's Zoho estimate into a Zoho INVOICE.
   Used by "Generate Job Card". Owner or the quote's creator. Idempotent: if the
   quote was already invoiced, returns the existing invoice number.
   POST JSON: {id} */
session_start();
header('Content-Type: application/json; charset=utf-8');
if (empty($_SESSION['auth'])) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Not signed in.']); exit; }
require __DIR__ . '/../db.php';
require __DIR__ . '/../zoho.php';
@set_time_limit(90);

try {
    $pdo = db();
    $me    = $_SESSION['user'] ?? '';
    $admin = !empty($_SESSION['is_admin']);

    // make sure the invoice columns exist (older tables)
    foreach ([
        "ADD COLUMN zoho_invoice_id VARCHAR(64) DEFAULT '' AFTER zoho_estimate_number",
        "ADD COLUMN zoho_invoice_number VARCHAR(64) DEFAULT '' AFTER zoho_invoice_id",
    ] as $alter) { try { $pdo->exec("ALTER TABLE quotes $alter"); } catch (Exception $e) {} }

    $in = json_decode(file_get_contents('php://input'), true); if (!is_array($in)) $in = $_POST;
    $id = (int)($in['id'] ?? 0); if ($id <= 0) throw new Exception('No quote.');

    $st = $pdo->prepare("SELECT * FROM quotes WHERE id=?"); $st->execute([$id]);
    $q = $st->fetch(PDO::FETCH_ASSOC);
    if (!$q) throw new Exception('Quote not found.');
    if (!$admin && $q['created_by'] !== $me) throw new Exception('Not your quote.');
    if (empty($q['zoho_estimate_id'])) throw new Exception('Push the quote to Zoho first.');
    if (!in_array($q['status'], ['approved','sent','accepted','invoiced'], true)) {
        throw new Exception('Only an approved quote can be turned into a job card / invoice.');
    }

    // already converted?
    if (!empty($q['zoho_invoice_id'])) {
        echo json_encode(['ok'=>true, 'invoice_number'=>$q['zoho_invoice_number'], 'already'=>true]);
        exit;
    }

    [$d, $c] = zoho_api('POST', 'invoices/fromestimate', null, ['estimate_id' => $q['zoho_estimate_id']]);
    if ($c >= 400 || empty($d['invoice']['invoice_id'])) {
        throw new Exception($d['message'] ?? 'Zoho could not convert the estimate to an invoice (check scope: ZohoBooks.invoices.CREATE).');
    }
    $invId = (string)$d['invoice']['invoice_id'];
    $invNo = (string)($d['invoice']['invoice_number'] ?? '');

    $pdo->prepare("UPDATE quotes SET zoho_invoice_id=?, zoho_invoice_number=?, status='invoiced', last_synced_at=NOW() WHERE id=?")
        ->execute([$invId, $invNo, $id]);

    echo json_encode(['ok'=>true, 'invoice_number'=>$invNo]);
} catch (Exception $e) {
    echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
}

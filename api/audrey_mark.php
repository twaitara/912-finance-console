<?php
/* api/audrey_mark.php (PUBLIC) — POST {invoice, status?, note?} */
header('Content-Type: application/json; charset=utf-8');
require __DIR__ . '/../db.php';

try {
    $in = json_decode(file_get_contents('php://input'), true);
    if (!is_array($in)) $in = $_POST;
    $invoice = trim($in['invoice'] ?? '');
    if ($invoice === '') throw new Exception('No invoice given.');

    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS audrey_marks (
        invoice_number VARCHAR(64) PRIMARY KEY,
        status VARCHAR(16) NOT NULL DEFAULT 'unpaid',
        note TEXT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");
    try { $pdo->exec("ALTER TABLE audrey_marks ADD COLUMN note TEXT NULL"); } catch (Exception $e) {}

    $pdo->prepare("INSERT IGNORE INTO audrey_marks (invoice_number) VALUES (?)")->execute([$invoice]);

    if (array_key_exists('status', $in)) {
        $status = ($in['status'] === 'paid') ? 'paid' : 'unpaid';
        $pdo->prepare("UPDATE audrey_marks SET status=? WHERE invoice_number=?")->execute([$status, $invoice]);
    }
    if (array_key_exists('note', $in)) {
        $note = (string)$in['note'];
        if (function_exists('mb_substr') && mb_strlen($note) > 2000) $note = mb_substr($note, 0, 2000);
        $pdo->prepare("UPDATE audrey_marks SET note=? WHERE invoice_number=?")->execute([$note, $invoice]);
    }

    $st = $pdo->prepare("SELECT status, note FROM audrey_marks WHERE invoice_number=?");
    $st->execute([$invoice]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    echo json_encode(['ok'=>true, 'invoice'=>$invoice, 'status'=>$row['status'] ?? 'unpaid', 'note'=>$row['note'] ?? '']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
}

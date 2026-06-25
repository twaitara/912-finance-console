<?php
/* api/email_book.php — saved email addresses per customer (autocomplete book).
   GET-style read:  POST { customer_id }              -> { ok, emails:[...] }
   Add:             POST { customer_id, add:"a@b.com" } -> { ok, emails:[...] }
   Remove:          POST { customer_id, remove:"a@b" }  -> { ok, emails:[...] } */
session_start();
header('Content-Type: application/json; charset=utf-8');
if (empty($_SESSION['auth'])) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Not signed in.']); exit; }

require __DIR__ . '/../db.php';

function eb_emails($pdo, $cid) {
    $st = $pdo->prepare("SELECT email FROM email_book WHERE customer_id=? ORDER BY email");
    $st->execute([$cid]);
    return $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
}

try {
    $in = json_decode(file_get_contents('php://input'), true);
    if (!is_array($in)) $in = $_POST;
    $cid = preg_replace('/[^0-9]/', '', $in['customer_id'] ?? '');
    if ($cid === '') throw new Exception('No client selected.');

    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS email_book (
        id INT AUTO_INCREMENT PRIMARY KEY,
        customer_id VARCHAR(64) NOT NULL,
        email VARCHAR(190) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_cust_email (customer_id, email)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    if (!empty($in['add'])) {
        // accept one or more addresses separated by comma OR semicolon
        $parts = preg_split('/[;,]+/', $in['add']);
        $added = 0;
        foreach ($parts as $p) {
            $email = trim($p);
            if ($email === '') continue;
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new Exception('Not a valid email address: ' . $email);
            $pdo->prepare("INSERT IGNORE INTO email_book (customer_id, email) VALUES (?, ?)")->execute([$cid, $email]);
            $added++;
        }
        if (!$added) throw new Exception('No valid email address to save.');
    }
    if (!empty($in['remove'])) {
        $pdo->prepare("DELETE FROM email_book WHERE customer_id=? AND email=?")->execute([$cid, trim($in['remove'])]);
    }

    echo json_encode(['ok'=>true, 'emails'=>eb_emails($pdo, $cid)]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
}

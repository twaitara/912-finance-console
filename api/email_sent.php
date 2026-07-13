<?php
require_once __DIR__ . '/../errors.php';
/* api/email_sent.php — mark/unmark a client as "statement sent" (hides them for 30 days).
   {action:'mark', customer_id} | {action:'unmark', customer_id} | {action:'list'} */
session_start();
header('Content-Type: application/json; charset=utf-8');
if (empty($_SESSION['auth'])) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Not signed in.']); exit; }

require __DIR__ . '/../db.php';
require_once __DIR__ . '/../settings_store.php';
$__cfg = require __DIR__ . '/../config.php';
$SENT_DAYS = (int) app_setting($__cfg, 'sent_hide_days'); if ($SENT_DAYS < 1) $SENT_DAYS = 30;

try {
    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS email_sent_marks (
        id INT AUTO_INCREMENT PRIMARY KEY,
        customer_id VARCHAR(64) NOT NULL UNIQUE,
        marked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $in = json_decode(file_get_contents('php://input'), true);
    if (!is_array($in)) $in = $_POST;
    $action = $in['action'] ?? 'list';
    $cid = preg_replace('/[^0-9]/', '', $in['customer_id'] ?? '');

    if ($action === 'mark') {
        if ($cid === '') throw new Exception('No client.');
        $pdo->prepare("INSERT INTO email_sent_marks (customer_id, marked_at) VALUES (?, NOW())
                       ON DUPLICATE KEY UPDATE marked_at = NOW()")->execute([$cid]);
    } elseif ($action === 'unmark') {
        if ($cid === '') throw new Exception('No client.');
        $pdo->prepare("DELETE FROM email_sent_marks WHERE customer_id=?")->execute([$cid]);
    }

    $cut = date("Y-m-d H:i:s", time() - $SENT_DAYS*24*3600);
    $st = $pdo->prepare("SELECT customer_id, marked_at FROM email_sent_marks WHERE marked_at >= ? ORDER BY marked_at DESC");
    $st->execute([$cut]);
    echo json_encode(['ok'=>true, 'marked'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
} catch (Exception $e) {
    http_response_code(500);
    echo api_fail($e);
}

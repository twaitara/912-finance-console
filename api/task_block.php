<?php
require_once __DIR__ . '/../errors.php';
/* api/task_block.php — senders whose emails must never appear as task candidates.
   {action:'list'} | {action:'add', email} | {action:'remove', email} */
session_start();
require_once __DIR__ . '/../csrf.php'; csrf_guard();
header('Content-Type: application/json; charset=utf-8');
if (empty($_SESSION['auth'])) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Not signed in.']); exit; }

require __DIR__ . '/../db.php';

try {
    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS task_block_senders (
        id INT AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(190) NOT NULL UNIQUE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $in = json_decode(file_get_contents('php://input'), true);
    if (!is_array($in)) $in = $_POST;
    $action = $in['action'] ?? 'list';

    if ($action === 'add') {
        $email = strtolower(trim($in['email'] ?? ''));
        if ($email === '') throw new Exception('No sender email.');
        $pdo->prepare("INSERT IGNORE INTO task_block_senders (email) VALUES (?)")->execute([$email]);
    } elseif ($action === 'remove') {
        $email = strtolower(trim($in['email'] ?? ''));
        $pdo->prepare("DELETE FROM task_block_senders WHERE email=?")->execute([$email]);
    }
    $list = $pdo->query("SELECT email FROM task_block_senders ORDER BY email")->fetchAll(PDO::FETCH_COLUMN);
    echo json_encode(['ok'=>true, 'blocked'=>$list]);
} catch (Exception $e) {
    http_response_code(500);
    echo api_fail($e);
}

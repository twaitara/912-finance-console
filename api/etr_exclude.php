<?php
require_once __DIR__ . '/../errors.php';
/* =============================================================
   api/etr_exclude.php — mark invoices that will never have an
   ETR file ("no tax invoice"), so they drop off the missing list.
   Stored in MySQL; the table auto-creates on first use.

   GET                       -> list all exclusions
   POST ?action=add          -> {invoice_number, client?, reason?}
   POST ?action=remove       -> {invoice_number}
   ============================================================= */

session_start();
require_once __DIR__ . '/../csrf.php'; csrf_guard();
if (empty($_SESSION['auth'])) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Not signed in.']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
require __DIR__ . '/../db.php';

function etr_ensure_table($pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS etr_exclusions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        invoice_number VARCHAR(50) NOT NULL UNIQUE,
        client VARCHAR(255) NULL,
        reason VARCHAR(255) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

try {
    $pdo = db();
    etr_ensure_table($pdo);
    $method = $_SERVER['REQUEST_METHOD'];
    $action = $_GET['action'] ?? '';

    if ($method === 'GET') {
        $rows = $pdo->query("SELECT invoice_number, client, reason, created_at
                             FROM etr_exclusions ORDER BY created_at DESC")
                    ->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['ok' => true, 'exclusions' => $rows]);
        exit;
    }

    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $num = trim($in['invoice_number'] ?? '');
    if ($num === '') throw new Exception('invoice_number required');

    if ($method === 'POST' && $action === 'add') {
        $stmt = $pdo->prepare("INSERT INTO etr_exclusions (invoice_number, client, reason)
                               VALUES (?,?,?)
                               ON DUPLICATE KEY UPDATE client = VALUES(client), reason = VALUES(reason)");
        $stmt->execute([$num, $in['client'] ?? null, $in['reason'] ?? null]);
        echo json_encode(['ok' => true, 'invoice_number' => $num]);
        exit;
    }

    if ($method === 'POST' && $action === 'remove') {
        $stmt = $pdo->prepare("DELETE FROM etr_exclusions WHERE invoice_number = ?");
        $stmt->execute([$num]);
        echo json_encode(['ok' => true, 'invoice_number' => $num]);
        exit;
    }

    throw new Exception('Unknown action');

} catch (Exception $e) {
    http_response_code(500);
    echo api_fail($e);
}

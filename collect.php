<?php
/* collect.php — shared "ready to collect" flags.
   Written by the local reconciliation app (token-protected, server-to-server) and
   read by Grace's portal, so marking an invoice "ready to collect" in the offline
   reconciliation tool surfaces it to Grace on the live portal.
     GET  ?token=…            -> {ok, ready:[invoice_id,…]}
     POST ?token=…  body {invoice_id, invoice_number, customer_name, ready:bool}
   Token is stored in data/collect_secret.json (web-blocked). */
require_once __DIR__ . '/errors.php';
header('Content-Type: application/json; charset=utf-8');

$dir = __DIR__ . '/data';
$sf  = $dir . '/collect_secret.json';
$secret = is_file($sf) ? (string)(json_decode(@file_get_contents($sf), true)['token'] ?? '') : '';
$tok = (string)($_GET['token'] ?? ($_SERVER['HTTP_X_COLLECT_TOKEN'] ?? ''));
if ($secret === '' || !hash_equals($secret, $tok)) { http_response_code(403); echo json_encode(['ok'=>false, 'error'=>'forbidden']); exit; }

require_once __DIR__ . '/db.php';
try {
    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS collect_flags (
        invoice_id VARCHAR(64) PRIMARY KEY,
        invoice_number VARCHAR(64) DEFAULT '',
        customer_name VARCHAR(190) DEFAULT '',
        ready TINYINT(1) DEFAULT 1,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $in = json_decode(file_get_contents('php://input'), true) ?: [];
        $iid = trim((string)($in['invoice_id'] ?? ''));
        if ($iid === '') { echo json_encode(['ok'=>false, 'error'=>'no invoice_id']); exit; }
        if (!empty($in['ready'])) {
            $pdo->prepare("INSERT INTO collect_flags (invoice_id,invoice_number,customer_name,ready) VALUES (?,?,?,1)
                ON DUPLICATE KEY UPDATE invoice_number=VALUES(invoice_number),customer_name=VALUES(customer_name),ready=1")
                ->execute([$iid, mb_substr((string)($in['invoice_number'] ?? ''), 0, 64), mb_substr((string)($in['customer_name'] ?? ''), 0, 190)]);
        } else {
            $pdo->prepare("DELETE FROM collect_flags WHERE invoice_id=?")->execute([$iid]);
        }
        echo json_encode(['ok'=>true, 'invoice_id'=>$iid, 'ready'=>!empty($in['ready'])]); exit;
    }

    $ids = [];
    foreach ($pdo->query("SELECT invoice_id FROM collect_flags WHERE ready=1")->fetchAll(PDO::FETCH_COLUMN) as $v) $ids[] = (string)$v;
    echo json_encode(['ok'=>true, 'ready'=>$ids]);
} catch (\Throwable $e) { echo api_fail($e); }

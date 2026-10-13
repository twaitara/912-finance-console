<?php
/* fiscal_sync.php — receives the fiscalisation queue from the local reconciliation app.
   The local app (on the office PC) is the only thing that sees both folders, so it
   computes "filed − fiscalised" (KES), enriches each with Zoho details, and POSTs the
   list here. We REPLACE the fiscal_queue table with it, and prune fiscal_flags rows
   whose invoice is no longer in the queue (meaning it's genuinely fiscalised now).
     GET  ?token=…                 -> {ok, count}
     POST ?token=…  body {items:[{invoice_number, invoice_id, customer_name, amount, currency, date, descr}]}
   Token is stored in data/collect_secret.json (web-blocked), shared with collect.php. */
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
    $pdo->exec("CREATE TABLE IF NOT EXISTS fiscal_queue (
        invoice_number VARCHAR(64) PRIMARY KEY,
        invoice_id VARCHAR(64) DEFAULT '',
        customer_name VARCHAR(190) DEFAULT '',
        amount DECIMAL(14,2) DEFAULT 0,
        total DECIMAL(14,2) DEFAULT 0,
        balance DECIMAL(14,2) DEFAULT 0,
        currency VARCHAR(8) DEFAULT 'KES',
        inv_date VARCHAR(20) DEFAULT '',
        due_date VARCHAR(20) DEFAULT '',
        descr VARCHAR(255) DEFAULT '',
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    try { $pdo->exec("ALTER TABLE fiscal_queue ADD COLUMN total DECIMAL(14,2) DEFAULT 0"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE fiscal_queue ADD COLUMN balance DECIMAL(14,2) DEFAULT 0"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE fiscal_queue ADD COLUMN due_date VARCHAR(20) DEFAULT ''"); } catch (\Throwable $e) {}
    $pdo->exec("CREATE TABLE IF NOT EXISTS fiscal_flags (
        invoice_number VARCHAR(64) PRIMARY KEY,
        invoice_id VARCHAR(64) DEFAULT '',
        customer_name VARCHAR(190) DEFAULT '',
        done TINYINT(1) DEFAULT 1,
        done_by VARCHAR(80) DEFAULT '',
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $in = json_decode(file_get_contents('php://input'), true) ?: [];
        $items = is_array($in['items'] ?? null) ? $in['items'] : null;
        if ($items === null) { echo json_encode(['ok'=>false, 'error'=>'no items array']); exit; }

        $pdo->beginTransaction();
        $pdo->exec("DELETE FROM fiscal_queue");
        $keep = [];
        if ($items) {
            $st = $pdo->prepare("INSERT INTO fiscal_queue (invoice_number,invoice_id,customer_name,amount,total,balance,currency,inv_date,due_date,descr)
                VALUES (?,?,?,?,?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE invoice_id=VALUES(invoice_id),customer_name=VALUES(customer_name),amount=VALUES(amount),total=VALUES(total),balance=VALUES(balance),currency=VALUES(currency),inv_date=VALUES(inv_date),due_date=VALUES(due_date),descr=VALUES(descr)");
            foreach ($items as $it) {
                $num = mb_substr(trim((string)($it['invoice_number'] ?? '')), 0, 64);
                if ($num === '') continue;
                $keep[$num] = true;
                $st->execute([
                    $num,
                    mb_substr((string)($it['invoice_id'] ?? ''), 0, 64),
                    mb_substr((string)($it['customer_name'] ?? ''), 0, 190),
                    (float)($it['amount'] ?? 0),
                    (float)($it['total'] ?? 0),
                    (float)($it['balance'] ?? 0),
                    mb_substr(strtoupper((string)($it['currency'] ?? 'KES')), 0, 8),
                    mb_substr((string)($it['date'] ?? ''), 0, 20),
                    mb_substr((string)($it['due'] ?? ''), 0, 20),
                    mb_substr((string)($it['descr'] ?? ''), 0, 255),
                ]);
            }
        }
        /* prune done-marks for invoices that have left the queue (now fiscalised) */
        $flagNums = $pdo->query("SELECT invoice_number FROM fiscal_flags")->fetchAll(PDO::FETCH_COLUMN);
        $del = $pdo->prepare("DELETE FROM fiscal_flags WHERE invoice_number=?");
        foreach ($flagNums as $fn) { if (!isset($keep[(string)$fn])) $del->execute([(string)$fn]); }
        $pdo->commit();

        echo json_encode(['ok'=>true, 'count'=>count($keep)]); exit;
    }

    $count = (int)$pdo->query("SELECT COUNT(*) FROM fiscal_queue")->fetchColumn();
    echo json_encode(['ok'=>true, 'count'=>$count]);
} catch (\Throwable $e) { if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack(); echo api_fail($e); }

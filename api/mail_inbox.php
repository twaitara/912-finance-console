<?php
require_once __DIR__ . '/../errors.php';
/* api/mail_inbox.php — recent inbox emails (last 14 days) for turning into tasks.
   Skips senders on the block list (task_block_senders). Needs ZohoMail.messages.READ. */
session_start();
header('Content-Type: application/json; charset=utf-8');
if (empty($_SESSION['auth'])) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Not signed in.']); exit; }

require __DIR__ . '/../zoho.php';
require __DIR__ . '/../mail.php';
require __DIR__ . '/../db.php';
require_once __DIR__ . '/../settings_store.php';
$__cfg = require __DIR__ . '/../config.php';
$INBOX_DAYS = (int) app_setting($__cfg, 'inbox_days'); if ($INBOX_DAYS < 1) $INBOX_DAYS = 14;

function mi_email($s){
    if (preg_match('/<([^>]+)>/', (string)$s, $m)) return strtolower(trim($m[1]));
    return strtolower(trim((string)$s));
}

try {
    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS task_block_senders (
        id INT AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(190) NOT NULL UNIQUE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $blocked = [];
    foreach ($pdo->query("SELECT email FROM task_block_senders")->fetchAll(PDO::FETCH_COLUMN) as $b) $blocked[strtolower($b)] = true;

    $acc = mail_primary_account();
    $accountId = $acc['accountId'];
    if ($accountId === '') throw new Exception('No Mail account id.');

    [$d, $code] = mail_api('GET', '/api/accounts/' . rawurlencode($accountId) . '/messages/view?limit=100&start=1');
    if ($code === 401 || $code === 403) {
        echo json_encode(['ok'=>false, 'need_scope'=>true,
            'error'=>'Reading email needs the ZohoMail.messages.READ scope. Re-mint the Mail token with accounts.READ and messages.ALL, then delete data/mail_token.json.']);
        exit;
    }
    if ($code >= 400) throw new Exception('Mail list error (' . $code . ').');

    $cutoff = (time() - $INBOX_DAYS*24*3600) * 1000; // epoch ms
    $out = [];
    foreach (($d['data'] ?? []) as $m) {
        $recRaw = $m['receivedTime'] ?? ($m['sentDateInGMT'] ?? '');
        $recMs  = is_numeric($recRaw) ? (float)$recRaw : 0;
        if ($recMs && $recMs < $cutoff) continue;                 // older than 14 days
        $from   = $m['fromAddress'] ?? ($m['sender'] ?? '');
        $fEmail = mi_email($from);
        if ($fEmail !== '' && isset($blocked[$fEmail])) continue;  // blocked sender
        $rec = $recMs ? date('Y-m-d H:i', (int)($recMs/1000)) : '';
        $out[] = [
            'id'        => $m['messageId'] ?? '',
            'subject'   => trim($m['subject'] ?? '(no subject)'),
            'from'      => $from,
            'fromEmail' => $fEmail,
            'summary'   => trim($m['summary'] ?? ''),
            'received'  => $rec,
        ];
    }
    echo json_encode(['ok'=>true, 'messages'=>$out, 'from'=>$acc['from'], 'days'=>$INBOX_DAYS]);
} catch (Exception $e) {
    http_response_code(500);
    echo api_fail($e);
}

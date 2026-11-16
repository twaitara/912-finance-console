<?php
/* stmt_draft.php — the owner's local reconciliation app asks the live site to save a
   statement email as a DRAFT in the company Zoho mailbox (never sends), with invoice PDFs
   attached and optional CC. Invoices whose PDF was attached are then ticked "sent" so Grace
   sees it on her portal.
     GET  ?token=…  -> {ok, connected}
     POST ?token=…  body {to, cc, subject, html, attachments:[{name, b64}], sent:[{invoice_id, invoice_number, customer_name}]}
   Token is the shared bridge token in data/collect_secret.json (web-blocked). */
require_once __DIR__ . '/errors.php';
header('Content-Type: application/json; charset=utf-8');
@set_time_limit(180);

$sf = __DIR__ . '/data/collect_secret.json';
$secret = is_file($sf) ? (string)(json_decode(@file_get_contents($sf), true)['token'] ?? '') : '';
$tok = (string)($_GET['token'] ?? ($_SERVER['HTTP_X_COLLECT_TOKEN'] ?? ''));
if ($secret === '' || !hash_equals($secret, $tok)) { http_response_code(403); echo json_encode(['ok'=>false, 'error'=>'forbidden']); exit; }

require_once __DIR__ . '/mail.php';
require_once __DIR__ . '/db.php';

function sd_emails($raw) {
    $out = [];
    foreach (preg_split('/[,;\s]+/', (string)$raw) as $p) {
        $p = trim($p); if ($p === '') continue;
        if (!filter_var($p, FILTER_VALIDATE_EMAIL)) throw new Exception('Not a valid email address: ' . $p);
        $out[strtolower($p)] = $p;
    }
    return array_values($out);
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { echo json_encode(['ok'=>true, 'connected'=>mail_is_connected()]); exit; }

    $in = json_decode(file_get_contents('php://input'), true);
    if (!is_array($in)) throw new Exception('Bad request (the email may be too large — attach fewer invoices).');
    $to = sd_emails($in['to'] ?? ''); if (!$to) throw new Exception('No recipient email.');
    $cc = array_values(array_diff(sd_emails($in['cc'] ?? ''), $to));
    $subject = mb_substr(trim((string)($in['subject'] ?? '')), 0, 200); if ($subject === '') $subject = 'Statement of account';
    $html = (string)($in['html'] ?? ''); if ($html === '') throw new Exception('Email body is empty.');

    $acct = mail_primary_account();
    if (empty($acct['accountId'])) throw new Exception('Could not resolve the Mail account.');
    $base = mail_base() . '/api/accounts/' . rawurlencode($acct['accountId']);

    // 1) upload each PDF to Zoho Mail's attachment store
    $atts = []; $failed = []; $okNames = [];
    foreach ((is_array($in['attachments'] ?? null) ? $in['attachments'] : []) as $a) {
        $name = preg_replace('/[^A-Za-z0-9 ._()\-]/', '_', mb_substr((string)($a['name'] ?? 'invoice.pdf'), 0, 120));
        $bin  = base64_decode((string)($a['b64'] ?? ''), true);
        if ($bin === false || strncmp($bin, '%PDF', 4) !== 0) { $failed[] = $name . ' (not a PDF)'; continue; }
        $ch = curl_init($base . '/messages/attachments?fileName=' . rawurlencode($name));
        curl_setopt_array($ch, [CURLOPT_POST=>true, CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>60, CURLOPT_POSTFIELDS=>$bin,
            CURLOPT_HTTPHEADER=>['Authorization: Zoho-oauthtoken ' . mail_access_token(), 'Content-Type: application/octet-stream', 'Accept: application/json']]);
        $res = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        $d = json_decode((string)$res, true); $x = $d['data'] ?? null;
        if (is_array($x) && isset($x[0]) && is_array($x[0])) $x = $x[0];
        if ($code < 400 && is_array($x) && !empty($x['storeName']) && !empty($x['attachmentPath'])) {
            $atts[] = ['storeName'=>$x['storeName'], 'attachmentPath'=>$x['attachmentPath'], 'attachmentName'=>$x['attachmentName'] ?? $name];
            $okNames[$name] = true;
        } else {
            $failed[] = $name . ' (' . ($d['data']['moreInfo'] ?? ($d['status']['description'] ?? ('HTTP ' . $code))) . ')';
        }
    }

    // 2) the draft itself
    $payload = ['mode'=>'draft', 'fromAddress'=>$acct['from'], 'toAddress'=>implode(',', $to), 'subject'=>$subject, 'content'=>$html, 'mailFormat'=>'html'];
    if ($cc)   $payload['ccAddress']   = implode(',', $cc);
    if ($atts) $payload['attachments'] = $atts;
    [$resp, $code] = mail_api('POST', '/api/accounts/' . rawurlencode($acct['accountId']) . '/messages', $payload);
    if ($code >= 400) throw new Exception('Zoho Mail did not save the draft: ' . ($resp['data']['moreInfo'] ?? ($resp['status']['description'] ?? ('HTTP ' . $code))));

    // 3) tick "sent" for invoices whose PDF really got attached
    $marked = [];
    try {
        $pdo = db();
        $pdo->exec("CREATE TABLE IF NOT EXISTS collect_sent (invoice_id VARCHAR(64) PRIMARY KEY, invoice_number VARCHAR(64) DEFAULT '', customer_name VARCHAR(190) DEFAULT '', updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $st = $pdo->prepare("INSERT INTO collect_sent (invoice_id,invoice_number,customer_name) VALUES (?,?,?)
            ON DUPLICATE KEY UPDATE invoice_number=VALUES(invoice_number),customer_name=VALUES(customer_name)");
        foreach ((is_array($in['sent'] ?? null) ? $in['sent'] : []) as $s) {
            $iid = trim((string)($s['invoice_id'] ?? '')); if ($iid === '') continue;
            $fn = preg_replace('/[^A-Za-z0-9 ._()\-]/', '_', mb_substr((string)($s['file'] ?? ''), 0, 120));
            if ($fn !== '' && !isset($okNames[$fn])) continue;   // its PDF failed to attach -> don't claim it was sent
            $st->execute([mb_substr($iid, 0, 64), mb_substr((string)($s['invoice_number'] ?? ''), 0, 64), mb_substr((string)($s['customer_name'] ?? ''), 0, 190)]);
            $marked[] = $iid;
        }
    } catch (\Throwable $e) { /* draft is saved; ticks can be done by hand */ }

    echo json_encode(['ok'=>true, 'from'=>$acct['from'], 'to'=>implode(', ', $to), 'cc'=>implode(', ', $cc), 'attached'=>count($atts), 'failed'=>$failed, 'marked'=>$marked]);
} catch (\Throwable $e) { echo api_fail($e); }

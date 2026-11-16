<?php
/* mail_client_setup.php — admin-only, one-time: store the Zoho "Server-based Application"
   client (ID + secret) that lets Grace connect her OWN mailbox via connect_mail.php.
   Saved to data/mail_client.json (data/ is web-blocked and never in git). The secret is
   never shown back. Sign in to the main console as an admin first. */
session_start();
require_once __DIR__ . '/csrf.php'; csrf_guard();

function mcs_esc($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function mcs_page($body) {
    echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Mail client setup</title>'
       . '<style>body{font-family:Inter,Arial,sans-serif;background:#E8E6E1;color:#1B1A17;margin:0;padding:40px 16px}.box{max-width:520px;margin:0 auto;background:#fff;border:1px solid #D7D3CB;border-top:3px solid #D95E07;padding:26px}'
       . 'h1{font-size:18px;margin:0 0 10px}p{font-size:14px;line-height:1.5;color:#3d3a35}label{display:block;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:#6E6A63;margin:14px 0 5px}'
       . 'input{width:100%;box-sizing:border-box;padding:11px 12px;border:1px solid #D7D3CB;border-radius:3px;font-size:14px;font-family:Consolas,monospace}'
       . 'button{margin-top:18px;background:#D95E07;color:#fff;border:0;padding:12px 18px;border-radius:3px;font-weight:600;font-size:14px;cursor:pointer}'
       . '.ok{background:#E3EEE6;color:#1D7A3A;padding:10px 12px;font-size:13px}.err{background:#F6E5E1;color:#9B2016;padding:10px 12px;font-size:13px}code{background:#F5F3EF;padding:2px 5px;font-size:12px}</style></head><body><div class="box">' . $body . '</div></body></html>';
}

if (empty($_SESSION['auth']) || empty($_SESSION['is_admin'])) {
    http_response_code(403);
    mcs_page('<h1>Admins only</h1><p>Sign in to the main console as an administrator, then open this page again.</p><p><a href="index.php">Go to the console</a></p>');
    exit;
}

$file = __DIR__ . '/data/mail_client.json';
$cur  = is_file($file) ? (json_decode((string)@file_get_contents($file), true) ?: []) : [];
$msg  = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $tok = (string)($_POST['t'] ?? '');
    $id  = trim((string)($_POST['client_id'] ?? ''));
    $sec = trim((string)($_POST['client_secret'] ?? ''));
    if (empty($_SESSION['mcs_t']) || !hash_equals((string)$_SESSION['mcs_t'], $tok)) {
        $msg = '<div class="err">The form expired — please try again.</div>';
    } elseif (!preg_match('/^1000\.[A-Z0-9]{20,60}$/', $id)) {
        $msg = '<div class="err">That does not look like a Zoho Client ID (it starts with <code>1000.</code>).</div>';
    } elseif (!preg_match('/^[a-f0-9]{30,80}$/i', $sec)) {
        $msg = '<div class="err">That does not look like a Zoho Client Secret.</div>';
    } else {
        if (!is_dir(__DIR__ . '/data')) @mkdir(__DIR__ . '/data', 0775, true);
        $ok = @file_put_contents($file, json_encode(['client_id' => $id, 'client_secret' => $sec, 'saved_at' => date('c'), 'saved_by' => (string)($_SESSION['user'] ?? '')]));
        if ($ok !== false) {
            // a token minted for a different client can no longer be refreshed
            @unlink(__DIR__ . '/data/mail_refresh_token_grace.txt'); @unlink(__DIR__ . '/data/mail_token_grace.json');
            $cur = ['client_id' => $id];
            $msg = '<div class="ok">Saved. Grace can now open her portal → Email statement → Connect my mailbox.</div>';
        } else {
            $msg = '<div class="err">Could not write to the data folder.</div>';
        }
    }
}
$_SESSION['mcs_t'] = bin2hex(random_bytes(16));

mcs_page('<h1>Mail client for Grace\'s mailbox</h1>' . $msg
    . '<p>' . (!empty($cur['client_id']) ? 'Currently saved: <code>' . mcs_esc(substr((string)$cur['client_id'], 0, 12)) . '…</code> (secret hidden). Saving again replaces it.' : 'Nothing saved yet.') . '</p>'
    . '<p>Paste the <b>Client ID</b> and <b>Client Secret</b> of the Zoho API Console client <b>912 Collections Mail</b> (Server-based Application).</p>'
    . '<form method="post" autocomplete="off"><input type="hidden" name="t" value="' . mcs_esc($_SESSION['mcs_t']) . '">'
    . '<label>Client ID</label><input name="client_id" required autocomplete="off" spellcheck="false">'
    . '<label>Client Secret</label><input name="client_secret" type="password" required autocomplete="new-password" spellcheck="false">'
    . '<button type="submit">Save</button></form>');

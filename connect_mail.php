<?php
/* connect_mail.php — one-time "Connect my Zoho Mail" for Grace, so the Collections portal can
   save statement DRAFTS into her own mailbox (it never sends on its own).
   She must be signed in to the Collections portal first (GRACEPORTAL session).
   Start:  connect_mail.php?start=1   -> Zoho consent (Mail scopes only)
   Return: Zoho redirects back with ?code -> we store HER refresh token in data/ (web-blocked).
   The redirect URL of this page must be listed on the Zoho API client (API console). */
session_name('GRACEPORTAL');
session_start();
require __DIR__ . '/mail.php';

const CM_WHO   = 'grace';
const CM_SCOPE = 'ZohoMail.messages.CREATE,ZohoMail.accounts.READ';

function cm_esc($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function cm_page($title, $bodyHtml) {
    echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<title>' . cm_esc($title) . '</title><style>body{font-family:"IBM Plex Sans",Arial,sans-serif;background:#E8E6E1;color:#1B1A17;margin:0;padding:40px 16px}'
       . '.box{max-width:460px;margin:0 auto;background:#fff;border:1px solid #D7D3CB;border-top:3px solid #D95E07;padding:26px}'
       . 'h1{font-size:18px;margin:0 0 10px}p{font-size:14px;line-height:1.5;color:#3d3a35}code{background:#F5F3EF;padding:2px 5px;font-size:12px;word-break:break-all}'
       . 'a.btn{display:inline-block;background:#D95E07;color:#fff;text-decoration:none;padding:11px 18px;border-radius:3px;font-weight:600;margin-top:8px}'
       . 'a.sec{background:#fff;color:#1B1A17;border:1px solid #D7D3CB}</style></head><body><div class="box">' . $bodyHtml . '</div></body></html>';
}
function cm_redirect_uri() {
    $c = zoho_config();
    if (!empty($c['mail_redirect_uri'])) return $c['mail_redirect_uri'];
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'nineonetwo.online';
    $dir    = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/connect_mail.php'), '/\\');
    return $scheme . '://' . $host . $dir . '/connect_mail.php';
}
$portal = 'index.php?portal=grace';

if (empty($_SESSION['grace_auth'])) {
    cm_page('Sign in first', '<h1>Please sign in first</h1><p>Open the Collections portal, sign in with your PIN, then tap “Connect my mailbox” again.</p><p><a class="btn" href="' . $portal . '">Go to the portal</a></p>');
    exit;
}

// returning from Zoho consent
if (isset($_GET['code'])) {
    $state = (string)($_GET['state'] ?? '');
    $want  = (string)($_SESSION['cm_state'] ?? '');
    unset($_SESSION['cm_state']);
    if ($want === '' || !hash_equals($want, $state)) {
        cm_page('Could not verify', '<h1>Security check failed</h1><p>The link didn’t verify. Please try connecting again.</p><p><a class="btn" href="connect_mail.php?start=1">Try again</a></p>');
        exit;
    }
    $c = zoho_config();
    $ch = curl_init(rtrim($c['accounts_url'], '/') . '/oauth/v2/token');
    curl_setopt_array($ch, [CURLOPT_POST=>true, CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>40,
        CURLOPT_POSTFIELDS => http_build_query([
            'grant_type'=>'authorization_code', 'client_id'=>$c['client_id'] ?? '', 'client_secret'=>$c['client_secret'] ?? '',
            'redirect_uri'=>cm_redirect_uri(), 'code'=>(string)$_GET['code'],
        ])]);
    $res = curl_exec($ch); $hc = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $j = json_decode((string)$res, true) ?: [];
    if ($hc < 400 && !empty($j['refresh_token'])) {
        mail_save_refresh_token(CM_WHO, $j['refresh_token']);
        $from = '';
        try { $from = (string)(mail_primary_account(CM_WHO)['from'] ?? ''); } catch (\Throwable $e) { /* shown on first draft instead */ }
        cm_page('Connected', '<h1>Mailbox connected</h1><p>' . ($from !== '' ? 'Statements will be saved to the Drafts of <b>' . cm_esc($from) . '</b>.' : 'Your Zoho Mail is now linked.')
            . ' Nothing is ever sent automatically — you review and send each draft yourself.</p><p><a class="btn" href="' . $portal . '">Back to the portal</a></p>');
    } else {
        cm_page('Could not connect', '<h1>Could not connect</h1><p>Zoho said: <b>' . cm_esc($j['error'] ?? ('HTTP ' . $hc)) . '</b></p>'
            . '<p>If this says <code>invalid_client</code> or mentions the redirect, the administrator needs to add this exact address as an authorised redirect URL on the Zoho API client:</p><p><code>' . cm_esc(cm_redirect_uri()) . '</code></p>'
            . '<p><a class="btn" href="connect_mail.php?start=1">Try again</a></p>');
    }
    exit;
}
if (isset($_GET['error'])) {
    cm_page('Not connected', '<h1>Not connected</h1><p>Zoho returned: <b>' . cm_esc($_GET['error']) . '</b>. Nothing was changed.</p><p><a class="btn" href="connect_mail.php">Back</a></p>');
    exit;
}

// start the connect
if (isset($_GET['start'])) {
    $c = zoho_config();
    $_SESSION['cm_state'] = bin2hex(random_bytes(16));
    header('Location: ' . rtrim($c['accounts_url'], '/') . '/oauth/v2/auth?' . http_build_query([
        'response_type'=>'code', 'client_id'=>$c['client_id'] ?? '', 'scope'=>CM_SCOPE,
        'redirect_uri'=>cm_redirect_uri(), 'access_type'=>'offline', 'prompt'=>'consent', 'state'=>$_SESSION['cm_state'],
    ]));
    exit;
}

if (isset($_GET['disconnect'])) {
    @unlink(mail_refresh_file(CM_WHO)); @unlink(mail_cache_file(CM_WHO));
    cm_page('Disconnected', '<h1>Disconnected</h1><p>Your mailbox link was removed.</p><p><a class="btn" href="connect_mail.php">Back</a></p>');
    exit;
}

// landing
$connected = mail_is_connected(CM_WHO);
cm_page('Connect Zoho Mail', '<h1>Connect your Zoho Mail</h1>'
    . ($connected
        ? '<p>Already connected. Re-connect only if you changed Zoho accounts.</p><p><a class="btn" href="connect_mail.php?start=1">Re-connect</a> &nbsp; <a class="btn sec" href="connect_mail.php?disconnect=1">Disconnect</a></p>'
        : '<p>Sign in to Zoho with <b>your own</b> mailbox when asked and approve once. After that, “Email statement” in the portal saves a ready statement into your Drafts for you to send.</p><p><a class="btn" href="connect_mail.php?start=1">Connect my mailbox</a></p>')
    . '<p style="margin-top:16px"><a class="btn sec" href="' . $portal . '">Back to the portal</a></p>');

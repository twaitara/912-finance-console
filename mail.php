<?php
/* Zoho Mail API helper. Uses a SEPARATE Mail-scoped refresh token stored in
   data/mail_refresh_token.txt (so config.php is never hand-edited), mirroring
   the WorkDrive token pattern. Mints + caches an access token in data/mail_token.json.

   Every function takes an optional $who naming a second mailbox ('' = the owner's, the
   default and unchanged; 'grace' = Grace's own mailbox, connected once via connect_mail.php).
   Each mailbox has its own refresh-token file and access-token cache. */

require_once __DIR__ . '/zoho.php';

function mail_who($who) { return preg_replace('/[^a-z0-9]/', '', strtolower((string)$who)); }
function mail_refresh_file($who = '') { $w = mail_who($who); return __DIR__ . '/data/mail_refresh_token' . ($w !== '' ? '_' . $w : '') . '.txt'; }
function mail_cache_file($who = '')   { $w = mail_who($who); return __DIR__ . '/data/mail_token' . ($w !== '' ? '_' . $w : '') . '.json'; }

function mail_refresh_token($who = '') {
    $f = mail_refresh_file($who);
    if (is_file($f)) { $t = trim(file_get_contents($f)); if ($t !== '') return $t; }
    if (mail_who($who) !== '') return '';          // a named mailbox never falls back to the owner's token
    $c = zoho_config();
    return $c['mail_refresh_token'] ?? '';
}
/* OAuth client for a mailbox. The owner's uses config.php. A named mailbox (Grace) uses the
   server-based client saved by mail_client_setup.php in data/mail_client.json, because the
   config.php client is a Zoho Self Client, which cannot run a browser sign-in for another person. */
function mail_client($who = '') {
    $c = zoho_config();
    $out = ['client_id' => $c['client_id'] ?? '', 'client_secret' => $c['client_secret'] ?? '', 'own' => false];
    if (mail_who($who) !== '') {
        $f = __DIR__ . '/data/mail_client.json';
        $j = is_file($f) ? (json_decode((string)@file_get_contents($f), true) ?: []) : [];
        if (!empty($j['client_id']) && !empty($j['client_secret'])) $out = ['client_id' => (string)$j['client_id'], 'client_secret' => (string)$j['client_secret'], 'own' => true];
    }
    return $out;
}
function mail_is_connected($who = '') { return mail_refresh_token($who) !== ''; }
function mail_save_refresh_token($who, $token) {
    $d = __DIR__ . '/data'; if (!is_dir($d)) @mkdir($d, 0775, true);
    @file_put_contents(mail_refresh_file($who), trim((string)$token));
    @unlink(mail_cache_file($who));
}

function mail_access_token($who = '') {
    $c = zoho_config();
    $cache = mail_cache_file($who);
    if (is_file($cache)) {
        $t = json_decode(file_get_contents($cache), true);
        if ($t && ($t['expires_at'] ?? 0) > time() + 60) return $t['access_token'];
    }
    $rt = mail_refresh_token($who);
    $cl = mail_client($who);
    if ($rt === '') throw new Exception(mail_who($who) !== ''
        ? 'This mailbox is not connected yet.'
        : 'Zoho Mail is not connected yet. Run mail_token.php once to set it up.');

    $ch = curl_init($c['accounts_url'] . '/oauth/v2/token');
    curl_setopt_array($ch, [CURLOPT_POST=>true, CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_POSTFIELDS => http_build_query([
            'refresh_token' => $rt,
            'client_id'     => $cl['client_id'],
            'client_secret' => $cl['client_secret'],
            'grant_type'    => 'refresh_token',
        ])]);
    $res = curl_exec($ch); $err = curl_error($ch); curl_close($ch);
    if ($err) throw new Exception('Mail token request failed: ' . $err);
    $d = json_decode($res, true);
    if (empty($d['access_token'])) throw new Exception('Mail token error: ' . ($d['error'] ?? 'no access token returned'));
    $d['expires_at'] = time() + (int)($d['expires_in'] ?? 3600);
    @file_put_contents($cache, json_encode($d));
    return $d['access_token'];
}

/* mail.zoho.<region> derived from accounts_url (handles .com/.eu/.in etc.) */
function mail_base() {
    $c = zoho_config();
    $host = parse_url($c['accounts_url'], PHP_URL_HOST);        // e.g. accounts.zoho.com
    $region = preg_replace('/^accounts\.zoho\./', '', $host);   // e.g. com
    if ($region === '' || $region === $host) $region = 'com';
    return 'https://mail.zoho.' . $region;
}

/* Returns [decoded_body, http_code]. $path begins with /api/... */
function mail_api($method, $path, $body = null, $who = '') {
    $token = mail_access_token($who);
    $ch = curl_init(mail_base() . $path);
    $headers = ['Authorization: Zoho-oauthtoken ' . $token, 'Accept: application/json'];
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST=>$method, CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>30]);
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $res = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
    if ($err) throw new Exception('Mail API failed: ' . $err);
    return [json_decode($res, true), (int)$code];
}

/* Resolves the primary Mail account id + from-address. */
function mail_primary_account($who = '') {
    [$d, $code] = mail_api('GET', '/api/accounts', null, $who);
    if ($code >= 400) throw new Exception('Could not read Mail accounts (' . $code . ').');
    $acc = $d['data'][0] ?? null;
    if (!$acc) throw new Exception('No Zoho Mail account found on this login.');
    $from = $acc['primaryEmailAddress']
        ?? ($acc['sendMailDetails'][0]['fromAddress'] ?? ($acc['mailboxAddress'] ?? ''));
    return ['accountId' => $acc['accountId'] ?? '', 'from' => $from];
}

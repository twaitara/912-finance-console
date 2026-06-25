<?php
/* Zoho Mail API helper. Uses a SEPARATE Mail-scoped refresh token stored in
   data/mail_refresh_token.txt (so config.php is never hand-edited), mirroring
   the WorkDrive token pattern. Mints + caches an access token in data/mail_token.json. */

require_once __DIR__ . '/zoho.php';

function mail_refresh_token() {
    $f = __DIR__ . '/data/mail_refresh_token.txt';
    if (is_file($f)) { $t = trim(file_get_contents($f)); if ($t !== '') return $t; }
    $c = zoho_config();
    return $c['mail_refresh_token'] ?? '';
}

function mail_access_token() {
    $c = zoho_config();
    $cache = __DIR__ . '/data/mail_token.json';
    if (is_file($cache)) {
        $t = json_decode(file_get_contents($cache), true);
        if ($t && ($t['expires_at'] ?? 0) > time() + 60) return $t['access_token'];
    }
    $rt = mail_refresh_token();
    if ($rt === '') throw new Exception('Zoho Mail is not connected yet. Run mail_token.php once to set it up.');

    $ch = curl_init($c['accounts_url'] . '/oauth/v2/token');
    curl_setopt_array($ch, [CURLOPT_POST=>true, CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_POSTFIELDS => http_build_query([
            'refresh_token' => $rt,
            'client_id'     => $c['client_id'],
            'client_secret' => $c['client_secret'],
            'grant_type'    => 'refresh_token',
        ])]);
    $res = curl_exec($ch); $err = curl_error($ch); curl_close($ch);
    if ($err) throw new Exception('Mail token request failed: ' . $err);
    $d = json_decode($res, true);
    if (empty($d['access_token'])) throw new Exception('Mail token error: ' . $res);
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
function mail_api($method, $path, $body = null) {
    $token = mail_access_token();
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
function mail_primary_account() {
    [$d, $code] = mail_api('GET', '/api/accounts');
    if ($code >= 400) throw new Exception('Could not read Mail accounts (' . $code . ').');
    $acc = $d['data'][0] ?? null;
    if (!$acc) throw new Exception('No Zoho Mail account found on this login.');
    $from = $acc['primaryEmailAddress']
        ?? ($acc['sendMailDetails'][0]['fromAddress'] ?? ($acc['mailboxAddress'] ?? ''));
    return ['accountId' => $acc['accountId'] ?? '', 'from' => $from];
}

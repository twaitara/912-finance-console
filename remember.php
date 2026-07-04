<?php
/* remember.php — file-backed "remember me" tokens for persistent mobile login.
   Only a SHA-256 hash of each token is stored; the raw token lives only in the
   user's cookie. Tokens carry the session facts needed to re-auth and expire. */

function _remember_file() {
    $d = __DIR__ . '/data';
    if (!is_dir($d)) @mkdir($d, 0775, true);
    return $d . '/remember_tokens.json';
}
function _remember_load() {
    $f = _remember_file();
    if (is_file($f)) { $j = json_decode(@file_get_contents($f), true); if (is_array($j)) return $j; }
    return [];
}
function _remember_save($a) { @file_put_contents(_remember_file(), json_encode($a)); }

/* Issue a new token for a signed-in user. Returns the raw token (store in cookie). */
function remember_issue($data, $days = 90) {
    $token = bin2hex(random_bytes(32));
    $h     = hash('sha256', $token);
    $now   = time();
    $all   = _remember_load();
    foreach ($all as $k => $v) { if (($v['exp'] ?? 0) < $now) unset($all[$k]); }   // prune expired
    $all[$h] = [
        'user'     => (string)($data['user'] ?? ''),
        'email'    => (string)($data['email'] ?? ''),
        'is_admin' => (int)($data['is_admin'] ?? 0) ? 1 : 0,
        'tabs'     => (string)($data['tabs'] ?? ''),
        'exp'      => $now + $days * 86400,
    ];
    _remember_save($all);
    return $token;
}

/* Look up a raw token → its stored session facts, or null if missing/expired. */
function remember_lookup($token) {
    if (!$token) return null;
    $h   = hash('sha256', $token);
    $all = _remember_load();
    $v   = $all[$h] ?? null;
    if (!$v) return null;
    if (($v['exp'] ?? 0) < time()) { unset($all[$h]); _remember_save($all); return null; }
    return $v;
}

/* Invalidate a token (on logout). */
function remember_forget($token) {
    if (!$token) return;
    $h   = hash('sha256', $token);
    $all = _remember_load();
    if (isset($all[$h])) { unset($all[$h]); _remember_save($all); }
}

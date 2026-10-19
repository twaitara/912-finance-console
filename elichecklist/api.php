<?php
/**
 * Event Checklist — shared state API (vanilla PHP, flat-file store, no DB).
 *
 * Everyone who opens the checklist signs in with a name. Ticks are shared:
 * when one person marks an item done it is recorded with their name and the
 * time, and every other user sees it. State lives in data/state.json, written
 * atomically under an exclusive lock. The data/ folder is denied to the web.
 *
 * Actions (GET ?action= or JSON body {action:...}):
 *   state                      -> current shared state
 *   join   {user}              -> register / refresh a user, returns state
 *   toggle {user,item,done}    -> set or clear one item (attributed), returns state
 *   meta   {user,field,value}  -> set a shared event-header field, returns state
 */

declare(strict_types=1);
require __DIR__ . '/checklist_data.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

const USER_MAX   = 40;
const VALUE_MAX  = 120;
const USERS_MAX  = 500;
const ONLINE_SECS = 90;   // a user is "online" if seen within this window

$DATA_DIR = __DIR__ . '/data';
$STATE    = $DATA_DIR . '/state.json';

function fail(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

/** Read the request: JSON body if present, else GET/POST params. */
function input(): array {
    $raw = file_get_contents('php://input');
    if ($raw !== '' && $raw !== false) {
        $j = json_decode($raw, true);
        if (is_array($j)) return $j;
    }
    return $_GET + $_POST;
}

function clean_user(string $name): string {
    $name = trim(preg_replace('/[\x00-\x1f\x7f]+/u', ' ', $name));
    $name = preg_replace('/\s+/u', ' ', $name);
    if (function_exists('mb_substr')) $name = mb_substr($name, 0, USER_MAX);
    else $name = substr($name, 0, USER_MAX);
    return $name;
}

function user_key(string $name): string {
    $k = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
    return $k;
}

function default_state(): array {
    return ['meta' => ['name' => '', 'date' => '', 'venue' => '', 'pax' => '180'],
            'items' => new stdClass(), 'users' => new stdClass(), 'rev' => 0];
}

function load_state(string $file): array {
    if (!is_file($file)) return json_decode(json_encode(default_state()), true);
    $raw = file_get_contents($file);
    $s = json_decode($raw ?: '', true);
    if (!is_array($s)) $s = json_decode(json_encode(default_state()), true);
    $s += ['meta' => [], 'items' => [], 'users' => [], 'rev' => 0];
    $s['meta'] += ['name' => '', 'date' => '', 'venue' => '', 'pax' => '180'];
    return $s;
}

/** Shape the state for the client: online flags, sorted users, counts. */
function public_state(array $s): array {
    $now = time();
    $users = [];
    foreach (($s['users'] ?? []) as $k => $u) {
        $users[] = [
            'name'   => $u['name'] ?? $k,
            'seen'   => $u['seen'] ?? 0,
            'online' => ($now - (int)($u['seen'] ?? 0)) <= ONLINE_SECS,
        ];
    }
    usort($users, fn($a, $b) => $b['seen'] <=> $a['seen']);
    return [
        'meta'  => $s['meta'],
        'items' => (object)($s['items'] ?? []),
        'users' => $users,
        'rev'   => (int)($s['rev'] ?? 0),
        'now'   => $now,
    ];
}

/**
 * Exclusive read-modify-write. $fn receives the state array by reference and
 * mutates it; the result is written atomically. Returns the written state.
 */
function with_lock(string $dir, string $file, callable $fn): array {
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $lock = $dir . '/.lock';
    $fh = fopen($lock, 'c');
    if (!$fh) fail('store unavailable (lock)', 500);
    if (!flock($fh, LOCK_EX)) { fclose($fh); fail('store busy', 503); }
    try {
        $s = load_state($file);
        $fn($s);
        $tmp = $file . '.tmp';
        $bytes = file_put_contents($tmp, json_encode($s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        if ($bytes === false || !@rename($tmp, $file)) { @unlink($tmp); fail('store write failed', 500); }
        return $s;
    } finally {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}

function touch_user(array &$s, string $name): void {
    $key = user_key($name);
    if (!isset($s['users'][$key])) {
        if (count($s['users']) >= USERS_MAX) return;
        $s['users'][$key] = ['name' => $name, 'joined' => time()];
    }
    $s['users'][$key]['name'] = $name;   // keep latest capitalisation
    $s['users'][$key]['seen'] = time();
}

/* ------------------------------------------------------------------ routing */

$in     = input();
$action = (string)($in['action'] ?? 'state');

if ($action === 'state') {
    // A read, but also refresh the caller's "seen" if they pass a name (keeps them online).
    $user = clean_user((string)($in['user'] ?? ''));
    if ($user !== '') {
        $s = with_lock($DATA_DIR, $STATE, function (array &$s) use ($user) { touch_user($s, $user); $s['rev']++; });
    } else {
        $s = load_state($STATE);
    }
    echo json_encode(['ok' => true, 'state' => public_state($s)]);
    exit;
}

if ($action === 'join') {
    $user = clean_user((string)($in['user'] ?? ''));
    if ($user === '') fail('Please enter your name.');
    $s = with_lock($DATA_DIR, $STATE, function (array &$s) use ($user) { touch_user($s, $user); $s['rev']++; });
    echo json_encode(['ok' => true, 'state' => public_state($s)]);
    exit;
}

if ($action === 'toggle') {
    $user = clean_user((string)($in['user'] ?? ''));
    $item = (string)($in['item'] ?? '');
    $done = filter_var($in['done'] ?? false, FILTER_VALIDATE_BOOLEAN);
    if ($user === '') fail('Sign in first.');
    $valid = checklist_valid_ids();
    if (!isset($valid[$item])) fail('Unknown item.');
    $s = with_lock($DATA_DIR, $STATE, function (array &$s) use ($user, $item, $done) {
        touch_user($s, $user);
        if ($done) $s['items'][$item] = ['by' => $user, 'at' => time()];
        else unset($s['items'][$item]);
        $s['rev']++;
    });
    echo json_encode(['ok' => true, 'state' => public_state($s)]);
    exit;
}

if ($action === 'meta') {
    $user  = clean_user((string)($in['user'] ?? ''));
    $field = (string)($in['field'] ?? '');
    $value = (string)($in['value'] ?? '');
    if ($user === '') fail('Sign in first.');
    if (!in_array($field, $META_FIELDS, true)) fail('Unknown field.');
    $value = trim(preg_replace('/[\x00-\x1f\x7f]+/u', ' ', $value));
    $value = function_exists('mb_substr') ? mb_substr($value, 0, VALUE_MAX) : substr($value, 0, VALUE_MAX);
    $s = with_lock($DATA_DIR, $STATE, function (array &$s) use ($user, $field, $value) {
        touch_user($s, $user);
        $s['meta'][$field] = $value;
        $s['rev']++;
    });
    echo json_encode(['ok' => true, 'state' => public_state($s)]);
    exit;
}

if ($action === 'leave') {
    // Complete logout: remove the user from the shared roster.
    $user = clean_user((string)($in['user'] ?? ''));
    if ($user !== '') {
        with_lock($DATA_DIR, $STATE, function (array &$s) use ($user) {
            $k = user_key($user);
            if (isset($s['users'][$k])) { unset($s['users'][$k]); $s['rev']++; }
        });
    }
    echo json_encode(['ok' => true]);
    exit;
}

fail('Unknown action.');

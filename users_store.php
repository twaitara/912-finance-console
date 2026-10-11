<?php
/* Multi-user + per-tab permissions. Users live in MySQL (app_users).
   The config app_password remains the ADMIN master login (full access). */

function users_table(PDO $pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS app_users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(80) NOT NULL UNIQUE,
        pass_hash VARCHAR(255) NOT NULL,
        email VARCHAR(190) DEFAULT '',
        tabs TEXT,
        is_admin TINYINT NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    try { $pdo->exec("ALTER TABLE app_users ADD COLUMN email VARCHAR(190) DEFAULT '' AFTER pass_hash"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE app_users ADD COLUMN disabled TINYINT NOT NULL DEFAULT 0 AFTER is_admin"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE app_users ADD COLUMN pin_hash VARCHAR(255) DEFAULT '' AFTER pass_hash"); } catch (Exception $e) {}
}

/* find a user by username or email (no password check) — used by the PIN login flow. */
function user_find(PDO $pdo, $username) {
    users_table($pdo);
    $id = trim($username);
    if ($id === '') return false;
    $st = $pdo->prepare("SELECT * FROM app_users WHERE username=? OR (email<>'' AND LOWER(email)=LOWER(?)) ORDER BY (username=?) DESC LIMIT 1");
    $st->execute([$id, $id, $id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: false;
}

/* find an active user whose PIN matches (PIN-only login). PINs are unique (enforced in user_set_pin). */
function user_find_by_pin(PDO $pdo, $pin) {
    users_table($pdo);
    if (!preg_match('/^\d{4,6}$/', (string)$pin)) return false;
    foreach ($pdo->query("SELECT * FROM app_users WHERE pin_hash<>'' AND disabled=0")->fetchAll(PDO::FETCH_ASSOC) as $u) {
        if (password_verify((string)$pin, $u['pin_hash'])) return $u;
    }
    return false;
}

/* set / change a user's PIN (hashed). 4–6 digits, unique across users.
   Returns true | 'format' | 'dup'. */
function user_set_pin(PDO $pdo, $username, $pin) {
    users_table($pdo);
    if (!preg_match('/^\d{4,6}$/', (string)$pin)) return 'format';
    foreach ($pdo->query("SELECT username,pin_hash FROM app_users WHERE pin_hash<>''")->fetchAll(PDO::FETCH_ASSOC) as $u) {
        if ($u['username'] !== trim($username) && password_verify((string)$pin, $u['pin_hash'])) return 'dup';
    }
    $st = $pdo->prepare("UPDATE app_users SET pin_hash=? WHERE username=?");
    $st->execute([password_hash($pin, PASSWORD_DEFAULT), trim($username)]);
    return true;
}

/* Canonical permissionable tabs: key => label. Keep in sync with the nav. */
function users_all_tabs() {
    return [
        'dash'     => 'Dashboard',
        'deploy'   => 'Deploy',
        'ledger'   => 'Ledger',
        'loans'    => 'Loans',
        'growth'   => 'Growth',
        'etr'      => 'ETR Check',
        'invrep'   => 'Invoices',
        'recv'     => 'Receivables',
        'quotes'   => 'Quotes',
        'newquote' => 'New Quote',
        'myquotes' => 'My Quotes',
        'jobcards' => 'Job Cards',
        'projects' => 'Projects',
        'costcap'  => 'Capture Costs',
        'emails'   => 'Emails',
        'todo'     => 'To-Do',
        'settings' => 'Settings',
        'clientaccess' => 'Client Access',
        'activity' => 'Activity',
        'audrey'   => 'Audrey Reports',
        'taskboard'=> 'Task Board',
    ];
}

/* Keep only valid tab keys, return comma string. */
function users_clean_tabs($tabs) {
    $valid = array_keys(users_all_tabs());
    if (is_string($tabs)) $tabs = explode(',', $tabs);
    $tabs = is_array($tabs) ? $tabs : [];
    $out = [];
    foreach ($tabs as $t) { $t = trim($t); if ($t !== '' && in_array($t, $valid, true) && !in_array($t, $out, true)) $out[] = $t; }
    return implode(',', $out);
}

function user_authenticate(PDO $pdo, $username, $password) {
    users_table($pdo);
    $id = trim($username);
    if ($id === '') return false;
    // match by username OR email (email match needs a non-empty value); prefer an exact username
    $st = $pdo->prepare("SELECT * FROM app_users WHERE username=? OR (email<>'' AND LOWER(email)=LOWER(?)) ORDER BY (username=?) DESC LIMIT 1");
    $st->execute([$id, $id, $id]);
    $u = $st->fetch(PDO::FETCH_ASSOC);
    if (!$u) return false;
    if (!empty($u['disabled'])) return false;            // disabled users cannot sign in
    if (!password_verify($password, $u['pass_hash'])) return false;
    return $u;
}

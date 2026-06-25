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
}

/* Canonical permissionable tabs: key => label. Keep in sync with the nav. */
function users_all_tabs() {
    return [
        'dash'     => 'Dashboard',
        'deploy'   => 'Deploy',
        'ledger'   => 'Ledger',
        'loans'    => 'Loans',
        'growth'   => 'Growth',
        'report'   => 'Profit Report',
        'etr'      => 'ETR Check',
        'invrep'   => 'Invoices',
        'quotes'   => 'Quotes',
        'emails'   => 'Emails',
        'todo'     => 'To-Do',
        'settings' => 'Settings',
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
    $st = $pdo->prepare("SELECT * FROM app_users WHERE username=?");
    $st->execute([trim($username)]);
    $u = $st->fetch(PDO::FETCH_ASSOC);
    if (!$u) return false;
    if (!password_verify($password, $u['pass_hash'])) return false;
    return $u;
}

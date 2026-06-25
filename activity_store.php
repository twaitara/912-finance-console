<?php
/* activity_store.php — lightweight activity/audit log shared by all endpoints. */

function activity_table(PDO $pdo) {
    static $done = false;
    if ($done) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS activity_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(80) DEFAULT '',
        action VARCHAR(60) DEFAULT '',
        detail VARCHAR(255) DEFAULT '',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

/* Record one activity. Never throws — logging must not break the action. */
function activity_log(PDO $pdo, $user, $action, $detail = '') {
    try {
        activity_table($pdo);
        $st = $pdo->prepare("INSERT INTO activity_log (username, action, detail) VALUES (?,?,?)");
        $st->execute([substr((string)$user, 0, 80), substr((string)$action, 0, 60), substr((string)$detail, 0, 255)]);
    } catch (Exception $e) { /* swallow */ }
}

/* Convenience: log using the current session user. */
function activity_log_session(PDO $pdo, $action, $detail = '') {
    activity_log($pdo, $_SESSION['user'] ?? 'unknown', $action, $detail);
}

<?php
require_once __DIR__ . '/../errors.php';
/* api/activity.php — admin-only activity/audit log viewer.
   GET ?limit=300 [&q=search] -> recent activity, newest first. */
session_start();
header('Content-Type: application/json; charset=utf-8');
if (empty($_SESSION['auth']) || empty($_SESSION['is_admin'])) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Admins only.']); exit; }
require __DIR__ . '/../db.php';
require_once __DIR__ . '/../activity_store.php';

try {
    $pdo = db();
    activity_table($pdo);
    $limit = (int)($_GET['limit'] ?? 300); if ($limit < 1 || $limit > 2000) $limit = 300;
    $q = trim((string)($_GET['q'] ?? ''));

    if ($q !== '') {
        $st = $pdo->prepare("SELECT username, action, detail, created_at FROM activity_log
                             WHERE username LIKE ? OR action LIKE ? OR detail LIKE ?
                             ORDER BY id DESC LIMIT $limit");
        $like = '%' . $q . '%';
        $st->execute([$like, $like, $like]);
    } else {
        $st = $pdo->query("SELECT username, action, detail, created_at FROM activity_log ORDER BY id DESC LIMIT $limit");
    }
    echo json_encode(['ok'=>true, 'log'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
} catch (Exception $e) {
    echo api_fail($e);
}

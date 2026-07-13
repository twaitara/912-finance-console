<?php
require_once __DIR__ . '/../errors.php';
/* api/task_users.php — people available to assign tasks to = the users created in the system. */
session_start();
require_once __DIR__ . '/../csrf.php'; csrf_guard();
header('Content-Type: application/json; charset=utf-8');
if (empty($_SESSION['auth'])) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Not signed in','users'=>[]]); exit; }
require __DIR__ . '/../db.php';
require_once __DIR__ . '/../users_store.php';
try {
    $pdo = db();
    users_table($pdo);
    $rows = $pdo->query("SELECT username,email FROM app_users ORDER BY username ASC")->fetchAll(PDO::FETCH_ASSOC);
    $users = [];
    foreach ($rows as $r) { $users[] = ['name'=>$r['username'], 'email'=>($r['email'] ?? '')]; }
    echo json_encode(['ok'=>true, 'users'=>$users]);
} catch (Exception $e) {
    echo api_fail($e, ['users'=>[]]);
}

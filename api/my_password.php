<?php
require_once __DIR__ . '/../errors.php';
/* api/my_password.php — let the signed-in user change THEIR OWN password.
   POST JSON: {current, new}. Verifies the current password first. */
session_start();
require_once __DIR__ . '/../csrf.php'; csrf_guard();
header('Content-Type: application/json; charset=utf-8');
if (empty($_SESSION['auth'])) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Not signed in.']); exit; }
require __DIR__ . '/../db.php';
require_once __DIR__ . '/../users_store.php';

try {
    $in = json_decode(file_get_contents('php://input'), true); if (!is_array($in)) $in = $_POST;
    $cur = (string)($in['current'] ?? '');
    $new = (string)($in['new'] ?? '');
    if ($new === '' || strlen($new) < 6) throw new Exception('New password must be at least 6 characters.');

    $pdo = db();
    users_table($pdo);
    $me = $_SESSION['user'] ?? '';

    $st = $pdo->prepare("SELECT * FROM app_users WHERE username=?"); $st->execute([$me]);
    $u = $st->fetch(PDO::FETCH_ASSOC);
    if (!$u) throw new Exception('Your account password is set in the app config and cannot be changed here.');
    if (!empty($u['disabled'])) throw new Exception('Your account is disabled.');
    if (!password_verify($cur, $u['pass_hash'])) throw new Exception('Your current password is incorrect.');

    $pdo->prepare("UPDATE app_users SET pass_hash=? WHERE id=?")
        ->execute([password_hash($new, PASSWORD_DEFAULT), (int)$u['id']]);

    echo json_encode(['ok'=>true]);
} catch (Exception $e) {
    http_response_code(400);
    echo api_fail($e);
}

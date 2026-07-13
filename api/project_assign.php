<?php
/* api/project_assign.php — assign which system users may view/cost a project (admin only).
   Actions (POST JSON):
     {action:'get', quote_id}                 -> {ok, users:[{username,email,assigned}], assignees[]}
     {action:'set', quote_id, usernames:[...]} -> {ok, assignees[]} */
session_start();
header('Content-Type: application/json; charset=utf-8');
if (empty($_SESSION['auth']) || empty($_SESSION['is_admin'])) {
    http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Admins only.']); exit;
}
require __DIR__ . '/../db.php';
require __DIR__ . '/../project_costs.php';   // pa_table / pa_for_quote

try {
    $pdo = db();
    pa_table($pdo);
    $me = $_SESSION['user'] ?? '';
    $in = json_decode(file_get_contents('php://input'), true); if (!is_array($in)) $in = $_POST;
    $action = $in['action'] ?? 'get';
    $qid = (int)($in['quote_id'] ?? 0); if ($qid <= 0) throw new Exception('No project.');

    // assignable = active non-admin accounts (admins already see everything)
    $valid = [];
    try {
        $us = $pdo->query("SELECT username, email FROM app_users WHERE is_admin=0 AND (disabled IS NULL OR disabled=0) ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $us = []; }
    foreach ($us as $u) $valid[$u['username']] = true;

    if ($action === 'set') {
        $names = array_values(array_unique(array_filter(array_map('strval', (array)($in['usernames'] ?? [])), function($n) use ($valid){ return isset($valid[trim($n)]); })));
        $pdo->prepare("DELETE FROM project_assignees WHERE quote_id=?")->execute([$qid]);
        $ins = $pdo->prepare("INSERT INTO project_assignees (quote_id, username, assigned_by) VALUES (?,?,?)");
        foreach ($names as $n) $ins->execute([$qid, trim($n), $me]);
        require_once __DIR__ . '/../activity_store.php';
        activity_log($pdo, $me, 'assigned project viewers', '#'.$qid.' -> '.($names ? implode(', ', $names) : '(none)'));
        echo json_encode(['ok'=>true, 'assignees'=>pa_for_quote($pdo, $qid)]); exit;
    }

    // get
    $assigned = pa_for_quote($pdo, $qid);
    $set = array_fill_keys($assigned, true);
    $users = array_map(function($u) use ($set){
        return ['username'=>$u['username'], 'email'=>$u['email'], 'assigned'=>isset($set[$u['username']])];
    }, $us);
    echo json_encode(['ok'=>true, 'users'=>$users, 'assignees'=>$assigned]);
} catch (Exception $e) {
    echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
}

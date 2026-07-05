<?php
/* api/ask_convos.php — save / list / open / delete "Ask your books" conversations.
   Admin only. File-backed. Saved chats auto-expire 7 days after their last save. */
session_start();
header('Content-Type: application/json; charset=utf-8');
if (empty($_SESSION['auth']) || empty($_SESSION['is_admin'])) {
    http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Admins only.']); exit;
}

$dir  = __DIR__ . '/../data'; if (!is_dir($dir)) @mkdir($dir, 0775, true);
$file = $dir . '/ask_convos.json';
$TTL  = 7 * 86400;                                  // 7 days

$load = function() use ($file) { if (is_file($file)) { $j = json_decode(@file_get_contents($file), true); if (is_array($j)) return $j; } return []; };
$put  = function($a) use ($file) { @file_put_contents($file, json_encode($a)); };

$all = $load();
$now = time();
$changed = false;
foreach ($all as $id => $c) { if ($now - (int)($c['updated'] ?? 0) > $TTL) { unset($all[$id]); $changed = true; } }  // prune expired
if ($changed) $put($all);

$in     = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $in['action'] ?? ($_GET['action'] ?? 'list');

if ($action === 'save') {
    $msgs = is_array($in['messages'] ?? null) ? $in['messages'] : [];
    $clean = [];
    foreach (array_slice($msgs, 0, 80) as $m) {
        $role = ($m['role'] ?? '') === 'assistant' ? 'assistant' : 'user';
        $txt  = substr((string)($m['content'] ?? ''), 0, 8000);
        if ($txt !== '') $clean[] = ['role'=>$role, 'content'=>$txt];
    }
    if (!$clean) { echo json_encode(['ok'=>false, 'error'=>'Nothing to save yet.']); exit; }
    $id = trim((string)($in['id'] ?? ''));
    if ($id === '' || !isset($all[$id])) $id = 'c' . bin2hex(random_bytes(6));
    $title = trim((string)($in['title'] ?? ''));
    if ($title === '') foreach ($clean as $m) { if ($m['role'] === 'user') { $title = $m['content']; break; } }
    $title   = substr($title !== '' ? $title : 'Conversation', 0, 90);
    $created = isset($all[$id]) ? (int)$all[$id]['created'] : $now;
    $all[$id] = ['id'=>$id, 'title'=>$title, 'messages'=>$clean, 'created'=>$created, 'updated'=>$now];
    if (count($all) > 60) { uasort($all, fn($a, $b) => $b['updated'] <=> $a['updated']); $all = array_slice($all, 0, 60, true); }
    $put($all);
    echo json_encode(['ok'=>true, 'id'=>$id, 'expires'=>$now + $TTL]);
    exit;
}

if ($action === 'get') {
    $id = trim((string)($in['id'] ?? ($_GET['id'] ?? '')));
    if (!isset($all[$id])) { echo json_encode(['ok'=>false, 'error'=>'Not found — it may have expired (chats are kept 7 days).']); exit; }
    echo json_encode(['ok'=>true, 'convo'=>$all[$id]]);
    exit;
}

if ($action === 'delete') {
    $id = trim((string)($in['id'] ?? ''));
    if (isset($all[$id])) { unset($all[$id]); $put($all); }
    echo json_encode(['ok'=>true]);
    exit;
}

// list
$list = [];
foreach ($all as $c) $list[] = ['id'=>$c['id'], 'title'=>$c['title'], 'updated'=>(int)$c['updated'], 'count'=>count($c['messages']), 'expires'=>(int)$c['updated'] + $TTL];
usort($list, fn($a, $b) => $b['updated'] <=> $a['updated']);
echo json_encode(['ok'=>true, 'convos'=>$list]);

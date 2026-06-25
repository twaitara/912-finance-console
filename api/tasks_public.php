<?php
/* api/tasks_public.php — PUBLIC (no password) read + light-write for the shared Task Board.
   Allowed actions only: list, toggle (done/open), note. No create / delete / reassign.
   Writes sync to the SAME tasks the in-app To-Do tab uses. */
header('Content-Type: application/json; charset=utf-8');
require __DIR__ . '/../db.php';

function tp_list(PDO $pdo){
    // tables may not exist yet if To-Do has never been used
    try { $rows = $pdo->query("SELECT id,title,subject,notes,status,created_at,done_at,sent_at FROM tasks ORDER BY (status='done') ASC, id DESC")->fetchAll(PDO::FETCH_ASSOC); }
    catch (Exception $e) { return []; }
    $out = [];
    foreach ($rows as $r) {
        $id = (int)$r['id'];
        $as = [];
        try {
            $st = $pdo->prepare("SELECT name,email FROM task_assignees WHERE task_id=? ORDER BY name,email");
            $st->execute([$id]);
            $as = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {}
        if (!$as && !empty($r['assignee_email'])) $as = [['name'=>$r['assignee_name'] ?? '', 'email'=>$r['assignee_email']]];
        $out[] = [
            'id'         => $id,
            'title'      => $r['title'],
            'subject'    => $r['subject'] ?? '',
            'notes'      => $r['notes'],
            'status'     => $r['status'],
            'created_at' => $r['created_at'] ?? null,
            'done_at'    => $r['done_at'] ?? null,
            'assignees'  => $as,
        ];
    }
    return $out;
}

try {
    $pdo = db();
    $in = json_decode(file_get_contents('php://input'), true);
    if (!is_array($in)) $in = $_POST;
    $action = $in['action'] ?? 'list';

    if ($action === 'list') { echo json_encode(['ok'=>true, 'tasks'=>tp_list($pdo)]); exit; }

    if ($action === 'toggle') {
        $id = (int)($in['id'] ?? 0); if (!$id) throw new Exception('No task.');
        $status = ($in['status'] ?? 'open') === 'done' ? 'done' : 'open';
        $done = $status === 'done' ? date('Y-m-d H:i:s') : null;
        $pdo->prepare("UPDATE tasks SET status=?, done_at=? WHERE id=?")->execute([$status, $done, $id]);
        echo json_encode(['ok'=>true]); exit;
    }

    if ($action === 'note') {
        $id = (int)($in['id'] ?? 0); if (!$id) throw new Exception('No task.');
        $pdo->prepare("UPDATE tasks SET notes=? WHERE id=?")->execute([trim((string)($in['notes'] ?? '')), $id]);
        echo json_encode(['ok'=>true]); exit;
    }

    if ($action === 'subject') {
        $id = (int)($in['id'] ?? 0); if (!$id) throw new Exception('No task.');
        try { $pdo->exec("ALTER TABLE tasks ADD COLUMN subject VARCHAR(255) DEFAULT '' AFTER title"); } catch (Exception $e) {}
        $pdo->prepare("UPDATE tasks SET subject=? WHERE id=?")->execute([trim((string)($in['subject'] ?? '')), $id]);
        echo json_encode(['ok'=>true]); exit;
    }

    if ($action === 'assign') {
        $id = (int)($in['id'] ?? 0); if (!$id) throw new Exception('No task.');
        $name = trim((string)($in['name'] ?? ''));
        $email = trim((string)($in['email'] ?? ''));
        if ($name === '' && $email === '') throw new Exception('No person.');
        if ($name === '') $name = $email;
        try { $pdo->exec("CREATE TABLE IF NOT EXISTS task_assignees (id INT AUTO_INCREMENT PRIMARY KEY, task_id INT NOT NULL, name VARCHAR(190), email VARCHAR(190), ticked TINYINT DEFAULT 1, UNIQUE KEY uniq (task_id,name,email)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Exception $e) {}
        $pdo->prepare("INSERT IGNORE INTO task_assignees (task_id,name,email,ticked) VALUES (?,?,?,1)")->execute([$id, $name, $email]);
        echo json_encode(['ok'=>true]); exit;
    }

    if ($action === 'unassign') {
        $id = (int)($in['id'] ?? 0); if (!$id) throw new Exception('No task.');
        $name = trim((string)($in['name'] ?? ''));
        $email = trim((string)($in['email'] ?? ''));
        $pdo->prepare("DELETE FROM task_assignees WHERE task_id=? AND name=? AND email=?")->execute([$id, $name, $email]);
        echo json_encode(['ok'=>true]); exit;
    }

    throw new Exception('Not allowed.');
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
}

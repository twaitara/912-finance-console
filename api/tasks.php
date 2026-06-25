<?php
/* api/tasks.php — to-do list with MULTIPLE assignees (each tickable).
   Tables (auto-created): tasks, task_assignees.
   Actions (POST JSON):
     {action:'list'}
     {action:'add', title, notes?, source?, source_ref?, assignees?:[{name,email}]}
     {action:'update', id, title?, notes?}
     {action:'toggle', id, status}                 task open|done
     {action:'delete', id}
     {action:'set_assignees', id, assignees:[{name,email}]}   replace the assignee set
     {action:'toggle_assignee', id, email, ticked}            tick/untick one assignee
     {action:'send', id}                            emails only TICKED assignees */
session_start();
header('Content-Type: application/json; charset=utf-8');
if (empty($_SESSION['auth'])) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Not signed in.']); exit; }

require __DIR__ . '/../db.php';

function tk_tables(PDO $pdo){
    $pdo->exec("CREATE TABLE IF NOT EXISTS tasks (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(255) NOT NULL,
        subject VARCHAR(255) DEFAULT '',
        notes TEXT,
        status VARCHAR(20) NOT NULL DEFAULT 'open',
        assignee_name VARCHAR(190),
        assignee_email VARCHAR(190),
        source VARCHAR(40) DEFAULT 'manual',
        source_ref VARCHAR(255),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        done_at TIMESTAMP NULL,
        sent_at TIMESTAMP NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // add subject to pre-existing tables (ignore error if it already exists)
    try { $pdo->exec("ALTER TABLE tasks ADD COLUMN subject VARCHAR(255) DEFAULT '' AFTER title"); } catch (Exception $e) {}
    $pdo->exec("CREATE TABLE IF NOT EXISTS task_assignees (
        id INT AUTO_INCREMENT PRIMARY KEY,
        task_id INT NOT NULL,
        name VARCHAR(190),
        email VARCHAR(190) NOT NULL,
        ticked TINYINT NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_task_email (task_id, email),
        KEY k_task (task_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function tk_assignees(PDO $pdo, $taskId){
    $st = $pdo->prepare("SELECT name,email,ticked FROM task_assignees WHERE task_id=? ORDER BY name,email");
    $st->execute([$taskId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) { $r['ticked'] = (int)$r['ticked'] ? true : false; }
    return $rows;
}

function tk_list(PDO $pdo){
    $rows = $pdo->query("SELECT * FROM tasks ORDER BY (status='done') ASC, id DESC")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['id'] = (int)$r['id'];
        $a = tk_assignees($pdo, $r['id']);
        // legacy: synthesize a single assignee from old columns if none in the new table
        if (!$a && !empty($r['assignee_email'])) {
            $a = [['name'=>$r['assignee_name'] ?: $r['assignee_email'], 'email'=>$r['assignee_email'], 'ticked'=>true]];
        }
        $r['assignees'] = $a;
    }
    return $rows;
}

function tk_set_assignees(PDO $pdo, $taskId, $list){
    // preserve ticked state by email
    $prev = [];
    foreach (tk_assignees($pdo, $taskId) as $p) { $prev[strtolower($p['email'])] = $p['ticked']; }
    $pdo->prepare("DELETE FROM task_assignees WHERE task_id=?")->execute([$taskId]);
    $ins = $pdo->prepare("INSERT IGNORE INTO task_assignees (task_id,name,email,ticked) VALUES (?,?,?,?)");
    foreach ($list as $u) {
        $email = trim($u['email'] ?? '');
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
        $tick = array_key_exists(strtolower($email), $prev) ? ($prev[strtolower($email)] ? 1 : 0) : 1;
        $ins->execute([$taskId, trim($u['name'] ?? ''), $email, $tick]);
    }
}

try {
    $pdo = db(); tk_tables($pdo);
    $in = json_decode(file_get_contents('php://input'), true);
    if (!is_array($in)) $in = $_POST;
    $action = $in['action'] ?? 'list';

    if ($action === 'list') { echo json_encode(['ok'=>true,'tasks'=>tk_list($pdo)]); exit; }

    if ($action === 'add') {
        $title = trim($in['title'] ?? '');
        if ($title === '') throw new Exception('Task needs a title.');
        $st = $pdo->prepare("INSERT INTO tasks (title,subject,notes,source,source_ref) VALUES (?,?,?,?,?)");
        $st->execute([$title, trim($in['subject'] ?? ''), trim($in['notes'] ?? ''), $in['source'] ?? 'manual', $in['source_ref'] ?? '']);
        $id = (int)$pdo->lastInsertId();
        if (!empty($in['assignees']) && is_array($in['assignees'])) tk_set_assignees($pdo, $id, $in['assignees']);
        echo json_encode(['ok'=>true,'id'=>$id,'tasks'=>tk_list($pdo)]); exit;
    }

    if ($action === 'update') {
        $id = (int)($in['id'] ?? 0); if (!$id) throw new Exception('No task id.');
        $fields=[]; $vals=[];
        foreach (['title','subject','notes'] as $f) {
            if (array_key_exists($f, $in)) { $fields[]="`$f`=?"; $vals[]=trim((string)$in[$f]); }
        }
        if (!$fields) throw new Exception('Nothing to update.');
        $vals[]=$id;
        $pdo->prepare("UPDATE tasks SET ".implode(',', $fields)." WHERE id=?")->execute($vals);
        echo json_encode(['ok'=>true,'tasks'=>tk_list($pdo)]); exit;
    }

    if ($action === 'set_assignees') {
        $id = (int)($in['id'] ?? 0); if (!$id) throw new Exception('No task id.');
        tk_set_assignees($pdo, $id, is_array($in['assignees'] ?? null) ? $in['assignees'] : []);
        echo json_encode(['ok'=>true,'tasks'=>tk_list($pdo)]); exit;
    }

    if ($action === 'toggle_assignee') {
        $id = (int)($in['id'] ?? 0); $email = trim($in['email'] ?? '');
        if (!$id || $email==='') throw new Exception('Missing task or email.');
        $tick = !empty($in['ticked']) ? 1 : 0;
        $pdo->prepare("UPDATE task_assignees SET ticked=? WHERE task_id=? AND email=?")->execute([$tick,$id,$email]);
        echo json_encode(['ok'=>true,'tasks'=>tk_list($pdo)]); exit;
    }

    if ($action === 'toggle') {
        $id = (int)($in['id'] ?? 0); if (!$id) throw new Exception('No task id.');
        $status = ($in['status'] ?? 'open') === 'done' ? 'done' : 'open';
        $done = $status === 'done' ? date('Y-m-d H:i:s') : null;
        $pdo->prepare("UPDATE tasks SET status=?, done_at=? WHERE id=?")->execute([$status, $done, $id]);
        echo json_encode(['ok'=>true,'tasks'=>tk_list($pdo)]); exit;
    }

    if ($action === 'delete') {
        $id = (int)($in['id'] ?? 0); if (!$id) throw new Exception('No task id.');
        $pdo->prepare("DELETE FROM tasks WHERE id=?")->execute([$id]);
        $pdo->prepare("DELETE FROM task_assignees WHERE task_id=?")->execute([$id]);
        echo json_encode(['ok'=>true,'tasks'=>tk_list($pdo)]); exit;
    }

    if ($action === 'send') {
        $id = (int)($in['id'] ?? 0); if (!$id) throw new Exception('No task id.');
        $t = $pdo->prepare("SELECT * FROM tasks WHERE id=?"); $t->execute([$id]);
        $task = $t->fetch(PDO::FETCH_ASSOC);
        if (!$task) throw new Exception('Task not found.');

        $all = tk_assignees($pdo, $id);
        if (!$all && !empty($task['assignee_email'])) $all = [['name'=>$task['assignee_name'], 'email'=>$task['assignee_email'], 'ticked'=>true]];
        $ticked = array_values(array_filter($all, fn($a)=>$a['ticked'] && filter_var($a['email'], FILTER_VALIDATE_EMAIL)));
        if (!$ticked) throw new Exception('No ticked assignee with a valid email.');
        $toList = implode(',', array_map(fn($a)=>$a['email'], $ticked));
        $names  = implode(', ', array_map(fn($a)=>$a['name'] ?: $a['email'], $ticked));

        require __DIR__ . '/../mail.php';
        $acc = mail_primary_account();
        $esc = fn($s)=>htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $notes = nl2br($esc($task['notes'] ?? ''));
        $html = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#15202B;max-width:620px">'
              . '<div style="background:#15202B;border-radius:10px 10px 0 0;padding:16px 20px;color:#fff">'
              . '<span style="display:inline-block;background:#F56F00;color:#fff;font-weight:700;padding:6px 9px;border-radius:7px">912</span>'
              . '<span style="font-weight:700;letter-spacing:.5px;margin-left:10px">TASK ASSIGNED</span></div>'
              . '<div style="border:1px solid #E6EAF0;border-top:0;border-radius:0 0 10px 10px;padding:20px">'
              . '<div style="font-size:16px;font-weight:700;margin:0 0 10px">' . $esc($task['title']) . '</div>'
              . ($notes ? '<div style="color:#334;line-height:1.55;margin:0 0 14px">' . $notes . '</div>' : '')
              . '<div style="font-size:12px;color:#64748B">Assigned to ' . $esc($names) . '. Please action and reply when done.</div>'
              . '</div></div>';
        $payload = ['fromAddress'=>$acc['from'], 'toAddress'=>$toList, 'subject'=>'Task assigned: '.$task['title'], 'content'=>$html, 'mailFormat'=>'html'];
        [$d, $code] = mail_api('POST', '/api/accounts/' . rawurlencode($acc['accountId']) . '/messages', $payload);
        if ($code >= 400) {
            $msg = $d['data']['moreInfo'] ?? ($d['data']['errorCode'] ?? ('Mail send error ' . $code));
            throw new Exception('Could not send: ' . $msg);
        }
        $pdo->prepare("UPDATE tasks SET sent_at=? WHERE id=?")->execute([date('Y-m-d H:i:s'), $id]);
        echo json_encode(['ok'=>true,'sent'=>true,'to'=>$toList,'tasks'=>tk_list($pdo)]); exit;
    }

    throw new Exception('Unknown action.');
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
}

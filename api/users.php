<?php
/* api/users.php — admin-only user management (app_users table). */
session_start();
header('Content-Type: application/json; charset=utf-8');
if (empty($_SESSION['auth']) || empty($_SESSION['is_admin'])) {
    http_response_code(403);
    echo json_encode(['ok'=>false, 'error'=>'Admins only.']);
    exit;
}
require __DIR__ . '/../db.php';
require_once __DIR__ . '/../users_store.php';

$action = $_GET['action'] ?? 'list';
$pdo = db();

function users_payload(PDO $pdo) {
    $rows = $pdo->query("SELECT id,username,email,tabs,is_admin FROM app_users ORDER BY is_admin DESC, username ASC")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) { $r['id'] = (int)$r['id']; $r['is_admin'] = (int)$r['is_admin']; }
    return ['ok'=>true, 'users'=>$rows];
}

try {
    users_table($pdo);

    if ($action === 'list') {
        $payload = users_payload($pdo);
        try {
            require_once __DIR__ . '/../calendar_oauth.php';
            $conn = cal_connected_set();
            foreach ($payload['users'] as &$u) { $u['calendar'] = isset($conn[strtolower((string)($u['email'] ?? ''))]); }
            unset($u);
        } catch (Exception $e) {}
        echo json_encode($payload); exit;
    }

    if ($action === 'import_zoho') {   // one-click: pull Zoho org users into app_users
        require __DIR__ . '/../zoho.php';
        $zu = []; $page = 1;
        do {
            [$d, $code] = zoho_api('GET', 'users', null, ['per_page'=>200, 'page'=>$page]);
            if ($code >= 400) throw new Exception($d['message'] ?? 'Zoho error (users).');
            foreach (($d['users'] ?? []) as $u) {
                $em = trim((string)($u['email'] ?? ''));
                $nm = trim((string)($u['name'] ?? $em));
                if ($em === '' || strtolower((string)($u['status'] ?? '')) === 'deleted') continue;
                $zu[] = ['name'=>$nm, 'email'=>$em];
            }
            $more = $d['page_context']['has_more_page'] ?? false; $page++;
        } while ($more && $page <= 10);

        // existing usernames + emails (lowercased) to avoid duplicates
        $have = $pdo->query("SELECT LOWER(username) u, LOWER(email) e FROM app_users")->fetchAll(PDO::FETCH_ASSOC);
        $haveU = []; $haveE = [];
        foreach ($have as $h) { if ($h['u']!=='') $haveU[$h['u']]=1; if ($h['e']!=='') $haveE[$h['e']]=1; }

        $defaultTabs = users_clean_tabs('todo'); // imported staff get To-Do by default
        $created = []; $skipped = 0;
        foreach ($zu as $u) {
            $emLc = strtolower($u['email']);
            if (isset($haveE[$emLc])) { $skipped++; continue; }   // already imported (by email)
            // build a clean unique username from the name (fallback to email local part)
            $base = preg_replace('/[^A-Za-z0-9._-]/', '', str_replace(' ', '.', $u['name']));
            if ($base === '') $base = preg_replace('/[^A-Za-z0-9._-]/', '', explode('@', $u['email'])[0]);
            if ($base === '') $base = 'user';
            $base = substr($base, 0, 70);
            $uname = $base; $n = 1;
            while (isset($haveU[strtolower($uname)])) { $uname = $base . $n; $n++; }
            $pwd = substr(str_shuffle('abcdefghijkmnpqrstuvwxyz23456789'), 0, 8);
            $st = $pdo->prepare("INSERT INTO app_users (username,pass_hash,email,tabs,is_admin) VALUES (?,?,?,?,0)");
            $st->execute([$uname, password_hash($pwd, PASSWORD_DEFAULT), $u['email'], $defaultTabs]);
            $haveU[strtolower($uname)] = 1; $haveE[$emLc] = 1;
            $created[] = ['username'=>$uname, 'email'=>$u['email'], 'password'=>$pwd];
        }
        $payload = users_payload($pdo);
        $payload['imported'] = count($created);
        $payload['skipped']  = $skipped;
        $payload['created']  = $created;   // plaintext one-time passwords for the admin to share
        echo json_encode($payload); exit;
    }

    $in = json_decode(file_get_contents('php://input'), true);
    if (!is_array($in)) $in = $_POST;

    if ($action === 'create') {
        $u = trim((string)($in['username'] ?? ''));
        $p = (string)($in['password'] ?? '');
        if ($u === '' || $p === '') throw new Exception('Username and password are required.');
        if (!preg_match('/^[A-Za-z0-9._-]{2,80}$/', $u)) throw new Exception('Username: 2–80 letters, numbers, . _ - only.');
        $isAdmin = !empty($in['is_admin']) ? 1 : 0;
        $tabs = $isAdmin ? '' : users_clean_tabs($in['tabs'] ?? '');
        $email = substr(trim((string)($in['email'] ?? '')), 0, 190);
        $exists = $pdo->prepare("SELECT COUNT(*) FROM app_users WHERE username=?"); $exists->execute([$u]);
        if ((int)$exists->fetchColumn() > 0) throw new Exception('That username already exists.');
        $st = $pdo->prepare("INSERT INTO app_users (username,pass_hash,email,tabs,is_admin) VALUES (?,?,?,?,?)");
        $st->execute([$u, password_hash($p, PASSWORD_DEFAULT), $email, $tabs, $isAdmin]);
        echo json_encode(users_payload($pdo)); exit;
    }

    if ($action === 'update') {   // change allowed tabs, email (and optionally admin flag)
        $id = (int)($in['id'] ?? 0); if (!$id) throw new Exception('No user.');
        $tabs = users_clean_tabs($in['tabs'] ?? '');
        if (array_key_exists('email', $in)) {
            $pdo->prepare("UPDATE app_users SET email=? WHERE id=?")->execute([substr(trim((string)$in['email']),0,190), $id]);
        }
        if (array_key_exists('is_admin', $in)) {
            $isAdmin = !empty($in['is_admin']) ? 1 : 0;
            $pdo->prepare("UPDATE app_users SET tabs=?, is_admin=? WHERE id=?")->execute([$isAdmin?'':$tabs, $isAdmin, $id]);
        } else if (array_key_exists('tabs', $in)) {
            $pdo->prepare("UPDATE app_users SET tabs=? WHERE id=?")->execute([$tabs, $id]);
        }
        echo json_encode(users_payload($pdo)); exit;
    }

    if ($action === 'passwd') {
        $id = (int)($in['id'] ?? 0); if (!$id) throw new Exception('No user.');
        $p = (string)($in['password'] ?? ''); if ($p === '') throw new Exception('Password required.');
        $pdo->prepare("UPDATE app_users SET pass_hash=? WHERE id=?")->execute([password_hash($p, PASSWORD_DEFAULT), $id]);
        echo json_encode(['ok'=>true]); exit;
    }

    if ($action === 'delete') {
        $id = (int)($in['id'] ?? 0); if (!$id) throw new Exception('No user.');
        $pdo->prepare("DELETE FROM app_users WHERE id=?")->execute([$id]);
        echo json_encode(users_payload($pdo)); exit;
    }

    throw new Exception('Unknown action.');

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
}

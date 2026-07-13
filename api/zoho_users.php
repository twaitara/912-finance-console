<?php
require_once __DIR__ . '/../errors.php';
/* api/zoho_users.php — lists Zoho Books users (for task assignment).
   Returns { ok, users:[{id,name,email,role,status}] }. Cached 6h in data/. */
session_start();
header('Content-Type: application/json; charset=utf-8');
if (empty($_SESSION['auth'])) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Not signed in.']); exit; }

require __DIR__ . '/../zoho.php';

try {
    $cacheDir = __DIR__ . '/../data';
    if (!is_dir($cacheDir)) @mkdir($cacheDir, 0775, true);
    $cacheFile = $cacheDir . '/zoho_users_v1.json';
    $force = isset($_GET['refresh']) && $_GET['refresh'] == '1';
    if (!$force && is_file($cacheFile) && (time() - filemtime($cacheFile) < 21600)) {
        echo json_encode(['ok'=>true, 'users'=>json_decode(file_get_contents($cacheFile), true), 'cached'=>true]);
        exit;
    }

    $users = []; $page = 1;
    do {
        [$d, $code] = zoho_api('GET', 'users', null, ['per_page'=>200, 'page'=>$page]);
        if ($code >= 400) throw new Exception($d['message'] ?? 'Zoho error (users)');
        foreach (($d['users'] ?? []) as $u) {
            $users[] = [
                'id'     => $u['user_id'] ?? '',
                'name'   => $u['name'] ?? ($u['email'] ?? ''),
                'email'  => $u['email'] ?? '',
                'role'   => $u['role_name'] ?? ($u['role'] ?? ''),
                'status' => $u['status'] ?? '',
            ];
        }
        $more = $d['page_context']['has_more_page'] ?? false;
        $page++;
    } while ($more && $page <= 10);

    // active users with an email, sorted by name
    $users = array_values(array_filter($users, fn($u)=>$u['email'] !== '' && strtolower($u['status']) !== 'deleted'));
    usort($users, fn($a,$b)=>strcasecmp($a['name'], $b['name']));

    @file_put_contents($cacheFile, json_encode($users));
    echo json_encode(['ok'=>true, 'users'=>$users, 'cached'=>false]);
} catch (Exception $e) {
    http_response_code(500);
    echo api_fail($e);
}

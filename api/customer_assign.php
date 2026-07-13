<?php
require_once __DIR__ . '/../errors.php';
/* api/customer_assign.php — map Zoho customers to app users (many users per customer).
   Drives who a staff member can raise quotes for. Admin only for changes.
   Tables (auto-created): customer_assignees.
   Actions (POST JSON):
     {action:'list'}                                            -> {assignments:[{id,name,users:[]}], users:[]}
     {action:'set', zoho_customer_id, customer_name, usernames:[]}  replace the user set for a customer
   Also exposes cust_assign_table() so other endpoints can ensure the table exists.

   NOTE: this file is required by customers.php for the helper, so guard the request
   handling behind direct-access only. */

if (!function_exists('cust_assign_table')) {
    function cust_assign_table(PDO $pdo){
        $pdo->exec("CREATE TABLE IF NOT EXISTS customer_assignees (
            id INT AUTO_INCREMENT PRIMARY KEY,
            zoho_customer_id VARCHAR(64) NOT NULL,
            customer_name VARCHAR(190) DEFAULT '',
            username VARCHAR(80) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_cust_user (zoho_customer_id, username)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
}

/* Only run the request handler when this file is the entry point (not when required). */
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'customer_assign.php') {
    session_start();
require_once __DIR__ . '/../csrf.php'; csrf_guard();
    header('Content-Type: application/json; charset=utf-8');
    if (empty($_SESSION['auth'])) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Not signed in.']); exit; }
    require_once __DIR__ . '/../db.php';
    require_once __DIR__ . '/../users_store.php';

    try {
        $pdo = db();
        cust_assign_table($pdo);
        users_table($pdo);

        $in = json_decode(file_get_contents('php://input'), true); if (!is_array($in)) $in = $_POST;
        $action = $in['action'] ?? 'list';

        if ($action === 'list') {
            $rows = $pdo->query("SELECT zoho_customer_id, customer_name, username FROM customer_assignees ORDER BY customer_name, username")->fetchAll(PDO::FETCH_ASSOC);
            $byCust = [];
            foreach ($rows as $r) {
                $id = $r['zoho_customer_id'];
                if (!isset($byCust[$id])) $byCust[$id] = ['id'=>$id, 'name'=>$r['customer_name'], 'users'=>[]];
                $byCust[$id]['users'][] = $r['username'];
            }
            $users = $pdo->query("SELECT username FROM app_users ORDER BY username")->fetchAll(PDO::FETCH_COLUMN);
            echo json_encode(['ok'=>true, 'assignments'=>array_values($byCust), 'users'=>$users]);
            exit;
        }

        if (empty($_SESSION['is_admin'])) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Admins only.']); exit; }

        if ($action === 'set') {
            $id = trim((string)($in['zoho_customer_id'] ?? ''));
            $nm = trim((string)($in['customer_name'] ?? ''));
            if ($id === '') throw new Exception('No customer.');
            $users = array_values(array_unique(array_filter(array_map('trim', (array)($in['usernames'] ?? [])))));
            $pdo->prepare("DELETE FROM customer_assignees WHERE zoho_customer_id=?")->execute([$id]);
            $ins = $pdo->prepare("INSERT IGNORE INTO customer_assignees (zoho_customer_id, customer_name, username) VALUES (?,?,?)");
            foreach ($users as $u) $ins->execute([$id, $nm, $u]);
            echo json_encode(['ok'=>true, 'count'=>count($users)]);
            exit;
        }

        throw new Exception('Unknown action.');
    } catch (Exception $e) {
        echo api_fail($e);
    }
}

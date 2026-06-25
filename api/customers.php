<?php
/* api/customers.php — customers for the quote builder.
   GET ?q=term   -> live search Zoho contacts (customers). Admins search all;
                    staff search only customers assigned to them.
   GET ?mine=1   -> the current user's assigned customers (no Zoho call).
   Returns: {ok, customers:[{id,name,currency}]} */
session_start();
header('Content-Type: application/json; charset=utf-8');
if (empty($_SESSION['auth'])) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Not signed in.','customers'=>[]]); exit; }
require __DIR__ . '/../db.php';
require __DIR__ . '/../zoho.php';
require_once __DIR__ . '/customer_assign.php';   // for cust_assign_table() + helpers (guarded below)

try {
    $pdo = db();
    cust_assign_table($pdo);
    $me    = $_SESSION['user'] ?? '';
    $admin = !empty($_SESSION['is_admin']);

    /* customer ids this user is allowed to quote for (admins: unrestricted) */
    $allowed = null;
    if (!$admin) {
        $st = $pdo->prepare("SELECT zoho_customer_id FROM customer_assignees WHERE username=?");
        $st->execute([$me]);
        $allowed = array_fill_keys(array_column($st->fetchAll(PDO::FETCH_ASSOC), 'zoho_customer_id'), true);
    }

    if (isset($_GET['mine'])) {
        $st = $pdo->prepare("SELECT zoho_customer_id id, customer_name name FROM customer_assignees WHERE username=? ORDER BY customer_name");
        $st->execute([$me]);
        echo json_encode(['ok'=>true, 'customers'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    $q = trim((string)($_GET['q'] ?? ''));
    if (mb_strlen($q) < 2) { echo json_encode(['ok'=>true, 'customers'=>[]]); exit; }

    [$data, $code] = zoho_api('GET', 'contacts', null, [
        'contact_name_contains' => $q,
        'contact_type'          => 'customer',
        'per_page'              => 50,
    ]);
    if ($code >= 400) throw new Exception($data['message'] ?? 'Zoho error (contacts).');

    $out = [];
    foreach (($data['contacts'] ?? []) as $c) {
        $id = (string)($c['contact_id'] ?? '');
        if ($id === '') continue;
        if ($allowed !== null && !isset($allowed[$id])) continue;   // staff: only their customers
        $out[] = [
            'id'       => $id,
            'name'     => $c['contact_name'] ?? ($c['company_name'] ?? '(unnamed)'),
            'currency' => $c['currency_code'] ?? 'KES',
        ];
    }
    echo json_encode(['ok'=>true, 'customers'=>$out]);
} catch (Exception $e) {
    echo json_encode(['ok'=>false, 'error'=>$e->getMessage(), 'customers'=>[]]);
}

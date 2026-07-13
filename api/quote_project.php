<?php
/* api/quote_project.php — turn an agreed quote into a PROJECT (admin only).
   Sets status='project' locally so actual costs can be captured against it before
   billing. The Zoho estimate is untouched (project is an app-side stage).
   POST JSON: {id} */
session_start();
header('Content-Type: application/json; charset=utf-8');
if (empty($_SESSION['auth']) || empty($_SESSION['is_admin'])) {
    http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Admins only.']); exit;
}
require __DIR__ . '/../db.php';

try {
    $pdo = db();
    $in = json_decode(file_get_contents('php://input'), true); if (!is_array($in)) $in = $_POST;
    $id = (int)($in['id'] ?? 0); if ($id <= 0) throw new Exception('No quote.');

    $st = $pdo->prepare("SELECT * FROM quotes WHERE id=?"); $st->execute([$id]);
    $q = $st->fetch(PDO::FETCH_ASSOC);
    if (!$q) throw new Exception('Quote not found.');
    if (empty($q['zoho_estimate_id'])) throw new Exception('Push the quote to Zoho first.');
    if (!in_array($q['status'], ['approved','sent','accepted'], true)) {
        throw new Exception('Only an approved quote can be turned into a project.');
    }

    $pdo->prepare("UPDATE quotes SET status='project' WHERE id=?")->execute([$id]);

    require_once __DIR__ . '/../activity_store.php';
    activity_log($pdo, $_SESSION['user'] ?? '', 'started project', ($q['zoho_estimate_number'] ?: ('#'.$id)) . ' · ' . ($q['customer_name'] ?? ''));
    echo json_encode(['ok'=>true, 'status'=>'project']);
} catch (Exception $e) {
    echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
}

<?php
require_once __DIR__ . '/../errors.php';
/* api/quote_approve.php — approve a pending quote from inside the app (admin/owner only).
   Calls Zoho's estimate approve action, so it mirrors approving in Zoho Books.
   POST JSON: {id} */
session_start();
require_once __DIR__ . '/../csrf.php'; csrf_guard();
header('Content-Type: application/json; charset=utf-8');
if (empty($_SESSION['auth'])) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Not signed in.']); exit; }
if (empty($_SESSION['is_admin'])) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Only the owner can approve quotes.']); exit; }
require __DIR__ . '/../db.php';
require __DIR__ . '/../zoho.php';
@set_time_limit(60);

try {
    $pdo = db();
    $in = json_decode(file_get_contents('php://input'), true); if (!is_array($in)) $in = $_POST;
    $id = (int)($in['id'] ?? 0); if ($id <= 0) throw new Exception('No quote.');

    $st = $pdo->prepare("SELECT * FROM quotes WHERE id=?"); $st->execute([$id]);
    $q = $st->fetch(PDO::FETCH_ASSOC);
    if (!$q) throw new Exception('Quote not found.');
    if (empty($q['zoho_estimate_id'])) throw new Exception('This quote is not in Zoho yet.');
    if (!in_array($q['status'], ['pending_approval', 'draft'], true)) {
        throw new Exception('Only a pending quote can be approved (status: ' . $q['status'] . ').');
    }

    [$d, $c] = zoho_api('POST', 'estimates/' . rawurlencode($q['zoho_estimate_id']) . '/approve');
    if ($c >= 400) {
        throw new Exception($d['message'] ?? 'Zoho rejected the approval (is estimate approval enabled in Zoho Books?).');
    }
    $status = (string)($d['estimate']['status'] ?? 'approved');

    $pdo->prepare("UPDATE quotes SET status=?, last_synced_at=NOW() WHERE id=?")->execute([$status, $id]);
    require_once __DIR__ . '/../activity_store.php';
    activity_log_session($pdo, 'approved quote', ($q['zoho_estimate_number'] ?: ('#'.$id)) . ' for ' . ($q['customer_name'] ?? ''));
    echo json_encode(['ok'=>true, 'status'=>$status, 'number'=>$q['zoho_estimate_number']]);
} catch (Exception $e) {
    echo api_fail($e);
}

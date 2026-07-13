<?php
require_once __DIR__ . '/../errors.php';
/* api/quote_decline.php — decline/reject a pending quote (admin/owner only).
   Records the decline in the app, and best-effort marks the Zoho estimate declined
   (Zoho's approval flow has no guaranteed reject endpoint, so this may not update Zoho).
   POST JSON: {id} */
session_start();
require_once __DIR__ . '/../csrf.php'; csrf_guard();
header('Content-Type: application/json; charset=utf-8');
if (empty($_SESSION['auth'])) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Not signed in.']); exit; }
if (empty($_SESSION['is_admin'])) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Only the owner can decline quotes.']); exit; }
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
    if (!in_array($q['status'], ['pending_approval', 'draft', 'approved'], true)) {
        throw new Exception('Only a pending or approved quote can be declined (status: ' . $q['status'] . ').');
    }

    // best-effort: tell Zoho the estimate was declined (endpoint may not exist on all orgs)
    $zohoUpdated = false;
    if (!empty($q['zoho_estimate_id'])) {
        [$d, $c] = zoho_api('POST', 'estimates/' . rawurlencode($q['zoho_estimate_id']) . '/status/declined');
        $zohoUpdated = ($c < 400);
    }

    $pdo->prepare("UPDATE quotes SET status='declined', last_synced_at=NOW() WHERE id=?")->execute([$id]);
    require_once __DIR__ . '/../activity_store.php';
    activity_log_session($pdo, 'declined quote', ($q['zoho_estimate_number'] ?: ('#'.$id)) . ' for ' . ($q['customer_name'] ?? ''));
    echo json_encode(['ok'=>true, 'status'=>'declined', 'zohoUpdated'=>$zohoUpdated, 'number'=>$q['zoho_estimate_number']]);
} catch (Exception $e) {
    echo api_fail($e);
}

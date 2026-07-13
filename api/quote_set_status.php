<?php
require_once __DIR__ . '/../errors.php';
/* api/quote_set_status.php — admin/owner: change a quote's status.
   For approved/declined/sent it drives the matching Zoho action so it sticks;
   other statuses are set locally (a manual override).
   POST JSON: {id, status} */
session_start();
require_once __DIR__ . '/../csrf.php'; csrf_guard();
header('Content-Type: application/json; charset=utf-8');
if (empty($_SESSION['auth'])) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Not signed in.']); exit; }
if (empty($_SESSION['is_admin'])) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Only the owner can change status.']); exit; }
require __DIR__ . '/../db.php';
require __DIR__ . '/../zoho.php';
@set_time_limit(60);

$ALLOWED = ['draft','pending_approval','approved','declined','sent','accepted','invoiced','expired'];

try {
    $pdo = db();
    $in = json_decode(file_get_contents('php://input'), true); if (!is_array($in)) $in = $_POST;
    $id = (int)($in['id'] ?? 0); if ($id <= 0) throw new Exception('No quote.');
    $status = (string)($in['status'] ?? '');
    if (!in_array($status, $ALLOWED, true)) throw new Exception('Unknown status.');

    $st = $pdo->prepare("SELECT * FROM quotes WHERE id=?"); $st->execute([$id]);
    $q = $st->fetch(PDO::FETCH_ASSOC);
    if (!$q) throw new Exception('Quote not found.');

    $note = '';
    if (!empty($q['zoho_estimate_id'])) {
        $estId = rawurlencode($q['zoho_estimate_id']);
        if ($status === 'approved' && in_array($q['status'], ['pending_approval','draft'], true)) {
            [$d,$c] = zoho_api('POST', 'estimates/' . $estId . '/approve');
            if ($c >= 400) $note = 'Set locally; Zoho approve failed (' . ($d['message'] ?? ('HTTP '.$c)) . ').';
        } elseif ($status === 'declined') {
            [$d,$c] = zoho_api('POST', 'estimates/' . $estId . '/status/declined');
            if ($c >= 400) $note = 'Set locally; not reflected in Zoho.';
        } elseif ($status === 'sent') {
            [$d,$c] = zoho_api('POST', 'estimates/' . $estId . '/status/sent');
            if ($c >= 400) $note = 'Set locally; not reflected in Zoho.';
        } else {
            $note = 'Set locally (Zoho status unchanged — a later sync may revert it).';
        }
    }

    $pdo->prepare("UPDATE quotes SET status=?, last_synced_at=NOW() WHERE id=?")->execute([$status, $id]);
    require_once __DIR__ . '/../activity_store.php';
    activity_log_session($pdo, 'changed quote status', ($q['zoho_estimate_number'] ?: ('#'.$id)) . ' → ' . $status);
    echo json_encode(['ok'=>true, 'status'=>$status, 'note'=>$note]);
} catch (Exception $e) {
    echo api_fail($e);
}

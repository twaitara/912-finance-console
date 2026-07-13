<?php
/* api/quote_sync.php — refresh the Zoho status of pushed quotes so staff see
   whether their quote was approved / declined. Reads each linked estimate from Zoho
   and updates the local status. In-app only (no emails).
   POST JSON: {id?}   id given -> sync that one; omitted -> sync all of the user's pushed quotes.
   Returns: {ok, quotes:[...]} (the rows that were synced, with fresh status). */
session_start();
header('Content-Type: application/json; charset=utf-8');
if (empty($_SESSION['auth'])) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Not signed in.']); exit; }
require __DIR__ . '/../db.php';
require __DIR__ . '/../zoho.php';
@set_time_limit(90);

try {
    $pdo = db();
    $me    = $_SESSION['user'] ?? '';
    $admin = !empty($_SESSION['is_admin']);

    $in = json_decode(file_get_contents('php://input'), true); if (!is_array($in)) $in = $_POST;
    $one = (int)($in['id'] ?? 0);

    if ($one > 0) {
        $st = $pdo->prepare("SELECT * FROM quotes WHERE id=?"); $st->execute([$one]);
        $rows = array_filter([$st->fetch(PDO::FETCH_ASSOC)]);
    } elseif ($admin) {
        $rows = $pdo->query("SELECT * FROM quotes WHERE zoho_estimate_id<>'' ")->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $st = $pdo->prepare("SELECT * FROM quotes WHERE zoho_estimate_id<>'' AND created_by=?");
        $st->execute([$me]); $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    }

    $updated = [];
    foreach ($rows as $q) {
        if (!$admin && $q['created_by'] !== $me) continue;
        if (empty($q['zoho_estimate_id'])) continue;
        [$d, $c] = zoho_api('GET', 'estimates/' . rawurlencode($q['zoho_estimate_id']));
        if ($c >= 400 || empty($d['estimate'])) continue;
        $status = (string)($d['estimate']['status'] ?? $q['status']);
        // Don't let the estimate's own Zoho status overwrite our local lifecycle states:
        //  - invoiced stays invoiced
        //  - a project (converted to a job) stays 'project' until it is billed
        if (!empty($q['zoho_invoice_id']) || !empty($q['zoho_invoice_number'])) $status = 'invoiced';
        elseif (!empty($q['is_project']) || $q['status'] === 'project')          $status = 'project';
        $pdo->prepare("UPDATE quotes SET status=?, zoho_estimate_number=?, last_synced_at=NOW() WHERE id=?")
            ->execute([$status, (string)($d['estimate']['estimate_number'] ?? $q['zoho_estimate_number']), (int)$q['id']]);
        $updated[] = ['id'=>(int)$q['id'], 'status'=>$status];
    }

    echo json_encode(['ok'=>true, 'synced'=>count($updated), 'quotes'=>$updated]);
} catch (Exception $e) {
    echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
}

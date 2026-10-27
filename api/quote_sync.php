<?php
require_once __DIR__ . '/../errors.php';
/* api/quote_sync.php — refresh the Zoho status of pushed quotes so staff see
   whether their quote was approved / declined. Reads each linked estimate from Zoho
   and updates the local status. In-app only (no emails).
   POST JSON: {id?}   id given -> sync that one; omitted -> sync all of the user's pushed quotes.
   Returns: {ok, quotes:[...]} (the rows that were synced, with fresh status). */
session_start();
require_once __DIR__ . '/../csrf.php'; csrf_guard();
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

    // Bulk sync only touches quotes whose status can still change (not invoiced/declined/expired),
    // and only those not refreshed in the last 10 minutes, capped per call (oldest first). This keeps
    // opening My Quotes fast instead of hitting Zoho once per quote every time.
    //   terminal statuses never need a Zoho round-trip; a single-id sync (↻ Status) always runs.
    $OPEN = "status NOT IN ('invoiced','declined','expired')";
    $STALE = "(last_synced_at IS NULL OR last_synced_at < (NOW() - INTERVAL 10 MINUTE))";
    if ($one > 0) {
        $st = $pdo->prepare("SELECT * FROM quotes WHERE id=?"); $st->execute([$one]);
        $rows = array_filter([$st->fetch(PDO::FETCH_ASSOC)]);
    } elseif ($admin) {
        $rows = $pdo->query("SELECT * FROM quotes WHERE zoho_estimate_id<>'' AND $OPEN AND $STALE ORDER BY (last_synced_at IS NULL) DESC, last_synced_at ASC LIMIT 40")->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $st = $pdo->prepare("SELECT * FROM quotes WHERE zoho_estimate_id<>'' AND created_by=? AND $OPEN AND $STALE ORDER BY (last_synced_at IS NULL) DESC, last_synced_at ASC LIMIT 40");
        $st->execute([$me]); $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    }

    $updated = [];
    foreach ($rows as $q) {
        if (!$admin && $q['created_by'] !== $me) continue;
        if (empty($q['zoho_estimate_id'])) continue;
        [$d, $c] = zoho_api('GET', 'estimates/' . rawurlencode($q['zoho_estimate_id']));
        if ($c >= 400 || empty($d['estimate'])) continue;
        $est = $d['estimate'];
        $status = (string)($est['status'] ?? $q['status']);
        $invId = (string)$q['zoho_invoice_id'];
        $invNo = (string)$q['zoho_invoice_number'];
        // If the estimate was converted to an invoice in Zoho (now or in the past), the estimate
        // carries invoice_ids — adopt that invoice so we can show its number.
        if ($invId === '' && !empty($est['invoice_ids']) && is_array($est['invoice_ids'])) {
            $invId = (string)$est['invoice_ids'][0];
            if ($invNo === '' && $invId !== '') {
                [$iv, $ic] = zoho_api('GET', 'invoices/' . rawurlencode($invId));
                if ($ic < 400 && !empty($iv['invoice'])) $invNo = (string)($iv['invoice']['invoice_number'] ?? '');
            }
        }
        // Don't let the estimate's own Zoho status overwrite our local lifecycle states:
        //  - any linked invoice => invoiced
        //  - a project (converted to a job) stays 'project' until it is billed
        if ($invId !== '' || $invNo !== '') $status = 'invoiced';
        elseif (!empty($q['is_project']) || $q['status'] === 'project') $status = 'project';
        $pdo->prepare("UPDATE quotes SET status=?, zoho_estimate_number=?, zoho_invoice_id=?, zoho_invoice_number=?, last_synced_at=NOW() WHERE id=?")
            ->execute([$status, (string)($est['estimate_number'] ?? $q['zoho_estimate_number']), $invId, $invNo, (int)$q['id']]);
        $updated[] = ['id'=>(int)$q['id'], 'status'=>$status, 'invoice_number'=>$invNo];
    }

    echo json_encode(['ok'=>true, 'synced'=>count($updated), 'quotes'=>$updated]);
} catch (Exception $e) {
    echo api_fail($e);
}

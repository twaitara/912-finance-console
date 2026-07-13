<?php
require_once __DIR__ . '/../errors.php';
/* api/quote_project.php — project lifecycle actions (admin only).
   POST JSON: {id, action}
     action 'start'  -> approved/sent/accepted quote becomes a project (status='project', is_project=1)
     action 'close'  -> archive the project (project_closed=1); the team can no longer see/cost it
     action 'reopen' -> un-archive (project_closed=0)
   The Zoho estimate/invoice is untouched — these are app-side project states. */
session_start();
require_once __DIR__ . '/../csrf.php'; csrf_guard();
header('Content-Type: application/json; charset=utf-8');
if (empty($_SESSION['auth']) || empty($_SESSION['is_admin'])) {
    http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Admins only.']); exit;
}
require __DIR__ . '/../db.php';
require __DIR__ . '/../project_costs.php';

try {
    $pdo = db();
    pc_table($pdo);   // make sure is_project / project_closed columns exist
    $in = json_decode(file_get_contents('php://input'), true); if (!is_array($in)) $in = $_POST;
    $id = (int)($in['id'] ?? 0); if ($id <= 0) throw new Exception('No quote.');
    $action = $in['action'] ?? 'start';

    $st = $pdo->prepare("SELECT * FROM quotes WHERE id=?"); $st->execute([$id]);
    $q = $st->fetch(PDO::FETCH_ASSOC);
    if (!$q) throw new Exception('Quote not found.');
    $me = $_SESSION['user'] ?? '';
    require_once __DIR__ . '/../activity_store.php';

    if ($action === 'start') {
        if (empty($q['zoho_estimate_id'])) throw new Exception('Push the quote to Zoho first.');
        $isInvoiced = ($q['status'] === 'invoiced') || (trim((string)$q['zoho_invoice_number']) !== '');
        if ($isInvoiced) {
            // already billed — bring it into the project flow (so costs can be captured) but keep it invoiced
            $pdo->prepare("UPDATE quotes SET is_project=1, project_closed=0 WHERE id=?")->execute([$id]);
            activity_log($pdo, $me, 'started project (already invoiced)', ($q['zoho_invoice_number'] ?: ('#'.$id)) . ' · ' . ($q['customer_name'] ?? ''));
            echo json_encode(['ok'=>true, 'status'=>$q['status'], 'is_project'=>1, 'project_closed'=>0, 'invoice_number'=>$q['zoho_invoice_number']]); exit;
        }
        if (!in_array($q['status'], ['approved','sent','accepted'], true)) {
            throw new Exception('Only an approved (or invoiced) quote can be turned into a project.');
        }
        $pdo->prepare("UPDATE quotes SET status='project', is_project=1, project_closed=0 WHERE id=?")->execute([$id]);
        activity_log($pdo, $me, 'started project', ($q['zoho_estimate_number'] ?: ('#'.$id)) . ' · ' . ($q['customer_name'] ?? ''));
        echo json_encode(['ok'=>true, 'status'=>'project', 'is_project'=>1, 'project_closed'=>0]); exit;
    }

    if ($action === 'close') {
        if (empty($q['is_project'])) throw new Exception('This is not a project.');
        $pdo->prepare("UPDATE quotes SET project_closed=1 WHERE id=?")->execute([$id]);
        activity_log($pdo, $me, 'closed project', ($q['zoho_invoice_number'] ?: $q['zoho_estimate_number'] ?: ('#'.$id)) . ' · ' . ($q['customer_name'] ?? ''));
        echo json_encode(['ok'=>true, 'project_closed'=>1]); exit;
    }

    if ($action === 'reopen') {
        if (empty($q['is_project'])) throw new Exception('This is not a project.');
        $pdo->prepare("UPDATE quotes SET project_closed=0 WHERE id=?")->execute([$id]);
        activity_log($pdo, $me, 'reopened project', ($q['zoho_invoice_number'] ?: $q['zoho_estimate_number'] ?: ('#'.$id)) . ' · ' . ($q['customer_name'] ?? ''));
        echo json_encode(['ok'=>true, 'project_closed'=>0]); exit;
    }

    if ($action === 'set_subject') {
        $subj = substr(trim((string)($in['subject'] ?? '')), 0, 190);
        $pdo->prepare("UPDATE quotes SET subject=? WHERE id=?")->execute([$subj, $id]);
        activity_log($pdo, $me, 'set project subject', ($q['zoho_estimate_number'] ?: ('#'.$id)) . ' · ' . $subj);
        echo json_encode(['ok'=>true, 'subject'=>$subj]); exit;
    }

    if ($action === 'pull_subject') {
        if (empty($q['zoho_estimate_id'])) throw new Exception('This project has no linked Zoho quote to read the subject from.');
        [$d, $c] = zoho_api('GET', 'estimates/' . rawurlencode($q['zoho_estimate_id']));
        if ($c >= 400 || empty($d['estimate'])) throw new Exception($d['message'] ?? 'Could not load the Zoho quote.');
        $subj = zoho_estimate_subject($d['estimate']);
        $pdo->prepare("UPDATE quotes SET subject=? WHERE id=?")->execute([$subj, $id]);
        activity_log($pdo, $me, 'pulled project subject', ($q['zoho_estimate_number'] ?: ('#'.$id)) . ' · ' . ($subj !== '' ? $subj : '(none on quote)'));
        echo json_encode(['ok'=>true, 'subject'=>$subj]); exit;
    }

    throw new Exception('Unknown action.');
} catch (Exception $e) {
    echo api_fail($e);
}

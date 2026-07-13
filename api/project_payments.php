<?php
require_once __DIR__ . '/../errors.php';
/* api/project_payments.php — record client deposits/payments against a project (admin only).
   Open projects aren't invoiced yet, so this tracks money received in the app.
   Actions (POST JSON):
     {action:'list', quote_id}                       -> {ok, payments[], total, quote_total, balance}
     {action:'add',  quote_id, amount, date?, note?} -> {ok, payment, total, balance}
     {action:'delete', id}                           -> {ok, total, balance} */
session_start();
header('Content-Type: application/json; charset=utf-8');
if (empty($_SESSION['auth']) || empty($_SESSION['is_admin'])) {
    http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Admins only.']); exit;
}
require __DIR__ . '/../db.php';
require __DIR__ . '/../project_costs.php';   // pp_table / pp_total

function pp_quote(PDO $pdo, $id){
    $st = $pdo->prepare("SELECT id, customer_name, total, currency FROM quotes WHERE id=?");
    $st->execute([(int)$id]); return $st->fetch(PDO::FETCH_ASSOC);
}
function pp_out(PDO $pdo, $quoteId){
    $q = pp_quote($pdo, $quoteId);
    $total = pp_total($pdo, $quoteId);
    $qt = (float)($q['total'] ?? 0);
    return ['total'=>$total, 'quote_total'=>$qt, 'balance'=>round($qt - $total, 2)];
}

try {
    $pdo = db();
    pp_table($pdo);
    $me = $_SESSION['user'] ?? '';
    $in = json_decode(file_get_contents('php://input'), true); if (!is_array($in)) $in = $_POST;
    $action = $in['action'] ?? 'list';

    if ($action === 'list') {
        $qid = (int)($in['quote_id'] ?? 0); if ($qid <= 0) throw new Exception('No project.');
        $q = pp_quote($pdo, $qid); if (!$q) throw new Exception('Project not found.');
        $st = $pdo->prepare("SELECT * FROM project_payments WHERE quote_id=? ORDER BY paid_date, id");
        $st->execute([$qid]);
        $payments = array_map(fn($p)=>[
            'id'=>(int)$p['id'], 'amount'=>(float)$p['amount'], 'paid_date'=>$p['paid_date'],
            'note'=>$p['note'], 'created_by'=>$p['created_by'],
        ], $st->fetchAll(PDO::FETCH_ASSOC));
        echo json_encode(array_merge(['ok'=>true, 'payments'=>$payments, 'customer_name'=>$q['customer_name'], 'currency'=>$q['currency'] ?: 'KES'], pp_out($pdo, $qid)));
        exit;
    }

    if ($action === 'add') {
        $qid = (int)($in['quote_id'] ?? 0); if ($qid <= 0) throw new Exception('No project.');
        if (!pp_quote($pdo, $qid)) throw new Exception('Project not found.');
        $amount = round((float)($in['amount'] ?? 0), 2);
        if ($amount <= 0) throw new Exception('Enter a payment amount greater than zero.');
        $date = preg_replace('/[^0-9\-]/', '', (string)($in['date'] ?? '')) ?: date('Y-m-d');
        $note = substr(trim((string)($in['note'] ?? '')), 0, 190);
        $pdo->prepare("INSERT INTO project_payments (quote_id, amount, paid_date, note, created_by) VALUES (?,?,?,?,?)")
            ->execute([$qid, $amount, $date, $note, $me]);
        $pid = (int)$pdo->lastInsertId();
        require_once __DIR__ . '/../activity_store.php';
        activity_log($pdo, $me, 'recorded project payment', '#'.$qid.' · '.number_format($amount, 0).($note!==''?(' · '.$note):''));
        echo json_encode(array_merge(['ok'=>true, 'payment'=>['id'=>$pid,'amount'=>$amount,'paid_date'=>$date,'note'=>$note,'created_by'=>$me]], pp_out($pdo, $qid)));
        exit;
    }

    if ($action === 'delete') {
        $pid = (int)($in['id'] ?? 0); if ($pid <= 0) throw new Exception('No payment.');
        $st = $pdo->prepare("SELECT quote_id FROM project_payments WHERE id=?"); $st->execute([$pid]);
        $qid = (int)$st->fetchColumn();
        $pdo->prepare("DELETE FROM project_payments WHERE id=?")->execute([$pid]);
        echo json_encode(array_merge(['ok'=>true], pp_out($pdo, $qid)));
        exit;
    }

    throw new Exception('Unknown action.');
} catch (Exception $e) {
    echo api_fail($e);
}

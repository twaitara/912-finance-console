<?php
/* api/projects.php — list projects (quotes converted to a project).
   Admin: every project (open / billed / closed) with cost + profit.
   Team (costcap): only OPEN projects (status='project', not closed), cost-only —
   no charged price or profit. Plain users see their own open projects.
   POST/GET JSON: {action:'list'} */
session_start();
header('Content-Type: application/json; charset=utf-8');
if (empty($_SESSION['auth'])) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Not signed in.']); exit; }
require __DIR__ . '/../db.php';
require __DIR__ . '/../project_costs.php';

function proj_line_count($json){ $a = json_decode($json ?: '[]', true); return is_array($a) ? count($a) : 0; }

try {
    $pdo = db();
    pc_table($pdo);
    $me    = $_SESSION['user'] ?? '';
    $admin = !empty($_SESSION['is_admin']);
    $tabsS   = (string)($_SESSION['tabs'] ?? '');
    $costcap = ($tabsS === '*') || in_array('costcap', array_map('trim', explode(',', $tabsS)), true);

    if ($admin) {
        $st = $pdo->query("SELECT * FROM quotes WHERE is_project=1 ORDER BY project_closed ASC, updated_at DESC");
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $out = array_map(function($q){
            return [
                'id'=>(int)$q['id'], 'customer_name'=>$q['customer_name'],
                'zoho_estimate_number'=>$q['zoho_estimate_number'], 'zoho_invoice_number'=>$q['zoho_invoice_number'],
                'status'=>$q['status'], 'project_closed'=>(int)$q['project_closed'],
                'created_by'=>$q['created_by'], 'quote_date'=>$q['quote_date'], 'created_at'=>$q['created_at'],
                'line_count'=>proj_line_count($q['line_items']),
                'total'=>(float)$q['total'], 'actual_cost'=>(float)($q['actual_cost'] ?? 0), 'actual_profit'=>(float)($q['actual_profit'] ?? 0),
            ];
        }, $rows);
        echo json_encode(['ok'=>true, 'admin'=>true, 'projects'=>$out]); exit;
    }

    // team / staff — OPEN projects only, cost-only (no price/profit)
    if ($costcap) {
        $st = $pdo->prepare("SELECT * FROM quotes WHERE is_project=1 AND status='project' AND project_closed=0 ORDER BY updated_at DESC");
        $st->execute();
    } else {
        $st = $pdo->prepare("SELECT * FROM quotes WHERE is_project=1 AND status='project' AND project_closed=0 AND created_by=? ORDER BY updated_at DESC");
        $st->execute([$me]);
    }
    $out = array_map(function($q){
        return [
            'id'=>(int)$q['id'], 'customer_name'=>$q['customer_name'],
            'zoho_estimate_number'=>$q['zoho_estimate_number'],
            'status'=>$q['status'], 'project_closed'=>0,
            'line_count'=>proj_line_count($q['line_items']),
            'actual_cost'=>(float)($q['actual_cost'] ?? 0),   // cost is fine for the team; price/profit omitted
        ];
    }, $st->fetchAll(PDO::FETCH_ASSOC));
    echo json_encode(['ok'=>true, 'admin'=>false, 'projects'=>$out]);
} catch (Exception $e) {
    echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
}

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
$cfg = require __DIR__ . '/../config.php';
$VAT = (float)($cfg['vat_rate'] ?? 0.16);

function proj_line_count($json){ $a = json_decode($json ?: '[]', true); return is_array($a) ? count($a) : 0; }

try {
    $pdo = db();
    pc_table($pdo);
    $me    = $_SESSION['user'] ?? '';
    $admin = !empty($_SESSION['is_admin']);
    $tabsS   = (string)($_SESSION['tabs'] ?? '');
    $tabsArr = array_map('trim', explode(',', $tabsS));
    $costcap = ($tabsS === '*') || in_array('costcap', $tabsArr, true) || in_array('projects', $tabsArr, true);

    if ($admin) {
        pp_table($pdo); pa_table($pdo);
        $vplus = $VAT; $vdiv = 1 + $VAT;   // VAT-aware: 'plus' adds VAT, 'incl' has VAT inside, else 0
        $st = $pdo->query("SELECT q.*,
                             (SELECT COALESCE(SUM(amount),0) FROM project_payments pp WHERE pp.quote_id=q.id) AS paid_total,
                             (SELECT COALESCE(SUM(CASE WHEN pc.vat_mode='incl' THEN pc.amount - pc.amount/$vdiv
                                                       WHEN pc.vat_mode='plus' THEN pc.amount*$vplus
                                                       ELSE 0 END),0) FROM project_costs pc WHERE pc.quote_id=q.id) AS cost_vat
                           FROM quotes q WHERE q.is_project=1 ORDER BY q.project_closed ASC, q.updated_at DESC");
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $assigneesBy = [];
        foreach ($pdo->query("SELECT quote_id, username FROM project_assignees ORDER BY username")->fetchAll(PDO::FETCH_ASSOC) as $a) {
            $assigneesBy[(int)$a['quote_id']][] = $a['username'];
        }
        $out = array_map(function($q) use ($assigneesBy){
            $total=(float)$q['total']; $paid=(float)($q['paid_total'] ?? 0);
            return [
                'id'=>(int)$q['id'], 'customer_name'=>$q['customer_name'], 'subject'=>$q['subject'],
                'zoho_estimate_number'=>$q['zoho_estimate_number'], 'zoho_invoice_number'=>$q['zoho_invoice_number'],
                'status'=>$q['status'], 'project_closed'=>(int)$q['project_closed'],
                'created_by'=>$q['created_by'], 'quote_date'=>$q['quote_date'], 'created_at'=>$q['created_at'],
                'line_count'=>proj_line_count($q['line_items']),
                'total'=>$total,
                'budget_cost'=>(float)($q['total_cost'] ?? 0), 'expected_profit'=>(float)($q['profit'] ?? 0),
                'actual_cost'=>(float)($q['actual_cost'] ?? 0), 'actual_profit'=>(float)($q['actual_profit'] ?? 0),
                'cost_vat'=>round((float)($q['cost_vat'] ?? 0), 2),   // input VAT; total spend = actual_cost(ex-VAT) + cost_vat
                'paid_total'=>$paid, 'balance'=>round($total-$paid, 2),
                'assignees'=>$assigneesBy[(int)$q['id']] ?? [],
            ];
        }, $rows);
        echo json_encode(['ok'=>true, 'admin'=>true, 'projects'=>$out]); exit;
    }

    // team / staff — OPEN projects the admin has ASSIGNED them (or ones they created), cost-only
    pa_table($pdo);
    $openWhere = "is_project=1 AND project_closed=0 AND status<>'invoiced' AND (zoho_invoice_number IS NULL OR zoho_invoice_number='')";
    $st = $pdo->prepare("SELECT * FROM quotes
        WHERE $openWhere AND (created_by=? OR id IN (SELECT quote_id FROM project_assignees WHERE username=?))
        ORDER BY updated_at DESC");
    $st->execute([$me, $me]);
    $out = array_map(function($q){
        return [
            'id'=>(int)$q['id'], 'customer_name'=>$q['customer_name'], 'subject'=>$q['subject'],
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

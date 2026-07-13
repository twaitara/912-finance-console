<?php
/* project_costs.php — the actual-cost rows captured against a project (quote).
   One row per cost entry, stored relationally (queryable) instead of as JSON.
   Costs live here from the moment a quote becomes a 'project'; at billing each row
   is pushed to Zoho Books as an expense (reference_number = invoice number) and its
   zoho_expense_id is stored back.

   Zoho is the accounting record; this table is the app-side source of truth. */

require_once __DIR__ . '/zoho.php';
require_once __DIR__ . '/costcap_sync.php';   // costcap_config()

function pc_table(PDO $pdo){
    $pdo->exec("CREATE TABLE IF NOT EXISTS project_costs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        quote_id INT NOT NULL,
        line_index INT NOT NULL DEFAULT 0,
        line_name VARCHAR(190) DEFAULT '',
        category VARCHAR(40) DEFAULT 'other',
        description VARCHAR(190) DEFAULT '',
        qty DECIMAL(12,2) DEFAULT 1,
        unit_cost DECIMAL(14,2) DEFAULT 0,
        amount DECIMAL(14,2) DEFAULT 0,
        zoho_expense_id VARCHAR(64) DEFAULT '',
        created_by VARCHAR(80) DEFAULT '',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX (quote_id), INDEX (quote_id, line_index), INDEX (category)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // actual (captured) cost/profit cached on the quote for cheap list rendering,
    // plus the project markers (is_project = ever converted; project_closed = archived by admin)
    foreach ([
        "ADD COLUMN actual_cost DECIMAL(14,2) DEFAULT 0 AFTER profit",
        "ADD COLUMN actual_profit DECIMAL(14,2) DEFAULT 0 AFTER actual_cost",
        "ADD COLUMN is_project TINYINT NOT NULL DEFAULT 0 AFTER actual_profit",
        "ADD COLUMN project_closed TINYINT NOT NULL DEFAULT 0 AFTER is_project",
    ] as $alter) { try { $pdo->exec("ALTER TABLE quotes $alter"); } catch (Exception $e) {} }
}

/* all cost rows for a quote, ordered for stable rendering */
function pc_for_quote(PDO $pdo, $quoteId){
    $st = $pdo->prepare("SELECT * FROM project_costs WHERE quote_id=? ORDER BY line_index, id");
    $st->execute([(int)$quoteId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function pc_total(PDO $pdo, $quoteId){
    $st = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM project_costs WHERE quote_id=?");
    $st->execute([(int)$quoteId]);
    return round((float)$st->fetchColumn(), 2);
}

/* recompute actual_cost / actual_profit onto the quote from its cost rows */
function pc_refresh_quote(PDO $pdo, $quoteId){
    $cost = pc_total($pdo, $quoteId);
    $st = $pdo->prepare("SELECT sub_total, discount_amount FROM quotes WHERE id=?");
    $st->execute([(int)$quoteId]);
    $r = $st->fetch(PDO::FETCH_ASSOC) ?: ['sub_total'=>0,'discount_amount'=>0];
    $profit = round(((float)$r['sub_total'] - (float)$r['discount_amount']) - $cost, 2);
    $pdo->prepare("UPDATE quotes SET actual_cost=?, actual_profit=? WHERE id=?")
        ->execute([$cost, $profit, (int)$quoteId]);
    return ['actual_cost'=>$cost, 'actual_profit'=>$profit];
}

/* --- Zoho expense body for one cost row (KES, tax-exclusive, tied to the invoice) --- */
function pc_expense_body(array $row, $invNo, array $conf){
    $desc = trim((string)($row['line_name'] ?? '')) . ' — ' . ucfirst((string)($row['category'] ?? 'other'))
          . ((trim((string)($row['description'] ?? '')) !== '') ? (': ' . trim((string)$row['description'])) : '');
    $body = [
        'account_id'       => (string)($conf['account_id'] ?? ''),
        'date'             => date('Y-m-d'),
        'amount'           => round((float)($row['amount'] ?? 0), 2),
        'reference_number' => (string)$invNo,
        'description'      => substr($desc, 0, 500),
        'is_inclusive_tax' => false,
    ];
    if (!empty($conf['paid_through_account_id'])) $body['paid_through_account_id'] = (string)$conf['paid_through_account_id'];
    return $body;
}

/* create a Zoho expense for a row -> [expense_id, errorOrNull] */
function pc_zoho_create(array $row, $invNo, array $conf){
    [$d, $c] = zoho_api('POST', 'expenses', pc_expense_body($row, $invNo, $conf));
    if ($c < 400 && !empty($d['expense']['expense_id'])) return [(string)$d['expense']['expense_id'], null];
    return ['', ($d['message'] ?? ('HTTP ' . $c))];
}

/* update an existing Zoho expense -> errorOrNull */
function pc_zoho_update($expenseId, array $row, $invNo, array $conf){
    [$d, $c] = zoho_api('PUT', 'expenses/' . $expenseId, pc_expense_body($row, $invNo, $conf));
    return ($c >= 400) ? ($d['message'] ?? ('HTTP ' . $c)) : null;
}

/* delete a Zoho expense (best-effort) -> errorOrNull */
function pc_zoho_delete($expenseId){
    if ($expenseId === '') return null;
    [$d, $c] = zoho_api('DELETE', 'expenses/' . $expenseId);
    return ($c >= 400) ? ($d['message'] ?? ('HTTP ' . $c)) : null;
}

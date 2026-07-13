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
    // whether the entered cost is inclusive or exclusive of VAT ('incl' | 'excl')
    try { $pdo->exec("ALTER TABLE project_costs ADD COLUMN vat_mode VARCHAR(4) DEFAULT 'excl' AFTER amount"); } catch (Exception $e) {}
    // actual (captured) cost/profit cached on the quote for cheap list rendering,
    // plus the project markers (is_project = ever converted; project_closed = archived by admin)
    foreach ([
        "ADD COLUMN actual_cost DECIMAL(14,2) DEFAULT 0 AFTER profit",
        "ADD COLUMN actual_profit DECIMAL(14,2) DEFAULT 0 AFTER actual_cost",
        "ADD COLUMN is_project TINYINT NOT NULL DEFAULT 0 AFTER actual_profit",
        "ADD COLUMN project_closed TINYINT NOT NULL DEFAULT 0 AFTER is_project",
        "ADD COLUMN imported TINYINT NOT NULL DEFAULT 0 AFTER project_closed",
    ] as $alter) { try { $pdo->exec("ALTER TABLE quotes $alter"); } catch (Exception $e) {} }
}

/* client deposits/payments recorded against a project (before it is billed) */
function pp_table(PDO $pdo){
    $pdo->exec("CREATE TABLE IF NOT EXISTS project_payments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        quote_id INT NOT NULL,
        amount DECIMAL(14,2) DEFAULT 0,
        paid_date DATE NULL,
        note VARCHAR(190) DEFAULT '',
        created_by VARCHAR(80) DEFAULT '',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (quote_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
function pp_total(PDO $pdo, $quoteId){
    $st = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM project_payments WHERE quote_id=?");
    $st->execute([(int)$quoteId]);
    return round((float)$st->fetchColumn(), 2);
}

/* the real subject shown on a Zoho estimate: a custom field labelled "subject",
   else a native subject field. Returns '' when the quote has no subject. */
function zoho_estimate_subject(array $e){
    foreach (($e['custom_fields'] ?? []) as $f) {
        $lbl = strtolower((string)($f['label'] ?? '') . ' ' . (string)($f['placeholder'] ?? ''));
        if (strpos($lbl, 'subject') !== false) { $v = trim((string)($f['value'] ?? '')); if ($v !== '') return substr($v, 0, 190); }
    }
    $v = trim((string)($e['subject'] ?? ''));
    return $v !== '' ? substr($v, 0, 190) : '';
}

/* per-project viewer assignment: which system users may see/cost a project */
function pa_table(PDO $pdo){
    $pdo->exec("CREATE TABLE IF NOT EXISTS project_assignees (
        id INT AUTO_INCREMENT PRIMARY KEY,
        quote_id INT NOT NULL,
        username VARCHAR(80) NOT NULL,
        assigned_by VARCHAR(80) DEFAULT '',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_pa (quote_id, username),
        INDEX (username)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
function pa_for_quote(PDO $pdo, $quoteId){
    $st = $pdo->prepare("SELECT username FROM project_assignees WHERE quote_id=? ORDER BY username");
    $st->execute([(int)$quoteId]);
    return $st->fetchAll(PDO::FETCH_COLUMN);
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

/* ex-VAT value of a cost (VAT is never part of profit) */
function pc_exvat($amount, $vatMode, $vat){
    $amount = (float)$amount;
    return round(($vatMode === 'incl') ? $amount / (1 + (float)$vat) : $amount, 2);
}

/* total ACTUAL cost ex-VAT (used for profit) */
function pc_actual_cost_exvat(PDO $pdo, $quoteId, $vat){
    $st = $pdo->prepare("SELECT amount, vat_mode FROM project_costs WHERE quote_id=?");
    $st->execute([(int)$quoteId]);
    $sum = 0.0;
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $sum += pc_exvat($r['amount'], $r['vat_mode'] ?? 'excl', $vat);
    return round($sum, 2);
}

/* the org's 16% VAT tax id (cached) — used to post VAT-inclusive expenses correctly */
function zoho_vat_tax_id(){
    $cache = __DIR__ . '/data/zoho_vat.json';
    if (is_file($cache)) { $t = json_decode(@file_get_contents($cache), true); if (!empty($t['tax_id'])) return (string)$t['tax_id']; }
    [$data, $code] = zoho_api('GET', 'settings/taxes');
    $id = '';
    if ($code < 400) {
        foreach (($data['taxes'] ?? []) as $tax) {
            $pct = (float)($tax['tax_percentage'] ?? 0); $nm = strtolower((string)($tax['tax_name'] ?? ''));
            if (abs($pct - 16.0) < 0.01 || strpos($nm, 'vat') !== false) { $id = (string)($tax['tax_id'] ?? ''); if (abs($pct-16.0)<0.01) break; }
        }
        if (!is_dir(__DIR__ . '/data')) @mkdir(__DIR__ . '/data', 0775, true);
        @file_put_contents($cache, json_encode(['tax_id'=>$id]));
    }
    return $id;
}

/* recompute actual_cost (ex-VAT) / actual_profit onto the quote — VAT is excluded from profit */
function pc_refresh_quote(PDO $pdo, $quoteId, $vat = 0.16){
    $cost = pc_actual_cost_exvat($pdo, $quoteId, $vat);
    $st = $pdo->prepare("SELECT sub_total, discount_amount FROM quotes WHERE id=?");
    $st->execute([(int)$quoteId]);
    $r = $st->fetch(PDO::FETCH_ASSOC) ?: ['sub_total'=>0,'discount_amount'=>0];
    $profit = round(((float)$r['sub_total'] - (float)$r['discount_amount']) - $cost, 2);
    $pdo->prepare("UPDATE quotes SET actual_cost=?, actual_profit=? WHERE id=?")
        ->execute([$cost, $profit, (int)$quoteId]);
    return ['actual_cost'=>$cost, 'actual_profit'=>$profit];
}

/* --- Zoho expense body for one cost row (tied to the invoice). VAT-inclusive rows are
   posted with is_inclusive_tax + the VAT tax id so Zoho's net (total_without_tax) stays
   ex-VAT; exclusive rows post as net. --- */
function pc_expense_body(array $row, $invNo, array $conf, $vatTaxId = ''){
    $desc = trim((string)($row['line_name'] ?? '')) . ' — ' . ucfirst((string)($row['category'] ?? 'other'))
          . ((trim((string)($row['description'] ?? '')) !== '') ? (': ' . trim((string)$row['description'])) : '');
    $incl = ((string)($row['vat_mode'] ?? 'excl') === 'incl') && ($vatTaxId !== '');
    $body = [
        'account_id'       => (string)($conf['account_id'] ?? ''),
        'date'             => date('Y-m-d'),
        'amount'           => round((float)($row['amount'] ?? 0), 2),
        'reference_number' => (string)$invNo,
        'description'      => substr($desc, 0, 500),
        'is_inclusive_tax' => $incl,
    ];
    if ($incl) $body['tax_id'] = (string)$vatTaxId;
    if (!empty($conf['paid_through_account_id'])) $body['paid_through_account_id'] = (string)$conf['paid_through_account_id'];
    return $body;
}

/* create a Zoho expense for a row -> [expense_id, errorOrNull] */
function pc_zoho_create(array $row, $invNo, array $conf, $vatTaxId = ''){
    [$d, $c] = zoho_api('POST', 'expenses', pc_expense_body($row, $invNo, $conf, $vatTaxId));
    if ($c < 400 && !empty($d['expense']['expense_id'])) return [(string)$d['expense']['expense_id'], null];
    return ['', ($d['message'] ?? ('HTTP ' . $c))];
}

/* update an existing Zoho expense -> errorOrNull */
function pc_zoho_update($expenseId, array $row, $invNo, array $conf, $vatTaxId = ''){
    [$d, $c] = zoho_api('PUT', 'expenses/' . $expenseId, pc_expense_body($row, $invNo, $conf, $vatTaxId));
    return ($c >= 400) ? ($d['message'] ?? ('HTTP ' . $c)) : null;
}

/* delete a Zoho expense (best-effort) -> errorOrNull */
function pc_zoho_delete($expenseId){
    if ($expenseId === '') return null;
    [$d, $c] = zoho_api('DELETE', 'expenses/' . $expenseId);
    return ($c >= 400) ? ($d['message'] ?? ('HTTP ' . $c)) : null;
}

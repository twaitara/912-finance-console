<?php
/* api/quotes.php — local quote drafts (before/after they are pushed to Zoho as estimates).
   Each quote is owned by the app user who created it. Admins see/edit all; staff see only their own.
   Tables (auto-created): quotes.
   Actions (POST JSON):
     {action:'list'}                              -> own quotes (admin: all)
     {action:'get', id}                           -> one quote
     {action:'save', id?, zoho_customer_id, customer_name, currency?, line_items:[{name,description?,qty,rate}], notes?}
     {action:'delete', id}
   Totals (sub_total / tax / total) are always recomputed server-side. */
session_start();
header('Content-Type: application/json; charset=utf-8');
if (empty($_SESSION['auth'])) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Not signed in.']); exit; }
require __DIR__ . '/../db.php';
require __DIR__ . '/../quote_pricing.php';   // shared quote_price() + quote_out() + cost_rows support
$cfg = require __DIR__ . '/../config.php';

function quotes_table(PDO $pdo){
    $pdo->exec("CREATE TABLE IF NOT EXISTS quotes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        created_by VARCHAR(80) NOT NULL,
        created_email VARCHAR(190) DEFAULT '',
        zoho_customer_id VARCHAR(64) DEFAULT '',
        customer_name VARCHAR(190) DEFAULT '',
        currency VARCHAR(8) DEFAULT 'KES',
        line_items LONGTEXT,
        notes TEXT,
        sub_total DECIMAL(14,2) DEFAULT 0,
        tax_amount DECIMAL(14,2) DEFAULT 0,
        total DECIMAL(14,2) DEFAULT 0,
        status VARCHAR(32) DEFAULT 'local_draft',
        zoho_estimate_id VARCHAR(64) DEFAULT '',
        zoho_estimate_number VARCHAR(64) DEFAULT '',
        last_synced_at DATETIME NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // additive columns for the fuller (Zoho-style) form — safe to re-run
    foreach ([
        "ADD COLUMN reference VARCHAR(80) DEFAULT '' AFTER customer_name",
        "ADD COLUMN subject VARCHAR(190) DEFAULT '' AFTER reference",
        "ADD COLUMN quote_date DATE NULL AFTER subject",
        "ADD COLUMN expiry_date DATE NULL AFTER quote_date",
        "ADD COLUMN terms TEXT AFTER notes",
        "ADD COLUMN discount_value DECIMAL(12,2) DEFAULT 0 AFTER tax_amount",
        "ADD COLUMN discount_type VARCHAR(10) DEFAULT 'percent' AFTER discount_value",
        "ADD COLUMN discount_amount DECIMAL(14,2) DEFAULT 0 AFTER discount_type",
        "ADD COLUMN total_cost DECIMAL(14,2) DEFAULT 0 AFTER discount_amount",
        "ADD COLUMN profit DECIMAL(14,2) DEFAULT 0 AFTER total_cost",
        "ADD COLUMN zoho_invoice_id VARCHAR(64) DEFAULT '' AFTER zoho_estimate_number",
        "ADD COLUMN zoho_invoice_number VARCHAR(64) DEFAULT '' AFTER zoho_invoice_id",
    ] as $alter) { try { $pdo->exec("ALTER TABLE quotes $alter"); } catch (Exception $e) {} }
}

try {
    $pdo = db();
    quotes_table($pdo);
    $me     = $_SESSION['user'] ?? '';
    $admin  = !empty($_SESSION['is_admin']);
    $vat    = (float)($cfg['vat_rate'] ?? 0.16);
    // a non-admin "cost capturer" may also view (price-stripped) invoiced jobs they don't own
    $tabsS   = (string)($_SESSION['tabs'] ?? '');
    $costcap = ($tabsS === '*') || in_array('costcap', array_map('trim', explode(',', $tabsS)), true);

    $in = json_decode(file_get_contents('php://input'), true); if (!is_array($in)) $in = $_POST;
    $action = $in['action'] ?? 'list';

    /* only the owner (or an admin) may touch a given quote */
    $own = function($id) use ($pdo, $me, $admin){
        $st = $pdo->prepare("SELECT * FROM quotes WHERE id=?"); $st->execute([(int)$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new Exception('Quote not found.');
        if (!$admin && $row['created_by'] !== $me) throw new Exception('Not your quote.');
        return $row;
    };

    if ($action === 'list') {
        if ($admin) {
            $st = $pdo->query("SELECT * FROM quotes ORDER BY updated_at DESC");
            $out = array_map('quote_out', $st->fetchAll(PDO::FETCH_ASSOC));
        } else {
            // own quotes (full detail — they built them)
            $st = $pdo->prepare("SELECT * FROM quotes WHERE created_by=? ORDER BY updated_at DESC");
            $st->execute([$me]);
            $out = array_map('quote_out', $st->fetchAll(PDO::FETCH_ASSOC));
            // cost capturers additionally see invoiced jobs from others — with all prices stripped
            if ($costcap) {
                $st2 = $pdo->prepare("SELECT * FROM quotes WHERE created_by<>? AND status='invoiced' ORDER BY updated_at DESC");
                $st2->execute([$me]);
                foreach ($st2->fetchAll(PDO::FETCH_ASSOC) as $r) { $out[] = quote_strip_prices(quote_out($r)); }
            }
        }
        echo json_encode(['ok'=>true, 'quotes'=>$out]);
        exit;
    }

    if ($action === 'get') { echo json_encode(['ok'=>true, 'quote'=>quote_out($own($in['id'] ?? 0))]); exit; }

    if ($action === 'delete') {
        $row = $own($in['id'] ?? 0);
        $pdo->prepare("DELETE FROM quotes WHERE id=?")->execute([(int)$row['id']]);
        require_once __DIR__ . '/../activity_store.php';
        activity_log($pdo, $me, 'removed quote', ($row['zoho_estimate_number'] ?: ('#'.$row['id'])) . ' · ' . ($row['customer_name'] ?? ''));
        echo json_encode(['ok'=>true]); exit;
    }

    if ($action === 'save') {
        $custId = trim((string)($in['zoho_customer_id'] ?? ''));
        $custNm = trim((string)($in['customer_name'] ?? ''));
        if ($custNm === '') throw new Exception('Choose a customer.');
        $discType = (strtolower((string)($in['discount_type'] ?? 'percent')) === 'amount') ? 'amount' : 'percent';
        $discVal  = max(0, (float)($in['discount_value'] ?? 0));
        [$items, $sub, $discAmt, $tax, $total, $costTotal, $profit] = quote_price($in['line_items'] ?? [], $vat, $discVal, $discType);
        if (!$items) throw new Exception('Add at least one line item.');
        $cur   = trim((string)($in['currency'] ?? 'KES')) ?: 'KES';
        if (!$admin && strtoupper($cur) !== 'KES') throw new Exception('Only the owner can make quotes in a currency other than KES.');
        $notes = substr(trim((string)($in['notes'] ?? '')), 0, 1000);
        $terms = substr(trim((string)($in['terms'] ?? '')), 0, 2000);
        $ref   = substr(trim((string)($in['reference'] ?? '')), 0, 80);
        $subj  = substr(trim((string)($in['subject'] ?? '')), 0, 190);
        if ($subj === '') throw new Exception('Subject is required.');           // subject is mandatory
        $qdate = preg_replace('/[^0-9\-]/', '', (string)($in['quote_date'] ?? '')) ?: null;
        $edate = preg_replace('/[^0-9\-]/', '', (string)($in['expiry_date'] ?? '')) ?: null;
        $itemsJson = json_encode($items);

        // editable only while a quote is still awaiting approval
        $editable = ['local_draft', 'draft', 'pending_approval'];

        if (!empty($in['id'])) {
            $row = $own($in['id']);
            // technicians can edit only while awaiting approval; the owner can edit any quote
            if (!$admin && !in_array($row['status'], $editable, true)) {
                throw new Exception('This quote can no longer be edited (status: ' . $row['status'] . '). Only quotes awaiting approval are editable.');
            }
            $pdo->prepare("UPDATE quotes SET zoho_customer_id=?, customer_name=?, reference=?, subject=?, quote_date=?, expiry_date=?, currency=?, line_items=?, notes=?, terms=?, sub_total=?, tax_amount=?, discount_value=?, discount_type=?, discount_amount=?, total_cost=?, profit=?, total=? WHERE id=?")
                ->execute([$custId, $custNm, $ref, $subj, $qdate, $edate, $cur, $itemsJson, $notes, $terms, $sub, $tax, $discVal, $discType, $discAmt, $costTotal, $profit, $total, (int)$row['id']]);
            $id = (int)$row['id'];
        } else {
            $pdo->prepare("INSERT INTO quotes (created_by, created_email, zoho_customer_id, customer_name, reference, subject, quote_date, expiry_date, currency, line_items, notes, terms, sub_total, tax_amount, discount_value, discount_type, discount_amount, total_cost, profit, total, status)
                           VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?, 'local_draft')")
                ->execute([$me, $_SESSION['email'] ?? '', $custId, $custNm, $ref, $subj, $qdate, $edate, $cur, $itemsJson, $notes, $terms, $sub, $tax, $discVal, $discType, $discAmt, $costTotal, $profit, $total]);
            $id = (int)$pdo->lastInsertId();
        }
        require_once __DIR__ . '/../activity_store.php';
        activity_log($pdo, $me, empty($in['id']) ? 'created quote' : 'edited quote', $custNm . ' (' . $cur . ' ' . number_format($total, 0) . ')');
        $st = $pdo->prepare("SELECT * FROM quotes WHERE id=?"); $st->execute([$id]);
        echo json_encode(['ok'=>true, 'quote'=>quote_out($st->fetch(PDO::FETCH_ASSOC))]);
        exit;
    }

    throw new Exception('Unknown action.');
} catch (Exception $e) {
    echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
}

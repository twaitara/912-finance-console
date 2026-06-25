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
}

/* normalise + price the line items; returns [cleanItems, sub, tax, total] */
function quote_price($rawItems, $vatRate){
    $items = []; $sub = 0.0;
    foreach ((array)$rawItems as $it){
        $name = trim((string)($it['name'] ?? ''));
        if ($name === '') continue;
        $qty  = round((float)($it['qty'] ?? 0), 2);
        $rate = round((float)($it['rate'] ?? 0), 2);
        if ($qty <= 0) $qty = 1;
        $amount = round($qty * $rate, 2);
        $sub += $amount;
        $items[] = [
            'name'        => substr($name, 0, 190),
            'description' => substr(trim((string)($it['description'] ?? '')), 0, 500),
            'qty'         => $qty,
            'rate'        => $rate,
            'amount'      => $amount,
        ];
    }
    $sub = round($sub, 2);
    $tax = round($sub * (float)$vatRate, 2);
    return [$items, $sub, $tax, round($sub + $tax, 2)];
}

function quote_out(array $r){
    $r['line_items'] = json_decode($r['line_items'] ?: '[]', true) ?: [];
    $r['id'] = (int)$r['id'];
    $r['sub_total'] = (float)$r['sub_total'];
    $r['tax_amount'] = (float)$r['tax_amount'];
    $r['total'] = (float)$r['total'];
    return $r;
}

try {
    $pdo = db();
    quotes_table($pdo);
    $me     = $_SESSION['user'] ?? '';
    $admin  = !empty($_SESSION['is_admin']);
    $vat    = (float)($cfg['vat_rate'] ?? 0.16);

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
        if ($admin) { $st = $pdo->query("SELECT * FROM quotes ORDER BY updated_at DESC"); $rows = $st->fetchAll(PDO::FETCH_ASSOC); }
        else { $st = $pdo->prepare("SELECT * FROM quotes WHERE created_by=? ORDER BY updated_at DESC"); $st->execute([$me]); $rows = $st->fetchAll(PDO::FETCH_ASSOC); }
        echo json_encode(['ok'=>true, 'quotes'=>array_map('quote_out', $rows)]);
        exit;
    }

    if ($action === 'get') { echo json_encode(['ok'=>true, 'quote'=>quote_out($own($in['id'] ?? 0))]); exit; }

    if ($action === 'delete') {
        $row = $own($in['id'] ?? 0);
        $pdo->prepare("DELETE FROM quotes WHERE id=?")->execute([(int)$row['id']]);
        echo json_encode(['ok'=>true]); exit;
    }

    if ($action === 'save') {
        $custId = trim((string)($in['zoho_customer_id'] ?? ''));
        $custNm = trim((string)($in['customer_name'] ?? ''));
        if ($custNm === '') throw new Exception('Choose a customer.');
        [$items, $sub, $tax, $total] = quote_price($in['line_items'] ?? [], $vat);
        if (!$items) throw new Exception('Add at least one line item.');
        $cur   = trim((string)($in['currency'] ?? 'KES')) ?: 'KES';
        $notes = substr(trim((string)($in['notes'] ?? '')), 0, 1000);
        $itemsJson = json_encode($items);

        if (!empty($in['id'])) {
            $row = $own($in['id']);
            // a quote already pushed to Zoho is locked locally (edit it in Zoho)
            if (!empty($row['zoho_estimate_id'])) throw new Exception('Already pushed to Zoho — edit it there.');
            $pdo->prepare("UPDATE quotes SET zoho_customer_id=?, customer_name=?, currency=?, line_items=?, notes=?, sub_total=?, tax_amount=?, total=? WHERE id=?")
                ->execute([$custId, $custNm, $cur, $itemsJson, $notes, $sub, $tax, $total, (int)$row['id']]);
            $id = (int)$row['id'];
        } else {
            $pdo->prepare("INSERT INTO quotes (created_by, created_email, zoho_customer_id, customer_name, currency, line_items, notes, sub_total, tax_amount, total, status)
                           VALUES (?,?,?,?,?,?,?,?,?,?, 'local_draft')")
                ->execute([$me, $_SESSION['email'] ?? '', $custId, $custNm, $cur, $itemsJson, $notes, $sub, $tax, $total]);
            $id = (int)$pdo->lastInsertId();
        }
        $st = $pdo->prepare("SELECT * FROM quotes WHERE id=?"); $st->execute([$id]);
        echo json_encode(['ok'=>true, 'quote'=>quote_out($st->fetch(PDO::FETCH_ASSOC))]);
        exit;
    }

    throw new Exception('Unknown action.');
} catch (Exception $e) {
    echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
}

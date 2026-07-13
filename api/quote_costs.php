<?php
/* api/quote_costs.php — capture the ACTUAL cost of each line on an invoiced job.
   Costs are stored as a cost_rows[] breakdown inside the quote's own line_items JSON
   (no separate table). Prices/profit are stripped server-side for non-admins, so a
   cost capturer never sees what the client was charged.

   Access: admin, the quote's creator, or any user holding the 'costcap' permission —
   and only on quotes whose status is 'invoiced'.

   Actions (POST JSON):
     {action:'get', id}
       -> {ok, admin, quote}                 (non-admin quote is price-stripped)
     {action:'save_costs', id, lines:[{index, cost_rows:[{category,description,qty,unit_cost}]}]}
       -> {ok, admin, quote}                 (merges cost_rows by line index; recomputes totals) */

session_start();
header('Content-Type: application/json; charset=utf-8');
if (empty($_SESSION['auth'])) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Not signed in.']); exit; }
require __DIR__ . '/../db.php';
require __DIR__ . '/../quote_pricing.php';
$cfg = require __DIR__ . '/../config.php';

try {
    $pdo   = db();
    $me    = $_SESSION['user'] ?? '';
    $admin = !empty($_SESSION['is_admin']);
    $vat   = (float)($cfg['vat_rate'] ?? 0.16);
    $tabsS   = (string)($_SESSION['tabs'] ?? '');
    $costcap = ($tabsS === '*') || in_array('costcap', array_map('trim', explode(',', $tabsS)), true);

    $in = json_decode(file_get_contents('php://input'), true); if (!is_array($in)) $in = $_POST;
    $action = $in['action'] ?? 'get';

    /* load an invoiced job the caller is allowed to cost */
    $load = function($id) use ($pdo, $me, $admin, $costcap) {
        $st = $pdo->prepare("SELECT * FROM quotes WHERE id=?"); $st->execute([(int)$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new Exception('Invoice not found.');
        $isOwner = ($row['created_by'] === $me);
        if (!$admin && !$isOwner && !$costcap) throw new Exception('You do not have access to this job.');
        if ($row['status'] !== 'invoiced') throw new Exception('Costs can only be captured once the job has been invoiced.');
        return $row;
    };

    /* shape the response: admins get full prices/profit, everyone else is price-stripped */
    $respond = function(array $row) use ($admin) {
        $q = quote_out($row);
        if ($admin) { echo json_encode(['ok'=>true, 'admin'=>true, 'quote'=>$q]); }
        else        { echo json_encode(['ok'=>true, 'admin'=>false, 'quote'=>quote_strip_prices($q)]); }
    };

    if ($action === 'get') {
        $respond($load($in['id'] ?? 0));
        exit;
    }

    if ($action === 'save_costs') {
        $row = $load($in['id'] ?? 0);
        $items = json_decode($row['line_items'] ?: '[]', true) ?: [];

        // merge submitted cost_rows into the stored lines BY INDEX — names/rates are never touched
        foreach ((array)($in['lines'] ?? []) as $ln) {
            $idx = (int)($ln['index'] ?? -1);
            if ($idx < 0 || !isset($items[$idx])) continue;
            $items[$idx]['cost_rows'] = is_array($ln['cost_rows'] ?? null) ? $ln['cost_rows'] : [];
        }

        // re-price with the existing discount so total_cost / profit refresh (quote_price cleans cost_rows)
        [$clean, $sub, $discAmt, $tax, $total, $costTotal, $profit] =
            quote_price($items, $vat, (float)($row['discount_value'] ?? 0), (string)($row['discount_type'] ?? 'percent'));

        $pdo->prepare("UPDATE quotes SET line_items=?, sub_total=?, tax_amount=?, discount_amount=?, total_cost=?, profit=?, total=? WHERE id=?")
            ->execute([json_encode($clean), $sub, $tax, $discAmt, $costTotal, $profit, $total, (int)$row['id']]);

        require_once __DIR__ . '/../activity_store.php';
        activity_log($pdo, $me, 'captured job costs',
            ($row['zoho_invoice_number'] ?: ('#'.$row['id'])) . ' · ' . ($row['customer_name'] ?? '') . ' (cost ' . number_format($costTotal, 0) . ')');

        $st = $pdo->prepare("SELECT * FROM quotes WHERE id=?"); $st->execute([(int)$row['id']]);
        $respond($st->fetch(PDO::FETCH_ASSOC));
        exit;
    }

    throw new Exception('Unknown action.');
} catch (Exception $e) {
    echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
}

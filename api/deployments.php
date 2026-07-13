<?php
require_once __DIR__ . '/../errors.php';
/** Deployment records: list, create (with Zoho flag write-back), log cost, restore, delete. */
session_start();
require_once __DIR__ . '/../csrf.php'; csrf_guard();
header('Content-Type: application/json');
if (empty($_SESSION['auth']) || empty($_SESSION['is_admin'])) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Owner only.']); exit; }
require __DIR__ . '/../db.php';
require __DIR__ . '/../zoho.php';
require_once __DIR__ . '/../fx.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$pdo = db();

try {
    if ($method === 'GET') {
        $rows = $pdo->query("SELECT * FROM deployments ORDER BY created_at DESC")
                    ->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['ok' => true, 'deployments' => $rows]);
        exit;
    }

    $in = json_decode(file_get_contents('php://input'), true) ?: [];

    if ($method === 'POST' && $action === 'create') {
        // Pull the invoice's ACTUAL sub-total and VAT from Zoho (accurate, handles 0% VAT correctly)
        $subTotal = null; $taxTotal = null;
        if (!empty($in['invoiceId'])) {
            try {
                [$inv, $ic] = zoho_api('GET', 'invoices/' . $in['invoiceId']);
                if ($ic < 400 && !empty($inv['invoice'])) {
                    $subTotal = $inv['invoice']['sub_total'] ?? null;
                    $taxTotal = $inv['invoice']['tax_total'] ?? null;
                    // USD invoice -> convert sub-total & VAT to KES so the ledger stays in KES
                    $cur = strtoupper($inv['invoice']['currency_code'] ?? 'KES');
                    if ($cur === 'USD') {
                        $cacheDir = __DIR__ . '/../data';
                        $fx = usd_kes_rate($cacheDir, zoho_config());
                        $invRate = (float)($inv['invoice']['exchange_rate'] ?? 0);
                        if ($subTotal !== null) $subTotal = to_kes((float)$subTotal, 'USD', $fx, $invRate);
                        if ($taxTotal !== null) $taxTotal = to_kes((float)$taxTotal, 'USD', $fx, $invRate);
                    }
                }
            } catch (Exception $e) { /* leave null — frontend falls back to the configured rate */ }
        }
        $stmt = $pdo->prepare("INSERT INTO deployments
            (invoice_id, invoice_number, client, invoice_value, invoice_subtotal, invoice_tax, amount, cost, purpose, deployed_date, expected_date, status)
            VALUES (?,?,?,?,?,?,?,?,?,?,?, 'Open')");
        $stmt->execute([
            $in['invoiceId']    ?? null,
            $in['invoiceNumber']?? null,
            $in['clientName']   ?? null,
            $in['invoiceValue'] ?? null,
            $subTotal,
            $taxTotal,
            $in['amount'],
            (isset($in['cost']) && $in['cost'] !== '') ? $in['cost'] : null,
            $in['purpose']      ?? null,
            date('Y-m-d'),
            $in['expectedDate'] ?? null,
        ]);
        $id = $pdo->lastInsertId();

        // Write back: tick "Funded by Working Capital" on the invoice in Zoho Books.
        $flagged = null;
        if (!empty($in['invoiceId'])) {
            try {
                $c = zoho_config();
                [$r, $code] = zoho_api('PUT', 'invoices/' . $in['invoiceId'], [
                    'custom_fields' => [
                        ['api_name' => $c['wc_custom_field'], 'value' => true],
                    ],
                ]);
                $flagged = $code < 400;
            } catch (Exception $e) {
                $flagged = false;
            }
        }
        echo json_encode(['ok' => true, 'id' => $id, 'flagged' => $flagged]);
        exit;
    }

    if ($method === 'POST' && $action === 'cost') {
        $pdo->prepare("UPDATE deployments SET cost = ? WHERE id = ?")
            ->execute([$in['cost'], $in['id']]);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($method === 'POST' && $action === 'restore') {
        $pdo->prepare("UPDATE deployments SET status='Restored', restored_amount=?, restored_date=? WHERE id=?")
            ->execute([$in['amount'], $in['date'] ?? date('Y-m-d'), $in['id']]);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($method === 'POST' && $action === 'delete') {
        $pdo->prepare("DELETE FROM deployments WHERE id = ?")->execute([$in['id']]);
        echo json_encode(['ok' => true]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Unknown action']);
} catch (Exception $e) {
    http_response_code(500);
    echo api_fail($e);
}

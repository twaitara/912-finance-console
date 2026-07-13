<?php
require_once __DIR__ . '/../errors.php';
/** Returns live unpaid invoices (KES + USD) straight from Zoho Books.
 *  USD invoices are converted to KES (live rate, with fallbacks) and tagged. */
session_start();
require_once __DIR__ . '/../csrf.php'; csrf_guard();
header('Content-Type: application/json');
if (empty($_SESSION['auth'])) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Not signed in.']); exit; }
require __DIR__ . '/../zoho.php';
require_once __DIR__ . '/../fx.php';

try {
    $c = zoho_config();
    $cacheDir = __DIR__ . '/../data';
    if (!is_dir($cacheDir)) @mkdir($cacheDir, 0775, true);
    $fx = usd_kes_rate($cacheDir, $c);

    $all = [];
    $page = 1;
    do {
        [$data, $code] = zoho_api('GET', 'invoices', null, [
            'status'      => 'unpaid',
            'per_page'    => 200,
            'page'        => $page,
            'sort_column' => 'due_date',
        ]);
        if ($code >= 400) throw new Exception($data['message'] ?? 'Zoho error');
        foreach (($data['invoices'] ?? []) as $inv) {
            $cur = strtoupper($inv['currency_code'] ?? $c['currency']);
            if ($cur !== $c['currency'] && $cur !== 'USD') continue;   // KES + USD only

            $origBalance = (float)$inv['balance'];
            $invRate     = (float)($inv['exchange_rate'] ?? 0);
            $balanceKes  = to_kes($origBalance, $cur, $fx, $invRate);

            $all[] = [
                'invoiceId'     => $inv['invoice_id'],
                'invoiceNumber' => $inv['invoice_number'],
                'clientName'    => $inv['customer_name'],
                'balance'       => $balanceKes,                 // KES (used for the bridge math)
                'dueDate'       => $inv['due_date'],
                'currency'      => $cur,
                'origBalance'   => $origBalance,                // original (USD) amount
                'fxRate'        => ($cur === 'USD') ? round($balanceKes / max($origBalance, 0.0001), 4) : 1,
            ];
        }
        $more = $data['page_context']['has_more_page'] ?? false;
        $page++;
    } while ($more && $page < 15);

    echo json_encode(['ok' => true, 'invoices' => $all, 'count' => count($all),
                      'fx' => ['rate' => round($fx['rate'], 4), 'src' => $fx['src']]]);
} catch (Exception $e) {
    http_response_code(500);
    echo api_fail($e);
}

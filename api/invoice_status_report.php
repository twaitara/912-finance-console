<?php
require_once __DIR__ . '/../errors.php';
/* =============================================================
   api/invoice_status_report.php
   -------------------------------------------------------------
   Lists invoices that are NOT yet sent, split into:
     - draft     : status === 'draft'
     - approved  : any other pre-send status (approval workflow,
                   pending_approval, etc.) — i.e. not draft and
                   not an "issued" status.
   "Issued" = sent, overdue, paid, partially_paid, viewed, void, unpaid.

   Query: ?year=2026&months=6 [&refresh=1] [&format=csv&view=draft|approved]
   Cache: 1 hour (statuses change often). Refresh bypasses it.
   ============================================================= */

session_start();
if (empty($_SESSION['auth'])) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Not signed in.']);
    exit;
}

require __DIR__ . '/../zoho.php';
@set_time_limit(120);
header('Content-Type: application/json; charset=utf-8');

try {
    $c = zoho_config();

    $year   = preg_replace('/[^0-9]/', '', $_GET['year'] ?? date('Y'));
    $months = array_values(array_unique(array_filter(
        array_map('intval', explode(',', (string)($_GET['months'] ?? ''))),
        function ($m) { return $m >= 1 && $m <= 12; }
    )));
    sort($months);
    $mset = array_fill_keys($months, true);
    $mkey = empty($months) ? 'all' : implode('-', $months);

    $format = (strtolower($_GET['format'] ?? 'json') === 'csv') ? 'csv' : 'json';
    $view   = (strtolower($_GET['view'] ?? 'draft') === 'approved') ? 'approved' : 'draft';
    $force  = isset($_GET['refresh']) && $_GET['refresh'] == '1';

    // date window (by created date)
    if (!empty($months)) {
        $dateStart = sprintf('%s-%02d-01', $year, min($months));
        $dateEnd   = date('Y-m-t', strtotime(sprintf('%s-%02d-01', $year, max($months))));
    } else {
        $dateStart = "$year-01-01";
        $dateEnd   = "$year-12-31";
    }

    $cacheDir  = __DIR__ . '/../data';
    if (!is_dir($cacheDir)) @mkdir($cacheDir, 0775, true);
    $cacheFile = $cacheDir . sprintf('/invstatus_v2_%s_%s.json', $year ?: 'all', $mkey);

    $base = null;
    if (!$force && is_file($cacheFile) && (time() - filemtime($cacheFile) < 3600)) { // 1h cache
        $tmp = json_decode(file_get_contents($cacheFile), true);
        if (is_array($tmp)) { $base = $tmp; $base['cached'] = true; }
    }

    if (!is_array($base)) {
        $issued = ['sent'=>1,'overdue'=>1,'paid'=>1,'partially_paid'=>1,'viewed'=>1,'void'=>1,'unpaid'=>1];
        $draft = []; $approved = [];
        $page = 1;
        do {
            [$data, $code] = zoho_api('GET', 'invoices', null, [
                'created_date_start' => $dateStart,
                'created_date_end'   => $dateEnd,
                'sort_column'        => 'created_time',
                'per_page'           => 200,
                'page'               => $page,
            ]);
            if ($code >= 400) throw new Exception($data['message'] ?? 'Zoho error (invoices)');
            $stop = false;
            foreach (($data['invoices'] ?? []) as $inv) {
                $raised = substr($inv['created_time'] ?? $inv['date'] ?? '', 0, 10);
                $ry = (int)substr($raised, 0, 4);
                if ($year) {
                    if ($ry > (int)$year) continue;
                    if ($ry < (int)$year) { $stop = true; break; }
                }
                $status = $inv['status'] ?? '';
                if (isset($issued[$status])) continue;           // already sent/issued — skip
                if (!empty($mset)) {
                    $rm = (int)substr($raised, 5, 2);
                    if (!isset($mset[$rm])) continue;
                }
                $row = [
                    'invoice_number' => $inv['invoice_number'] ?? '',
                    'client'         => $inv['customer_name'] ?? '',
                    'date'           => $raised,
                    'status'         => $status,
                    'currency'       => $inv['currency_code'] ?? '',
                    'total'          => (float)($inv['total'] ?? 0),
                ];
                if ($status === 'draft') $draft[] = $row; else $approved[] = $row;
            }
            $more = $data['page_context']['has_more_page'] ?? false;
            $page++;
        } while (!$stop && $more && $page <= 25);

        $sortByNo = function ($a, $b) {
            return (int)preg_replace('/\D/', '', $a['invoice_number'])
                 <=> (int)preg_replace('/\D/', '', $b['invoice_number']);
        };
        usort($draft, $sortByNo);
        usort($approved, $sortByNo);

        $base = [
            'draft'       => $draft,
            'approved'    => $approved,
            'generatedAt' => date('c'),
            'cached'      => false,
        ];
        @file_put_contents($cacheFile, json_encode($base));
    }

    if ($format === 'csv') {
        $set = ($view === 'approved') ? ($base['approved'] ?? []) : ($base['draft'] ?? []);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="invoices_' . $view . '_' . ($year ?: 'all') . '_' . $mkey . '.csv"');
        $fh = fopen('php://output', 'w');
        fputcsv($fh, ['Invoice Number', 'Client', 'Raised Date', 'Status', 'Currency', 'Total']);
        foreach ($set as $r) fputcsv($fh, [$r['invoice_number'], $r['client'], $r['date'], $r['status'], $r['currency'], $r['total']]);
        fclose($fh);
        exit;
    }

    echo json_encode([
        'ok'            => true,
        'draft'         => $base['draft'] ?? [],
        'approved'      => $base['approved'] ?? [],
        'draftCount'    => count($base['draft'] ?? []),
        'approvedCount' => count($base['approved'] ?? []),
        'generatedAt'   => $base['generatedAt'] ?? date('c'),
        'cached'        => !empty($base['cached']),
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo api_fail($e);
}

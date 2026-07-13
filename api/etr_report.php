<?php
require_once __DIR__ . '/../errors.php';
/* =============================================================
   api/etr_report.php — ETR reconciliation
   -------------------------------------------------------------
   Lists Zoho Books invoices (KES, raised/created in the chosen
   year/month, paid OR unpaid) that have NO matching ETR file in
   the WorkDrive folder.

   Matching: Zoho gives clean "INV-####". Take #### and check
   whether that number appears anywhere in any filename. (Folder
   filenames are messy: INV-####, IN-####, INV####, bare ####.)

   Query: ?year=2026&month=06&status=unpaid [&refresh=1] [&format=csv]
     year   required, >= 2026
     month  0/empty = whole year, else 1..12
     status unpaid (default) | paid
   ============================================================= */

session_start();
if (empty($_SESSION['auth'])) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Not signed in. Open the app and log in first.']);
    exit;
}

define('ETR_INTERNAL', 1);
require __DIR__ . '/../zoho.php';      // zoho_config(), zoho_api()
require __DIR__ . '/../workdrive.php'; // wd_filename_number_map()
require __DIR__ . '/../db.php';        // db()
@set_time_limit(120);

header('Content-Type: application/json; charset=utf-8');

/* invoices marked "no ETR needed" -> set of invoice numbers */
function etr_exclusion_set() {
    try {
        $pdo = db();
        $pdo->exec("CREATE TABLE IF NOT EXISTS etr_exclusions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            invoice_number VARCHAR(50) NOT NULL UNIQUE,
            client VARCHAR(255) NULL,
            reason VARCHAR(255) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $rows = $pdo->query("SELECT invoice_number FROM etr_exclusions")->fetchAll(PDO::FETCH_COLUMN);
        $set = [];
        foreach ($rows as $r) $set[trim($r)] = true;
        return $set;
    } catch (Exception $e) {
        return [];
    }
}

try {
    $c = zoho_config();

    /* ---- inputs ---- */
    $year   = (int)preg_replace('/[^0-9]/', '', $_GET['year'] ?? date('Y'));
    $sParam = strtolower($_GET['status'] ?? 'unpaid');
    $status = in_array($sParam, ['paid', 'all'], true) ? $sParam : 'unpaid';
    $format = (strtolower($_GET['format'] ?? 'json') === 'csv') ? 'csv' : 'json';
    $vParam = strtolower($_GET['view'] ?? 'missing');
    $view   = in_array($vParam, ['matched', 'excluded'], true) ? $vParam : 'missing';
    $force  = isset($_GET['refresh']) && $_GET['refresh'] == '1';
    if ($year < 2026) throw new Exception('This report starts from year 2026.');

    /* months: comma list e.g. "1,2,3" (empty = whole year). Legacy ?month= still works. */
    $months = array_values(array_unique(array_filter(
        array_map('intval', explode(',', (string)($_GET['months'] ?? ''))),
        function ($m) { return $m >= 1 && $m <= 12; }
    )));
    if (empty($months)) {
        $legacy = (int)preg_replace('/[^0-9]/', '', $_GET['month'] ?? '0');
        if ($legacy >= 1 && $legacy <= 12) $months = [$legacy];
    }
    sort($months);
    $mkey = empty($months) ? 'all' : implode('-', $months);

    /* ---- created-date window (spans earliest..latest selected month) ---- */
    if (!empty($months)) {
        $dateStart = sprintf('%04d-%02d-01', $year, min($months));
        $dateEnd   = date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $year, max($months))));
    } else {
        $dateStart = sprintf('%04d-01-01', $year);
        $dateEnd   = sprintf('%04d-12-31', $year);
    }

    /* ---- 24h cache of the heavy scan (blocked from web by .htaccess) ---- */
    $cacheDir  = __DIR__ . '/../data';
    if (!is_dir($cacheDir)) @mkdir($cacheDir, 0775, true);
    $cacheFile = $cacheDir . sprintf('/etr_v4_%04d_%s_%s.json', $year, $mkey, $status);

    $base = null;
    if (!$force && is_file($cacheFile) && (time() - filemtime($cacheFile) < 86400)) {
        $tmp = json_decode(file_get_contents($cacheFile), true);
        if (is_array($tmp)) { $base = $tmp; $base['cached'] = true; }
    }

    if (!is_array($base)) {
        /* ---- 1) invoices for the period (KES, not void) ---- */
        $invoices = [];
        $page = 1;
        do {
            $q = [
                'created_date_start' => $dateStart,
                'created_date_end'   => $dateEnd,
                'sort_column'        => 'created_time',
                'per_page'           => 200,
                'page'               => $page,
            ];
            if ($status !== 'all') $q['status'] = $status;   // 'unpaid' | 'paid'
            [$data, $code] = zoho_api('GET', 'invoices', null, $q);
            if ($code >= 400) throw new Exception('BOOKS invoices (HTTP ' . $code . '): ' . ($data['message'] ?? 'request failed'));
            foreach (($data['invoices'] ?? []) as $inv) {
                if (($inv['currency_code'] ?? 'KES') !== $c['currency']) continue;
                if (($inv['status'] ?? '') === 'void') continue;

                $raised = substr($inv['created_time'] ?? $inv['date'] ?? '', 0, 10);
                if ($raised) {
                    $ry = (int)substr($raised, 0, 4);
                    $rm = (int)substr($raised, 5, 2);
                    if ($ry !== $year) continue;
                    if (!empty($months) && !in_array($rm, $months, true)) continue;
                }

                $invoices[] = [
                    'invoice_number' => trim($inv['invoice_number'] ?? ''),
                    'client'         => $inv['customer_name'] ?? '',
                    'date'           => $raised,
                    'status'         => $inv['status'] ?? '',
                    'total'          => (float)($inv['total'] ?? 0),
                    'balance'        => (float)($inv['balance'] ?? 0),
                ];
            }
            $more = $data['page_context']['has_more_page'] ?? false;
            $page++;
        } while ($more && $page <= 25);

        /* ---- 2) filename number map from WorkDrive (one or more folders) ---- */
        $folderIds = $c['workdrive_folder_ids']
            ?? ['0mqdi73cabe780dcf49adb599e8e650cf893e',   // older invoices (<=3049)
                'gfyj5e8ce417a75ca49a7a9641d2931dbae25'];  // SCANNED COPIES (3050+)
        if (is_string($folderIds)) $folderIds = array_filter(array_map('trim', explode(',', $folderIds)));
        $wd = wd_filename_number_map($folderIds);
        $numMap = $wd['map'];

        /* ---- 3) split into matched (has file) and noFile ---- */
        $noFile = [];
        $matched = [];
        foreach ($invoices as $inv) {
            $core = '';
            if (preg_match('/(\d{3,6})/', $inv['invoice_number'], $mm)) {
                $core = ltrim($mm[1], '0'); if ($core === '') $core = $mm[1];
            }
            $file = '';
            if ($core !== '') {
                if (isset($numMap[$core]))      $file = $numMap[$core];
                elseif (isset($numMap[$mm[1]])) $file = $numMap[$mm[1]];
            }
            if ($file !== '') { $inv['file'] = $file; $matched[] = $inv; }
            else              { $noFile[] = $inv; }
        }

        $sortByNo = function ($a, $b) {
            return (int)preg_replace('/\D/', '', $a['invoice_number'])
                 <=> (int)preg_replace('/\D/', '', $b['invoice_number']);
        };
        usort($noFile, $sortByNo);
        usort($matched, $sortByNo);

        $base = [
            'kesInvoices'  => count($invoices),
            'filesScanned' => $wd['file_count'],
            'matched'      => $matched,
            'noFile'       => $noFile,
            'generatedAt'  => date('c'),
            'cached'       => false,
        ];
        @file_put_contents($cacheFile, json_encode($base));
    }

    /* ---- apply exclusions fresh (so a mark takes effect immediately) ---- */
    $exSet = etr_exclusion_set();
    $missing = []; $excluded = [];
    foreach (($base['noFile'] ?? []) as $inv) {
        if (isset($exSet[$inv['invoice_number']])) $excluded[] = $inv;
        else                                       $missing[]  = $inv;
    }
    $matched = $base['matched'] ?? [];

    /* ---- CSV branch (exports whichever view is requested) ---- */
    if ($format === 'csv') {
        $sets = ['matched' => $matched, 'missing' => $missing, 'excluded' => $excluded];
        $set  = $sets[$view] ?? $missing;
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="etr_' . $view . '_' . $year . '_' . $mkey . '_' . $status . '.csv"');
        $fh = fopen('php://output', 'w');
        if ($view === 'matched') {
            fputcsv($fh, ['Invoice Number', 'Client', 'Raised Date', 'Status', 'Total (KES)', 'Balance (KES)', 'File']);
            foreach ($set as $r) fputcsv($fh, [$r['invoice_number'], $r['client'], $r['date'], $r['status'], $r['total'], $r['balance'], $r['file'] ?? '']);
        } else {
            fputcsv($fh, ['Invoice Number', 'Client', 'Raised Date', 'Status', 'Total (KES)', 'Balance (KES)']);
            foreach ($set as $r) fputcsv($fh, [$r['invoice_number'], $r['client'], $r['date'], $r['status'], $r['total'], $r['balance']]);
        }
        fclose($fh);
        exit;
    }

    echo json_encode([
        'ok'            => true,
        'period'        => ['year' => $year, 'months' => $months, 'status' => $status],
        'kesInvoices'   => $base['kesInvoices'] ?? 0,
        'filesScanned'  => $base['filesScanned'] ?? 0,
        'matchedCount'  => count($matched),
        'missingCount'  => count($missing),
        'excludedCount' => count($excluded),
        'matched'       => $matched,
        'missing'       => $missing,
        'excluded'      => $excluded,
        'generatedAt'   => $base['generatedAt'] ?? date('c'),
        'cached'        => !empty($base['cached']),
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo api_fail($e);
}

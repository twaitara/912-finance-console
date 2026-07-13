<?php
require_once __DIR__ . '/../errors.php';
/* =============================================================
   api/quote_status_report.php
   -------------------------------------------------------------
   Lists quotes (estimates) that are NOT yet sent, split into:
     - approved : status === 'approved'         (approved, awaiting send)
     - pending  : status === 'pending_approval' (awaiting approval)
     - draft    : status === 'draft'
   "Sent/issued" = sent, invoiced, accepted, declined, expired  -> excluded.

   Query: ?year=2026&months=6 [&refresh=1] [&format=csv&view=approved|pending|draft]
   Cache: 1 hour. Refresh bypasses it.
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
    $vw     = strtolower($_GET['view'] ?? 'approved');
    if (!in_array($vw, ['approved', 'pending', 'draft'], true)) $vw = 'approved';
    $force  = isset($_GET['refresh']) && $_GET['refresh'] == '1';

    if (!empty($months)) {
        $dateStart = sprintf('%s-%02d-01', $year, min($months));
        $dateEnd   = date('Y-m-t', strtotime(sprintf('%s-%02d-01', $year, max($months))));
    } else {
        $dateStart = "$year-01-01";
        $dateEnd   = "$year-12-31";
    }

    $cacheDir  = __DIR__ . '/../data';
    if (!is_dir($cacheDir)) @mkdir($cacheDir, 0775, true);
    $cacheFile = $cacheDir . sprintf('/quotestatus_v3_%s_%s.json', $year ?: 'all', $mkey);

    $base = null;
    if (!$force && is_file($cacheFile) && (time() - filemtime($cacheFile) < 3600)) {
        $tmp = json_decode(file_get_contents($cacheFile), true);
        if (is_array($tmp)) { $base = $tmp; $base['cached'] = true; }
    }

    if (!is_array($base)) {
        $issued = ['sent'=>1,'invoiced'=>1,'accepted'=>1,'declined'=>1,'expired'=>1];
        $approved = []; $pending = []; $draft = [];
        $page = 1;
        do {
            [$data, $code] = zoho_api('GET', 'estimates', null, [
                'created_date_start' => $dateStart,
                'created_date_end'   => $dateEnd,
                'sort_column'        => 'created_time',
                'per_page'           => 200,
                'page'               => $page,
            ]);
            if ($code >= 400) throw new Exception($data['message'] ?? 'Zoho error (estimates)');
            $stop = false;
            foreach (($data['estimates'] ?? []) as $q) {
                $raised = substr($q['created_time'] ?? $q['date'] ?? '', 0, 10);
                $ry = (int)substr($raised, 0, 4);
                if ($year) {
                    if ($ry > (int)$year) continue;                 // newer than selected year
                    if ($ry < (int)$year) { $stop = true; break; }  // newest-first list -> stop
                }
                $status = $q['status'] ?? '';
                if (isset($issued[$status])) continue;            // already sent/issued
                if (!in_array($status, ['approved', 'pending_approval', 'draft'], true)) continue;
                if (!empty($mset)) {
                    $rm = (int)substr($raised, 5, 2);
                    if (!isset($mset[$rm])) continue;
                }
                $row = [
                    'number'   => $q['estimate_number'] ?? '',
                    'client'   => $q['customer_name'] ?? '',
                    'date'     => $raised,
                    'status'   => $status,
                    'currency' => $q['currency_code'] ?? '',
                    'total'    => (float)($q['total'] ?? 0),
                ];
                if ($status === 'approved')              $approved[] = $row;
                elseif ($status === 'pending_approval')  $pending[]  = $row;
                else                                     $draft[]    = $row;
            }
            $more = $data['page_context']['has_more_page'] ?? false;
            $page++;
        } while (!$stop && $more && $page <= 25);

        $byNew = function ($a, $b) {
            $c = strcmp($b['date'], $a['date']);                 // date: newest first
            if ($c !== 0) return $c;
            return (int)preg_replace('/\D/', '', $b['number'])   // then number: highest first
                 <=> (int)preg_replace('/\D/', '', $a['number']);
        };
        usort($approved, $byNew); usort($pending, $byNew); usort($draft, $byNew);

        $base = [
            'approved'    => $approved,
            'pending'     => $pending,
            'draft'       => $draft,
            'generatedAt' => date('c'),
            'cached'      => false,
        ];
        @file_put_contents($cacheFile, json_encode($base));
    }

    if ($format === 'csv') {
        $set = $base[$vw] ?? [];
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="quotes_' . $vw . '_' . ($year ?: 'all') . '_' . $mkey . '.csv"');
        $fh = fopen('php://output', 'w');
        fputcsv($fh, ['Quote Number', 'Client', 'Raised Date', 'Status', 'Currency', 'Total']);
        foreach ($set as $r) fputcsv($fh, [$r['number'], $r['client'], $r['date'], $r['status'], $r['currency'], $r['total']]);
        fclose($fh);
        exit;
    }

    echo json_encode([
        'ok'            => true,
        'approved'      => $base['approved'] ?? [],
        'pending'       => $base['pending'] ?? [],
        'draft'         => $base['draft'] ?? [],
        'approvedCount' => count($base['approved'] ?? []),
        'pendingCount'  => count($base['pending'] ?? []),
        'draftCount'    => count($base['draft'] ?? []),
        'generatedAt'   => $base['generatedAt'] ?? date('c'),
        'cached'        => !empty($base['cached']),
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo api_fail($e);
}

<?php
require_once __DIR__ . '/../errors.php';
/** Profit-per-invoice report: all Zoho invoices in a period, matched to their
 *  expenses by reference number (which equals the invoice number). KES only.
 *  Query: ?month=06&year=2026  (month optional; empty = whole year; year empty = all). */
session_start();
require_once __DIR__ . '/../csrf.php'; csrf_guard();
header('Content-Type: application/json');
if (empty($_SESSION['auth'])) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Not signed in.']); exit; }
if (empty($_SESSION['is_admin'])) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'The profit report is admin-only.']); exit; }
require __DIR__ . '/../zoho.php';
@set_time_limit(300);

const MAX_DETAIL = 150; // cap per-invoice detail fetches to stay within time limits

/* Current USD->KES rate: live (keyless API), cached 12h, with fallbacks.
   Returns ['rate'=>float, 'src'=>string, 'asOf'=>iso]. */
function usd_kes_rate($cacheDir, $cfg) {
    $f = $cacheDir . '/fx_usd_kes.json';
    if (is_file($f) && (time() - filemtime($f) < 43200)) {           // 12h cache
        $j = json_decode(@file_get_contents($f), true);
        if (!empty($j['rate'])) return $j;
    }
    $rate = null; $src = '';
    // Primary: open.er-api.com (free, no key)
    foreach ([
        'https://open.er-api.com/v6/latest/USD' => ['rates', 'KES'],
        'https://api.frankfurter.app/latest?from=USD&to=KES' => ['rates', 'KES'],
    ] as $url => $path) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_SSL_VERIFYPEER => true]);
        $res = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        if ($res && $code < 400) {
            $d = json_decode($res, true);
            $v = $d[$path[0]][$path[1]] ?? null;
            if ($v && $v > 50 && $v < 500) { $rate = (float)$v; $src = parse_url($url, PHP_URL_HOST); break; }
        }
    }
    if (!$rate) {                                                    // fallback: config rate, else house rule 128
        $rate = (float)($cfg['usd_rate'] ?? 128);
        $src  = 'fallback';
    }
    $out = ['rate' => $rate, 'src' => $src, 'asOf' => date('c')];
    @file_put_contents($f, json_encode($out));
    return $out;
}

try {
    $c = zoho_config();
    $year  = preg_replace('/[^0-9]/', '', $_GET['year'] ?? '');

    // months: comma list e.g. "1,2,3" (empty = whole year). Legacy ?month= still works.
    $months = array_values(array_unique(array_filter(
        array_map('intval', explode(',', (string)($_GET['months'] ?? ''))),
        function ($m) { return $m >= 1 && $m <= 12; }
    )));
    if (empty($months)) {
        $legacy = (int)preg_replace('/[^0-9]/', '', $_GET['month'] ?? '0');
        if ($legacy >= 1 && $legacy <= 12) $months = [$legacy];
    }
    sort($months);
    $mset  = array_fill_keys($months, true);            // for fast membership test
    $mkey  = empty($months) ? 'all' : implode('-', $months);

    // Period date range (spans earliest..latest selected month)
    $dateStart = $dateEnd = null;
    if ($year) {
        if (!empty($months)) {
            $dateStart = sprintf('%s-%02d-01', $year, min($months));
            $dateEnd   = date('Y-m-t', strtotime(sprintf('%s-%02d-01', $year, max($months))));
        } else {
            $dateStart = "$year-01-01";
            $dateEnd   = "$year-12-31";
        }
    }

    // ---- 24-hour file cache (keyed by period). Bypass with ?refresh=1 ----
    $cacheDir  = __DIR__ . '/../data';
    if (!is_dir($cacheDir)) @mkdir($cacheDir, 0775, true);
    $cacheFile = $cacheDir . '/report_v6_' . ($year ?: 'all') . '_' . $mkey . '.json';
    $force     = isset($_GET['refresh']) && $_GET['refresh'] == '1';

    if (!$force && is_file($cacheFile) && (time() - filemtime($cacheFile) < 86400)) {
        $cached = json_decode(file_get_contents($cacheFile), true);
        if ($cached) { $cached['cached'] = true; echo json_encode($cached); exit; }
    }

    $fx = usd_kes_rate($cacheDir, $c);   // current USD->KES rate (for USD invoices)

    // 1) Invoices in the period
    $invoices = [];
    $page = 1;
    do {
        $q = ['per_page' => 200, 'page' => $page, 'sort_column' => 'created_time'];
        if ($dateStart) { $q['created_date_start'] = $dateStart; $q['created_date_end'] = $dateEnd; }
        [$data, $code] = zoho_api('GET', 'invoices', null, $q);
        if ($code >= 400) throw new Exception($data['message'] ?? 'Zoho error (invoices)');
        foreach (($data['invoices'] ?? []) as $inv) {
            $cur = strtoupper($inv['currency_code'] ?? $c['currency']);
            if ($cur !== $c['currency'] && $cur !== 'USD') continue;     // KES + USD only
            if (($inv['status'] ?? '') === 'void') continue;            // exclude voided invoices
            $raised = substr($inv['created_time'] ?? $inv['date'], 0, 10);   // raised (created) date
            if (!empty($mset)) {                                            // restrict to selected months
                $rm = (int)substr($raised, 5, 2);
                if (!isset($mset[$rm])) continue;
            }
            $invoices[] = [
                'id'       => $inv['invoice_id'],
                'number'   => $inv['invoice_number'],
                'client'   => $inv['customer_name'],
                'date'     => $raised,
                'status'   => $inv['status'],
                'total'    => (float)$inv['total'],
                'currency' => $cur,
                'exRate'   => (float)($inv['exchange_rate'] ?? 1),
            ];
        }
        $more = $data['page_context']['has_more_page'] ?? false;
        $page++;
    } while ($more && $page <= 8);

    // 2) Expenses → cost per invoice number (reference_number = invoice number)
    $costByInvoice = [];
    $page = 1;
    do {
        [$ed, $ec] = zoho_api('GET', 'expenses', null, ['per_page' => 200, 'page' => $page, 'sort_column' => 'date']);
        if ($ec >= 400) throw new Exception($ed['message'] ?? 'Zoho error (expenses)');
        foreach (($ed['expenses'] ?? []) as $e) {
            $ref = trim($e['reference_number'] ?? '');
            if ($ref === '') continue;
            $amt = (float)($e['total_without_tax'] ?? $e['total'] ?? 0);
            $costByInvoice[$ref] = ($costByInvoice[$ref] ?? 0) + $amt;
        }
        $more = $ed['page_context']['has_more_page'] ?? false;
        $page++;
    } while ($more && $page <= 8);

    // 3) Per invoice: real sub_total + tax_total (accurate VAT, handles 0%)
    $rows = [];
    $detailFetched = 0;
    $truncated = false;
    foreach ($invoices as $inv) {
        $sub = $inv['total']; $tax = 0.0;
        if ($detailFetched < MAX_DETAIL) {
            [$d, $dc] = zoho_api('GET', 'invoices/' . $inv['id']);
            $detailFetched++;
            if ($dc < 400 && !empty($d['invoice'])) {
                $sub = (float)($d['invoice']['sub_total'] ?? $inv['total']);
                $tax = (float)($d['invoice']['tax_total'] ?? 0);
            }
        } else {
            $truncated = true;
        }

        // USD -> KES conversion (live rate; if live failed, use the invoice's own Zoho rate, else house rule)
        $isUsd = ($inv['currency'] === 'USD');
        $rateUsed = 1.0;
        if ($isUsd) {
            if ($fx['src'] !== 'fallback')      $rateUsed = $fx['rate'];                     // current live rate
            elseif ($inv['exRate'] > 0)         $rateUsed = $inv['exRate'];                  // invoice's own rate
            else                                $rateUsed = $fx['rate'];                     // 128 / config
        }
        $subK   = $sub * $rateUsed;
        $taxK   = $tax * $rateUsed;
        $totalK = $inv['total'] * $rateUsed;

        $cost = $costByInvoice[$inv['number']] ?? 0;   // expenses are in KES
        $rows[] = [
            'invoiceNumber' => $inv['number'],
            'client'        => $inv['client'],
            'date'          => $inv['date'],
            'status'        => $inv['status'],
            'currency'      => $inv['currency'],
            'fxRate'        => $isUsd ? round($rateUsed, 4) : 1,
            'origTotal'     => $inv['total'],            // total in original currency (USD for USD invoices)
            'origRevenueExVat' => $sub,                  // revenue ex-VAT in original currency
            'origVat'       => $tax,                     // VAT in original currency
            'total'         => $totalK,                  // KES
            'revenueExVat'  => $subK,                    // KES
            'vat'           => $taxK,                    // KES
            'cost'          => $cost,                    // KES
            'hasCost'       => $cost > 0,
            'profit'        => $totalK - $cost,          // profit = invoice total (incl VAT) − cost, per house rule
        ];
    }

    usort($rows, fn($a, $b) => strcmp($b['date'], $a['date']));

    $payload = ['ok' => true, 'rows' => $rows, 'count' => count($rows),
                'truncated' => $truncated, 'generatedAt' => date('c'),
                'fx' => ['rate' => round($fx['rate'], 4), 'src' => $fx['src'], 'asOf' => $fx['asOf']]];
    @file_put_contents($cacheFile, json_encode($payload));   // refresh the 24h cache
    $payload['cached'] = false;
    echo json_encode($payload);
} catch (Exception $e) {
    http_response_code(500);
    echo api_fail($e);
}

<?php
/* Shared USD->KES rate helper. Guarded so files that already define it
   (e.g. report.php has its own copy) won't clash. */
if (!function_exists('usd_kes_rate')) {
    @require_once __DIR__ . '/settings_store.php';
    function usd_kes_rate($cacheDir, $cfg) {
        $f = $cacheDir . '/fx_usd_kes.json';
        if (is_file($f) && (time() - filemtime($f) < 43200)) {           // 12h cache
            $j = json_decode(@file_get_contents($f), true);
            if (!empty($j['rate'])) return $j;
        }
        $rate = null; $src = '';
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
        if (!$rate) {
            $rate = function_exists('app_setting') ? (float)app_setting($cfg, 'usd_rate') : (float)($cfg['usd_rate'] ?? 128);
            $src  = 'fallback';
        }
        $out = ['rate' => $rate, 'src' => $src, 'asOf' => date('c')];
        @file_put_contents($f, json_encode($out));
        return $out;
    }
}

/* Convert an amount in $cur to KES. For USD: live rate if available,
   else the invoice's own Zoho exchange rate, else the fallback (128/config). */
if (!function_exists('to_kes')) {
    function to_kes($amount, $cur, $fx, $invoiceRate = 0) {
        if (strtoupper((string)$cur) !== 'USD') return (float)$amount;
        if (($fx['src'] ?? '') !== 'fallback')  $r = $fx['rate'];
        elseif ($invoiceRate > 0)               $r = $invoiceRate;
        else                                    $r = $fx['rate'];
        return (float)$amount * $r;
    }
}

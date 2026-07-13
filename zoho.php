<?php
/** Zoho OAuth + Books API helper. Refreshes access tokens automatically. */

function zoho_config() {
    static $c = null;
    if ($c === null) $c = require __DIR__ . '/config.php';
    return $c;
}

/** Get a valid access token, refreshing (and caching) as needed. */
function zoho_access_token() {
    $c = zoho_config();
    $cacheFile = __DIR__ . '/data/token.json';
    if (is_file($cacheFile)) {
        $t = json_decode(file_get_contents($cacheFile), true);
        if ($t && isset($t['expires_at']) && $t['expires_at'] > time() + 60) {
            return $t['access_token'];
        }
    }
    $ch = curl_init($c['accounts_url'] . '/oauth/v2/token');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'refresh_token' => $c['refresh_token'],
            'client_id'     => $c['client_id'],
            'client_secret' => $c['client_secret'],
            'grant_type'    => 'refresh_token',
        ]),
    ]);
    $res = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($err) throw new Exception('Token request failed: ' . $err);
    $data = json_decode($res, true);
    if (empty($data['access_token'])) throw new Exception('No access token returned: ' . $res);
    $data['expires_at'] = time() + (int)($data['expires_in'] ?? 3600);
    if (!is_dir(__DIR__ . '/data')) mkdir(__DIR__ . '/data', 0775, true);
    file_put_contents($cacheFile, json_encode($data));
    return $data['access_token'];
}

/** Call the Zoho Books API. Returns [decoded_body, http_code]. */
function zoho_api($method, $path, $body = null, $query = []) {
    $c = zoho_config();
    $token = zoho_access_token();
    $query['organization_id'] = $c['organization_id'];
    $url = $c['api_domain'] . '/books/v3/' . $path . '?' . http_build_query($query);

    $ch = curl_init($url);
    $headers = ['Authorization: Zoho-oauthtoken ' . $token];
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
    ]);
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($err) throw new Exception('API request failed: ' . $err);
    return [json_decode($res, true), (int)$code];
}

/* Canonical VAT tax-id lookup (was copy-pasted in quote_push/quote_invoice/
   quote_update/project_costs). Picks the 16%-or-named-"vat" tax from Zoho and
   caches it to data/zoho_vat.json for 24h; re-fetches if the cache is stale or
   the cached id is empty. Same selection heuristic as before. */
if (!function_exists('zoho_vat_tax_id')) {
    function zoho_vat_tax_id() {
        $cache = __DIR__ . '/data/zoho_vat.json';
        if (is_file($cache) && (time() - filemtime($cache) < 86400)) {
            $t = json_decode(@file_get_contents($cache), true);
            if (!empty($t['tax_id'])) return (string)$t['tax_id'];
        }
        [$data, $code] = zoho_api('GET', 'settings/taxes');
        $id = '';
        if ($code < 400) {
            foreach (($data['taxes'] ?? []) as $tax) {
                $pct = (float)($tax['tax_percentage'] ?? 0);
                $nm  = strtolower((string)($tax['tax_name'] ?? ''));
                if (abs($pct - 16.0) < 0.01 || strpos($nm, 'vat') !== false) { $id = (string)($tax['tax_id'] ?? ''); if (abs($pct - 16.0) < 0.01) break; }
            }
            if (!is_dir(__DIR__ . '/data')) @mkdir(__DIR__ . '/data', 0775, true);
            @file_put_contents($cache, json_encode(['tax_id' => $id]));
        }
        return $id;
    }
}

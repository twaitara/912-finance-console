<?php
/** Zoho OAuth + Books API helper. Refreshes access tokens automatically. */

function zoho_config() {
    static $c = null;
    if ($c === null) $c = require __DIR__ . '/config.php';
    return $c;
}

/** Return the cached access token if still valid (>60s left), else null. */
function zoho_cached_token($cacheFile) {
    if (!is_file($cacheFile)) return null;
    $t = json_decode(@file_get_contents($cacheFile), true);
    if ($t && isset($t['expires_at']) && $t['expires_at'] > time() + 60 && !empty($t['access_token'])) {
        return (string)$t['access_token'];
    }
    return null;
}

/** Get a valid access token, refreshing (and caching) as needed.
 *  Concurrency-safe: the refresh is serialized with an exclusive file lock so two
 *  simultaneous expired-token requests don't both refresh and race on token.json. */
function zoho_access_token() {
    $c = zoho_config();
    $dir = __DIR__ . '/data';
    $cacheFile = $dir . '/token.json';

    // fast path — valid cached token, no lock needed
    $tok = zoho_cached_token($cacheFile);
    if ($tok !== null) return $tok;

    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $lock = @fopen($dir . '/token.lock', 'c');
    if ($lock) flock($lock, LOCK_EX);
    try {
        // double-checked: another process may have refreshed while we waited for the lock
        $tok = zoho_cached_token($cacheFile);
        if ($tok !== null) return $tok;

        $ch = curl_init($c['accounts_url'] . '/oauth/v2/token');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
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
        file_put_contents($cacheFile, json_encode($data), LOCK_EX);   // atomic-ish write under our lock
        return $data['access_token'];
    } finally {
        if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
    }
}

/** Decide whether/how long to wait before retrying a Zoho call.
 *  Returns seconds to sleep, or -1 to give up. 429 (rate-limited = not processed)
 *  is safe to retry for any method; 5xx is ambiguous for writes, so only GET (an
 *  idempotent read) is retried on 5xx — never a POST/PUT/DELETE (avoids duplicate
 *  invoices/expenses). Honors Retry-After (capped) for 429. */
function zoho_retry_delay($code, $method, $attempt, $max, $retryAfter = null) {
    if ($attempt >= $max) return -1;
    $isRead  = (strtoupper((string)$method) === 'GET');
    $retryable = ($code == 429) || ($code >= 500 && $code < 600 && $isRead);
    if (!$retryable) return -1;
    if ($code == 429 && $retryAfter !== null && is_numeric($retryAfter)) {
        return max(1, min((int)$retryAfter, 8));   // honor Retry-After, capped at 8s
    }
    return (int)min(2 ** $attempt, 6);              // backoff: 2s, 4s, cap 6s
}

/** Call the Zoho Books API. Returns [decoded_body, http_code].
 *  Retries transient failures with backoff: 429 for any method, 5xx/network errors
 *  for GET only (see zoho_retry_delay). */
function zoho_api($method, $path, $body = null, $query = []) {
    $c = zoho_config();
    $query['organization_id'] = $c['organization_id'];
    $url = $c['api_domain'] . '/books/v3/' . $path . '?' . http_build_query($query);
    $payload = ($body !== null) ? json_encode($body) : null;
    $isRead  = (strtoupper((string)$method) === 'GET');

    $max = 3;   // 1 attempt + up to 2 retries
    for ($attempt = 1; ; $attempt++) {
        $token = zoho_access_token();
        $ch = curl_init($url);
        $headers = ['Authorization: Zoho-oauthtoken ' . $token];
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HEADER => true,   // capture response headers to read Retry-After
        ]);
        if ($payload !== null) {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $res  = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $hsz  = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err) {   // network/timeout — retry only for idempotent reads
            if ($isRead && $attempt < $max) { sleep((int)min(2 ** $attempt, 6)); continue; }
            throw new Exception('API request failed: ' . $err);
        }

        $rawHeaders = substr($res, 0, $hsz);
        $respBody   = substr($res, $hsz);
        if (preg_match('/^Retry-After:\s*([0-9]+)/im', $rawHeaders, $m)) $retryAfter = $m[1]; else $retryAfter = null;

        $wait = zoho_retry_delay($code, $method, $attempt, $max, $retryAfter);
        if ($wait >= 0) { sleep($wait); continue; }

        return [json_decode($respBody, true), $code];
    }
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

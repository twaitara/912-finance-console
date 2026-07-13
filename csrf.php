<?php
/**
 * CSRF guard: reject state-changing (POST) requests that come from another site.
 * A same-origin request's Origin/Referer host equals its own Host header; a
 * cross-site forgery carries the attacker's host. Layered on SameSite=Lax.
 * No token needed — same-origin use can never be blocked.
 */

if (!function_exists('csrf_guard')) {
    function csrf_guard(): void {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') return;   // only state-changing
        // host-only (strip any :port — HTTP_HOST keeps it, parse_url of Origin does not)
        $host = strtolower(preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? ''));
        $src  = '';
        if (!empty($_SERVER['HTTP_ORIGIN']))      $src = strtolower((string)parse_url($_SERVER['HTTP_ORIGIN'],  PHP_URL_HOST));
        elseif (!empty($_SERVER['HTTP_REFERER'])) $src = strtolower((string)parse_url($_SERVER['HTTP_REFERER'], PHP_URL_HOST));
        else return;   // no Origin/Referer → can't judge; SameSite=Lax is the backstop → allow

        if ($src === '' || $src !== $host) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'Request blocked (cross-site). Please reload the page and try again.']);
            exit;
        }
    }
}

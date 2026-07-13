<?php
/**
 * Shared error helpers. Log the real detail server-side, return a generic
 * message + a short reference id to the client — never leak SQL/Zoho internals.
 */

if (!function_exists('err_ref')) {
    function err_ref($e, string $msg = 'Something went wrong. Please try again.'): string {
        $ref = strtoupper(bin2hex(random_bytes(3)));               // e.g. 9F3A21
        $detail = ($e instanceof \Throwable)
            ? $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine()
            : (string)$e;
        error_log('[912app ref ' . $ref . '] ' . $detail);         // full detail to server log
        return $msg . ' (ref ' . $ref . ')';                       // safe string for the user
    }

    function api_fail($e, array $extra = [], string $msg = 'Something went wrong. Please try again.'): string {
        return json_encode(array_merge(['ok' => false, 'error' => err_ref($e, $msg)], $extra));
    }
}

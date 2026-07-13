<?php
/**
 * Shared error helpers. Log the real detail server-side, return a generic
 * message + a short reference id to the client — never leak SQL/Zoho internals.
 */

if (!function_exists('err_ref')) {
    function err_ref($e, string $msg = 'Something went wrong. Please try again.'): string {
        $ref = strtoupper(bin2hex(random_bytes(3)));               // e.g. 9F3A21
        $isThrow = ($e instanceof \Throwable);

        // structured record — searchable by ref, with SQLSTATE for DB errors and the request path
        $rec = [
            'ts'   => date('c'),
            'ref'  => $ref,
            'type' => $isThrow ? get_class($e) : 'error',
            'msg'  => $isThrow ? $e->getMessage() : (string)$e,
            'file' => $isThrow ? $e->getFile() : '',
            'line' => $isThrow ? $e->getLine() : 0,
            'req'  => trim(($_SERVER['REQUEST_METHOD'] ?? '') . ' ' . ($_SERVER['REQUEST_URI'] ?? ($_SERVER['SCRIPT_NAME'] ?? ''))),
            'user' => $_SESSION['user'] ?? ($_SESSION['ben_user'] ?? null),
        ];
        if ($e instanceof \PDOException) {
            $rec['sqlstate']    = (string)($e->errorInfo[0] ?? $e->getCode());
            $rec['driver_code'] = $e->errorInfo[1] ?? null;
        }

        // JSON line to a dedicated, web-denied log (data/ is blocked by .htaccess); never fatal
        $dir = __DIR__ . '/data';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        @file_put_contents($dir . '/error.log', json_encode($rec) . "\n", FILE_APPEND | LOCK_EX);

        // also a concise line to the PHP error log
        $sql = isset($rec['sqlstate']) ? (' [SQLSTATE ' . $rec['sqlstate'] . ']') : '';
        error_log('[912app ref ' . $ref . '] ' . $rec['type'] . $sql . ': ' . $rec['msg'] . ' @ ' . $rec['file'] . ':' . $rec['line']);

        return $msg . ' (ref ' . $ref . ')';                       // safe string for the user
    }

    function api_fail($e, array $extra = [], string $msg = 'Something went wrong. Please try again.'): string {
        return json_encode(array_merge(['ok' => false, 'error' => err_ref($e, $msg)], $extra));
    }
}

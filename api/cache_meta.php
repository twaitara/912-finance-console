<?php
require_once __DIR__ . '/../errors.php';
/* api/cache_meta.php — remembers when the full cache was last pulled from Zoho.
   GET  -> {ok, last: ISO8601|null}
   POST -> stamps now, returns {ok, last}. Stored in data/cache_meta.json. */
session_start();
require_once __DIR__ . '/../csrf.php'; csrf_guard();
header('Content-Type: application/json');
if (empty($_SESSION['auth'])) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Not signed in.']); exit; }
$f = __DIR__ . '/../data/cache_meta.json';
try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $dir = __DIR__ . '/../data';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $payload = ['last' => date('c')];
        if (@file_put_contents($f, json_encode($payload)) === false) {
            throw new Exception('Could not write cache_meta.json (data/ must be writable, 755).');
        }
        echo json_encode(['ok' => true] + $payload);
        exit;
    }
    $last = null;
    if (is_file($f)) {
        $j = json_decode(@file_get_contents($f), true);
        if (is_array($j) && !empty($j['last'])) $last = $j['last'];
    }
    echo json_encode(['ok' => true, 'last' => $last]);
} catch (Exception $e) {
    echo api_fail($e);
}

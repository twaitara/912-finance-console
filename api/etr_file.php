<?php
require_once __DIR__ . '/../errors.php';
/* api/etr_file.php — stream a WorkDrive file's bytes inline (for previewing a scanned ETR file).
   Same-origin proxy so images/PDFs preview in the app without exposing the WorkDrive token. */
session_start();
if (empty($_SESSION['auth'])) { http_response_code(401); header('Content-Type: text/plain'); echo 'Not signed in.'; exit; }
require __DIR__ . '/../workdrive.php';
$id = preg_replace('/[^A-Za-z0-9_\-]/', '', (string)($_GET['id'] ?? ''));
if ($id === '') { http_response_code(400); header('Content-Type: text/plain'); echo 'No file id.'; exit; }
try {
    $token = wd_access_token();
    $url   = wd_base() . '/download/' . rawurlencode($id);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120, CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER => ['Authorization: Zoho-oauthtoken ' . $token],
    ]);
    $body  = curl_exec($ch);
    $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ctype = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    if ($code < 200 || $code >= 300 || $body === false) {
        http_response_code(502); header('Content-Type: text/plain');
        echo 'Preview unavailable (HTTP ' . (int)$code . '). Use the Open link instead.'; exit;
    }
    header('Content-Type: ' . ($ctype ?: 'application/octet-stream'));
    header('Content-Disposition: inline');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=300');
    echo $body;
} catch (Exception $e) {
    http_response_code(500); header('Content-Type: text/plain'); echo 'Preview error: ' . err_ref($e);
}

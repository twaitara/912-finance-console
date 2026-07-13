<?php
require_once __DIR__ . '/../errors.php';
/* api/quote_pdf.php — stream a quote's Zoho estimate as a PDF (so the creator can
   download/share the file). GET ?id=NN. Owner or the quote's creator only. */
session_start();
if (empty($_SESSION['auth'])) { http_response_code(401); header('Content-Type: application/json'); echo json_encode(['ok'=>false,'error'=>'Not signed in.']); exit; }
require __DIR__ . '/../db.php';
require __DIR__ . '/../zoho.php';
@set_time_limit(90);

try {
    $pdo = db();
    $me    = $_SESSION['user'] ?? '';
    $admin = !empty($_SESSION['is_admin']);

    $id = (int)($_GET['id'] ?? 0); if ($id <= 0) throw new Exception('No quote.');
    $st = $pdo->prepare("SELECT * FROM quotes WHERE id=?"); $st->execute([$id]);
    $q = $st->fetch(PDO::FETCH_ASSOC);
    if (!$q) throw new Exception('Quote not found.');
    if (!$admin && $q['created_by'] !== $me) throw new Exception('Not your quote.');
    if (empty($q['zoho_estimate_id'])) throw new Exception('This quote is not in Zoho yet.');

    $cfg   = zoho_config();
    $token = zoho_access_token();
    $url   = $cfg['api_domain'] . '/books/v3/estimates/' . rawurlencode($q['zoho_estimate_id'])
           . '?accept=pdf&organization_id=' . rawurlencode($cfg['organization_id']);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_HTTPHEADER     => ['Authorization: Zoho-oauthtoken ' . $token],
    ]);
    $pdf  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ctype= curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    if ($code >= 400 || $pdf === false || stripos((string)$ctype, 'application/json') !== false) {
        http_response_code(502);
        header('Content-Type: application/json');
        echo json_encode(['ok'=>false, 'error'=>'Could not fetch the PDF from Zoho.']);
        exit;
    }

    $name = ($q['zoho_estimate_number'] ?: ('quote-' . $id)) . '.pdf';
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $name . '"');
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
} catch (Exception $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo api_fail($e);
}

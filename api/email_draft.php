<?php
require_once __DIR__ . '/../errors.php';
/* api/email_draft.php  (POST)
   Body JSON: { to, subject, html }
   Creates a DRAFT in the user's Zoho Mail (does not send). */
session_start();
header('Content-Type: application/json; charset=utf-8');
if (empty($_SESSION['auth'])) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Not signed in.']); exit; }

require __DIR__ . '/../mail.php';
@set_time_limit(60);

try {
    $in = json_decode(file_get_contents('php://input'), true);
    if (!is_array($in)) $in = $_POST;

    $to      = trim($in['to'] ?? '');
    $subject = trim($in['subject'] ?? '');
    $html    = (string)($in['html'] ?? '');
    if ($to === '')      throw new Exception('No recipient email.');

    // accept multiple recipients separated by comma OR semicolon; validate each
    $parts = preg_split('/[;,]+/', $to);
    $valid = [];
    foreach ($parts as $p) {
        $p = trim($p);
        if ($p === '') continue;
        if (!filter_var($p, FILTER_VALIDATE_EMAIL)) throw new Exception('Not a valid email address: ' . $p);
        $valid[] = $p;
    }
    if (!$valid) throw new Exception('No valid recipient email.');
    $to = implode(',', $valid);   // Zoho Mail expects comma-separated

    if ($subject === '') $subject = 'Statement of account';
    if ($html === '')    throw new Exception('Email body is empty.');

    $acct = mail_primary_account();
    if (empty($acct['accountId'])) throw new Exception('Could not resolve your Mail account id.');

    $payload = [
        'mode'        => 'draft',
        'fromAddress' => $acct['from'],
        'toAddress'   => $to,
        'subject'     => $subject,
        'content'     => $html,
        'mailFormat'  => 'html',
    ];

    [$resp, $code] = mail_api('POST', '/api/accounts/' . rawurlencode($acct['accountId']) . '/messages', $payload);
    if ($code >= 400) {
        $m = $resp['data']['moreInfo'] ?? ($resp['status']['description'] ?? ('Mail API error ' . $code));
        throw new Exception($m);
    }

    echo json_encode(['ok'=>true, 'from'=>$acct['from'], 'result'=>$resp]);

} catch (Exception $e) {
    http_response_code(500);
    echo api_fail($e);
}

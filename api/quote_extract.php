<?php
/* api/quote_extract.php — "Import a quote from a file".
   Admin only. Accepts an uploaded PDF / image / CSV of a quote, sends it to
   Claude, and returns structured line items to pre-fill the quote builder.
   The user then picks the real Zoho customer, reviews, and saves as usual. */
session_start();
require_once __DIR__ . '/../csrf.php'; csrf_guard();
require_once __DIR__ . '/../errors.php';
header('Content-Type: application/json; charset=utf-8');
if (empty($_SESSION['auth']) || empty($_SESSION['is_admin'])) { http_response_code(403); echo json_encode(['ok'=>false, 'error'=>'Admins only.']); exit; }
require __DIR__ . '/../zoho.php';
@set_time_limit(120);

try {
$cfg = zoho_config();
$KEY = trim((string)($cfg['anthropic_api_key'] ?? ''));
if ($KEY === '') { echo json_encode(['ok'=>false, 'error'=>'AI isn’t set up yet — add anthropic_api_key to config.php on the server.']); exit; }

if (empty($_FILES['file']) || (($_FILES['file']['error'] ?? 1) !== UPLOAD_ERR_OK)) { echo json_encode(['ok'=>false, 'error'=>'No file received.']); exit; }
$f = $_FILES['file'];
if ((int)($f['size'] ?? 0) > 10 * 1024 * 1024) { echo json_encode(['ok'=>false, 'error'=>'File too large (max 10 MB).']); exit; }
$data = @file_get_contents($f['tmp_name']);
if ($data === false || $data === '') { echo json_encode(['ok'=>false, 'error'=>'Could not read the file.']); exit; }
$ext = strtolower(pathinfo((string)($f['name'] ?? ''), PATHINFO_EXTENSION));

$instruction = 'Extract this sales quote / estimate into STRICT JSON with exactly this shape and nothing else: '
  . '{"customer_name": string, "currency": 3-letter code (default "KES"), "subject": string, "reference": string, '
  . '"items": [{"name": string, "description": string, "quantity": number, "rate": number}]}. '
  . '"rate" is the unit price EXCLUDING tax. If a value is missing use "" or 0. Return ONLY the JSON object — no prose, no code fences.';

$content = [];
if ($ext === 'pdf' || (($f['type'] ?? '') === 'application/pdf')) {
    $content[] = ['type'=>'document', 'source'=>['type'=>'base64', 'media_type'=>'application/pdf', 'data'=>base64_encode($data)]];
} elseif (in_array($ext, ['png', 'jpg', 'jpeg', 'webp', 'gif'], true)) {
    $mt = ($ext === 'jpg') ? 'image/jpeg' : ('image/' . $ext);
    $content[] = ['type'=>'image', 'source'=>['type'=>'base64', 'media_type'=>$mt, 'data'=>base64_encode($data)]];
} elseif (in_array($ext, ['csv', 'tsv', 'txt'], true)) {
    $content[] = ['type'=>'text', 'text'=>"Quote file contents:\n\n" . mb_substr($data, 0, 40000)];
} else {
    echo json_encode(['ok'=>false, 'error'=>'Unsupported file type. Please use a PDF, an image (PNG/JPG), or a CSV.']); exit;
}
$content[] = ['type'=>'text', 'text'=>$instruction];

$payload = ['model'=>'claude-opus-4-8', 'max_tokens'=>2000, 'messages'=>[['role'=>'user', 'content'=>$content]]];
$ch = curl_init('https://api.anthropic.com/v1/messages');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>90, CURLOPT_POST=>true,
    CURLOPT_POSTFIELDS=>json_encode($payload),
    CURLOPT_HTTPHEADER=>['Content-Type: application/json', 'x-api-key: ' . $KEY, 'anthropic-version: 2023-06-01']]);
$res = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
if ($err) { echo json_encode(['ok'=>false, 'error'=>'AI request failed. Please try again.']); exit; }
$d = json_decode($res, true);
if ($code >= 400) { echo json_encode(['ok'=>false, 'error'=>('AI error: ' . ($d['error']['message'] ?? 'unknown'))]); exit; }

$txt = '';
foreach (($d['content'] ?? []) as $b) { if (($b['type'] ?? '') === 'text') $txt .= $b['text']; }
$txt = trim($txt);
$txt = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $txt);        // strip code fences
if (preg_match('/\{.*\}/s', $txt, $m)) $txt = $m[0];                 // isolate the JSON object
$q = json_decode($txt, true);
if (!is_array($q)) { echo json_encode(['ok'=>false, 'error'=>'Could not read a quote from that file. Try a clearer PDF, an image, or a CSV.']); exit; }

$items = [];
foreach (($q['items'] ?? []) as $it) {
    if (!is_array($it)) continue;
    $nm = trim((string)($it['name'] ?? '')); $ds = trim((string)($it['description'] ?? ''));
    if ($nm === '' && $ds === '') continue;
    $qty = (float)($it['quantity'] ?? ($it['qty'] ?? 1));
    $items[] = ['name'=>mb_substr($nm !== '' ? $nm : $ds, 0, 200), 'description'=>mb_substr($nm !== '' ? $ds : '', 0, 500),
        'quantity'=>$qty > 0 ? $qty : 1, 'rate'=>(float)($it['rate'] ?? 0)];
}
$cur = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', (string)($q['currency'] ?? 'KES')), 0, 3));
echo json_encode(['ok'=>true, 'quote'=>[
    'customer_name'=>mb_substr(trim((string)($q['customer_name'] ?? '')), 0, 190),
    'currency'=>$cur !== '' ? $cur : 'KES',
    'subject'=>mb_substr(trim((string)($q['subject'] ?? '')), 0, 200),
    'reference'=>mb_substr(trim((string)($q['reference'] ?? '')), 0, 100),
    'items'=>$items,
]]);
} catch (\Throwable $e) {
    echo api_fail($e, [], 'Could not read that file. Please try a clearer PDF, image or CSV.');
}

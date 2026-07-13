<?php
/* ============================================================
   api/audrey.php
   Unpaid invoices for the Dunhill Consulting client set.
   Reuses config.php + zoho.php (zoho_api() -> [$data,$http_code],
   token cached in data/token.json). Read-only. No DB writes.
   ============================================================ */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../zoho.php';

/* --- Auth guard -------------------------------------------------
   Match this to whatever your other api/*.php endpoints use.
   If your session flag differs (e.g. $_SESSION['logged_in']),
   change the line below to match. Left tolerant so a logged-in
   browser session is accepted and anonymous hits are blocked. */
if (session_status() === PHP_SESSION_NONE) { @session_start();
require_once __DIR__ . '/../csrf.php'; csrf_guard(); }
$__authed = ($_SESSION['auth'] ?? $_SESSION['logged_in'] ?? $_SESSION['ok'] ?? false);
if (!$__authed) {
  http_response_code(401);
  header('Content-Type: application/json');
  echo json_encode(['error' => 'not_authenticated']);
  exit;
}

header('Content-Type: application/json');

$ORG = defined('ZOHO_ORG_ID') ? ZOHO_ORG_ID
     : (isset($org_id) ? $org_id
     : (isset($ORG_ID) ? $ORG_ID
     : '761843956'));

/* --- The curated Dunhill client set (36 contacts) --------------
   These are the contact/customer IDs whose billing email is
   @dunhillconsulting.com, MINUS the two "Dunhill Consulting"
   head-office records (KES + USD) you asked to exclude.
   To add/remove a client later, edit this list. */
$DUNHILL = [
  '2876567000000082003' => 'Avacado Investments',
  '2876567000000245478' => 'Avacado Investments (2)',
  '2876567000001428001' => 'Birchwood Villas',
  '2876567000001795001' => 'Bishop Properties',
  '2876567000000535001' => 'Broadway Enterprises Limited',
  '2876567000003016001' => 'Chasewood Apartments',
  '2876567000009216050' => 'Citadel',
  '2876567000000245100' => 'Delta Riverside Management Co. Ltd',
  '2876567000001590001' => 'Dunhill Towers',
  '2876567000003206081' => 'Elgon Management Company Limited',
  '2876567000000245720' => 'Empress Office Suites',
  '2876567000000390053' => 'Fairacres Development Ltd C/o Dunhill',
  '2876567000002472001' => 'Habel',
  '2876567000003863261' => 'Krishna Residency',
  '2876567000000245641' => 'Krystal Investment Ltd',
  '2876567000000245521' => 'Kusi Lane Management - Royal Apartments',
  '2876567000003847425' => 'Laxmi Plaza',
  '2876567000007552001' => 'Magnolia',
  '2876567000000245564' => 'North Park Management Ltd - Park Place',
  '2876567000007667001' => 'Office Suites',
  '2876567000000245361' => 'Oz Management (Citadel)',
  '2876567000000807001' => 'Parque Management Limited',
  '2876567000001238001' => 'Royal Building Management Ltd',
  '2876567000001382001' => 'Ruaraka Business Park',
  '2876567000002426041' => 'Sage Jiva Management Ltd',
  '2876567000000245176' => 'Samar Gardens',
  '2876567000000245277' => 'Samar Heights',
  '2876567000000245133' => 'Sandalwood Brookside',
  '2876567000007192001' => 'Savanna Business Park',
  '2876567000003289001' => 'Savannah',
  '2876567000001305001' => 'Sports Road Furnished Apartments',
  '2876567000000186001' => 'Taarifa Gardens Holding Ltd',
  '2876567000004222003' => 'The Promanade',
  '2876567000002012021' => 'The Residences',
  '2876567000009565001' => 'The Residences (2)',
  '2876567000002736001' => 'Tree Tops Apartments',
];

/* --- Pull unpaid invoices and filter to the set ---------------- */
$rows = [];
$total_kes = 0.0;
$page = 1;

while (true) {
  $path = "invoices?organization_id={$ORG}&status=unpaid&per_page=200&page={$page}"
        . "&sort_column=due_date&sort_order=A";
  list($data, $code) = zoho_api($path);

  if ($code !== 200 || !is_array($data) || empty($data['invoices'])) {
    if ($page === 1 && $code !== 200) {
      http_response_code(502);
      echo json_encode(['error' => 'zoho_error', 'http' => $code]);
      exit;
    }
    break;
  }

  foreach ($data['invoices'] as $inv) {
    $cid = $inv['customer_id'] ?? '';
    if (!isset($DUNHILL[$cid])) { continue; }
    if (($inv['status'] ?? '') === 'void') { continue; }

    $bal  = (float)($inv['balance'] ?? 0);
    if ($bal <= 0) { continue; }

    $cur  = $inv['currency_code'] ?? 'KES';
    $rate = (float)($inv['exchange_rate'] ?? 1);
    if ($rate <= 0) { $rate = 1; }
    $kes  = ($cur === 'KES') ? $bal : round($bal * $rate, 2);
    $total_kes += $kes;

    $rows[] = [
      'client'      => $inv['customer_name'] ?? ($DUNHILL[$cid]),
      'invoice'     => $inv['invoice_number'] ?? '',
      'invoice_id'  => $inv['invoice_id'] ?? '',
      'date'        => $inv['date'] ?? '',
      'due_date'    => $inv['due_date'] ?? '',
      'due_days'    => $inv['due_days'] ?? '',
      'status'      => $inv['status'] ?? '',
      'currency'    => $cur,
      'balance'     => $bal,
      'kes'         => $kes,
      'url'         => trim($inv['invoice_url'] ?? ''),
    ];
  }

  if (empty($data['page_context']['has_more_page'])) { break; }
  $page++;
  if ($page > 25) { break; } // hard stop, quota safety
}

/* Most-overdue first: sort by due_date ascending (blanks last) */
usort($rows, function ($a, $b) {
  $da = $a['due_date'] ?: '9999-99-99';
  $db = $b['due_date'] ?: '9999-99-99';
  return strcmp($da, $db);
});

echo json_encode([
  'as_of'     => date('Y-m-d H:i'),
  'count'     => count($rows),
  'total_kes' => $total_kes,
  'rows'      => $rows,
]);

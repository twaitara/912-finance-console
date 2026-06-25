<?php
/* api/job_card.php — printable Delivery Note / Job Card for a quote (no prices).
   GET ?id=NN  -> renders an A4 print page. Owner or the quote's creator.
   The quote should already be invoiced (use Generate Job Card, which converts first). */
session_start();
if (empty($_SESSION['auth'])) { http_response_code(401); echo 'Not signed in.'; exit; }
require __DIR__ . '/../db.php';
require __DIR__ . '/../zoho.php';
$cfg = require __DIR__ . '/../config.php';

function jc_esc($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

try {
    $pdo = db();
    $me    = $_SESSION['user'] ?? '';
    $admin = !empty($_SESSION['is_admin']);

    $id = (int)($_GET['id'] ?? 0); if ($id <= 0) throw new Exception('No quote.');
    $st = $pdo->prepare("SELECT * FROM quotes WHERE id=?"); $st->execute([$id]);
    $q = $st->fetch(PDO::FETCH_ASSOC);
    if (!$q) throw new Exception('Quote not found.');
    if (!$admin && $q['created_by'] !== $me) throw new Exception('Not your quote.');

    $items  = json_decode($q['line_items'] ?: '[]', true) ?: [];
    $docNo  = $q['zoho_invoice_number'] ?: ($q['zoho_estimate_number'] ?: ('Q-' . $id));
    $date   = date('d M Y');

    // company (letterhead) — defaults match the org; overridable in config.php
    $coName = $cfg['business_name']    ?? 'Nine One Two Holdings';
    $coPin  = $cfg['company_pin']      ?? 'P051475285Q';
    $coAddr = $cfg['company_address']  ?? "(UN Crescent Road)\nGate No 80 Gigiri\nNairobi Nairobi 7928\nKenya";

    // logo: a configured URL wins; else auto-detect a logo file dropped in the app root; else the 912 badge
    $logoUrl = trim((string)($cfg['company_logo_url'] ?? ''));
    if ($logoUrl === '') {
        foreach (['logo.png','logo.jpg','logo.jpeg','logo.webp','logo.svg','logo.gif'] as $cand) {
            if (is_file(__DIR__ . '/../' . $cand)) { $logoUrl = '../' . $cand; break; }
        }
    }

    // customer KRA PIN — pull from the Zoho contact (tax_reg_no or a PIN custom field)
    $custPin = '';
    if (!empty($q['zoho_customer_id'])) {
        [$ct, $cc] = zoho_api('GET', 'contacts/' . rawurlencode($q['zoho_customer_id']));
        if ($cc < 400 && !empty($ct['contact'])) {
            $custPin = trim((string)($ct['contact']['tax_reg_no'] ?? ''));
            if ($custPin === '') {
                foreach (($ct['contact']['custom_fields'] ?? []) as $f) {
                    $lbl = strtolower((string)($f['label'] ?? '') . ($f['placeholder'] ?? ''));
                    if (strpos($lbl, 'pin') !== false || strpos($lbl, 'kra') !== false) { $custPin = trim((string)($f['value'] ?? '')); if ($custPin!=='') break; }
                }
            }
        }
    }

    $rows = '';
    $n = 0;
    foreach ($items as $it) {
        $n++;
        $desc = jc_esc($it['name'] ?? '') . ((!empty($it['description'])) ? '<div style="color:#5a6b7b;font-size:11px;margin-top:2px">' . jc_esc($it['description']) . '</div>' : '');
        $qty  = rtrim(rtrim(number_format((float)($it['qty'] ?? 0), 2), '0'), '.');
        $rows .= '<tr><td class="c" style="width:34px">' . $n . '</td><td>' . $desc . '</td><td class="r" style="width:90px">' . jc_esc($qty) . '</td></tr>';
    }
    $addrHtml = nl2br(jc_esc($coAddr));

    header('Content-Type: text/html; charset=utf-8');
    ?><!doctype html><html><head><meta charset="utf-8">
<title>Job Card <?php echo jc_esc($docNo); ?></title>
<style>
  @page{size:A4;margin:14mm}
  *{box-sizing:border-box}
  body{font-family:Arial,Helvetica,sans-serif;color:#15202B;margin:0;background:#eef1f5}
  .sheet{background:#fff;max-width:780px;margin:18px auto;border:1px solid #C9D2DD;border-radius:4px;padding:0}
  .pad{padding:26px 30px}
  .top{display:flex;justify-content:space-between;align-items:flex-start;gap:20px;border-bottom:1px solid #C9D2DD;padding:26px 30px}
  .logo{width:52px;height:52px;border-radius:50%;background:#F56F00;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:17px;flex:0 0 auto;margin-right:14px}
  .logo-img{height:60px;width:auto;max-width:180px;object-fit:contain;flex:0 0 auto;margin-right:16px}
  .co{display:flex}
  .co .name{font-weight:700;font-size:17px}
  .co .meta{font-size:11.5px;color:#3f4d5c;line-height:1.5;margin-top:3px}
  .title{text-align:right}
  .title .t{font-size:26px;color:#3f4d5c;font-weight:400;line-height:1.1;letter-spacing:.5px}
  .title .no{font-size:12px;margin-top:8px}
  .rowline{display:flex;border-bottom:1px solid #C9D2DD}
  .rowline .k{padding:8px 30px;font-size:12px;color:#3f4d5c;font-weight:700}
  .billto .lbl{padding:8px 30px 2px;font-size:11px;color:#3f4d5c;font-weight:700;border-bottom:1px solid #C9D2DD}
  .billto .who{padding:8px 30px 16px}
  .billto .who b{font-size:13px}
  .billto .who .pin{font-size:11.5px;color:#3f4d5c;margin-top:2px}
  table.items{width:100%;border-collapse:collapse;margin:0}
  table.items th{background:#3a6ea5;color:#fff;font-size:11px;text-align:left;padding:8px 12px;font-weight:600}
  table.items th.r,table.items td.r{text-align:right}
  table.items th.c,table.items td.c{text-align:center}
  table.items td{padding:10px 12px;font-size:12px;border-bottom:1px solid #E6EAF0;vertical-align:top}
  .body{min-height:430px;padding:0 0 0 0}
  .sign{display:flex;justify-content:flex-end;padding:60px 30px 30px;font-size:12.5px;color:#15202B}
  .sign span{border-top:1px solid #8a98a8;padding-top:6px;min-width:230px;text-align:center}
  .bar{position:fixed;top:0;left:0;right:0;background:#15202B;color:#fff;padding:8px 14px;display:flex;gap:10px;justify-content:center;align-items:center;font-size:13px}
  .bar button{background:#F56F00;color:#fff;border:none;padding:7px 16px;border-radius:7px;font-weight:600;cursor:pointer;font-family:inherit}
  .bar a{color:#9AA7B8;text-decoration:none;font-size:12px}
  @media print{.bar{display:none}body{background:#fff}.sheet{border:none;margin:0;max-width:none}}
</style></head>
<body>
  <div class="bar"><span>Job Card <?php echo jc_esc($docNo); ?></span><button onclick="window.print()">Print / Save PDF</button><a href="#" onclick="window.close();return false;">Close</a></div>
  <div class="sheet">
    <div class="top">
      <div class="co"><?php echo $logoUrl !== '' ? '<img class="logo-img" src="'.jc_esc($logoUrl).'" alt="'.jc_esc($coName).'">' : '<div class="logo">912</div>'; ?>
        <div><div class="name"><?php echo jc_esc($coName); ?></div>
          <div class="meta">KRA PIN: <?php echo jc_esc($coPin); ?><br><?php echo $addrHtml; ?></div></div>
      </div>
      <div class="title"><div class="t">DELIVERY NOTE /<br>JOB CARD</div>
        <div class="no"># <b><?php echo jc_esc($docNo); ?></b></div></div>
    </div>
    <div class="rowline"><div class="k">Date</div><div class="k" style="font-weight:400">: <b><?php echo jc_esc($date); ?></b></div></div>
    <div class="billto">
      <div class="lbl">Bill To</div>
      <div class="who"><b><?php echo jc_esc($q['customer_name'] ?: '—'); ?></b>
        <?php if ($custPin !== '') echo '<div class="pin">KRA PIN: ' . jc_esc($custPin) . '</div>'; ?></div>
    </div>
    <div class="body">
      <table class="items">
        <thead><tr><th class="c" style="width:34px">#</th><th>Item &amp; Description</th><th class="r" style="width:90px">Qty</th></tr></thead>
        <tbody><?php echo $rows ?: '<tr><td colspan="3" style="text-align:center;color:#8a98a8;padding:18px">No items.</td></tr>'; ?></tbody>
      </table>
    </div>
    <div class="sign"><span>Authorized Signature</span></div>
  </div>
  <div style="text-align:center;padding:14px 12px 18px;line-height:1.7">
    <div style="font-size:11px;color:#64748B">This system is designed for <b>912 Holdings</b>, Zone Fibre Limited, Waitara Holdings Limited, Smart Zone Fibre Limited &amp; Global IT Limited</div>
    <div style="font-size:10.5px;color:#E07000;margin-top:4px">&#9888; If you can see information of a company you are not associated with, report immediately to <b>Njuguna Waitara — 0722 974 970</b></div>
  </div>
</body></html><?php
} catch (Exception $e) {
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo '<p style="font-family:Arial;padding:20px;color:#b00">Could not generate job card: ' . jc_esc($e->getMessage()) . '</p>';
}

<?php
require_once __DIR__ . '/../errors.php';
/* api/job_card.php — printable Delivery Note / Job Card for a quote (no prices).
   GET ?id=NN  -> renders an A4 print page. Owner or the quote's creator.
   The quote should already be invoiced (use Generate Job Card, which converts first). */
session_start();
require_once __DIR__ . '/../csrf.php'; csrf_guard();
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
    // admins and the creator always; assigned team members may view the no-price docs (job card / BOQ)
    // (the profit report is further gated to admins only below)
    if (!$admin && $q['created_by'] !== $me) {
        require_once __DIR__ . '/../project_costs.php';
        pa_table($pdo);
        if (!in_array($me, pa_for_quote($pdo, $id), true)) throw new Exception('You do not have access to this job.');
    }

    $items  = json_decode($q['line_items'] ?: '[]', true) ?: [];
    $docNo  = $q['zoho_invoice_number'] ?: ($q['zoho_estimate_number'] ?: ('Q-' . $id));
    $date   = date('d M Y');
    $theme  = (($_GET['theme'] ?? '') === 'dark') ? 'dark' : '';   // match the app's dark mode on screen (prints light)

    // Document mode: jobcard (default) | boq (materials, no labour) | profit (per-invoice P&L, admin only)
    $g = strtolower((string)($_GET['doc'] ?? ''));
    $doc = in_array($g, ['boq','profit'], true) ? $g : 'jobcard';
    if ($doc === 'boq') {
        // Conservative labour match so materials like "network installation accessories" stay in.
        $labourRe = '/\blabou?r\b|workmanship|man[\s\-]?hours?|service\s*charge/i';
        $items = array_values(array_filter($items, function($it) use ($labourRe) {
            $hay = (string)($it['name'] ?? '') . ' ' . (string)($it['description'] ?? '');
            return !preg_match($labourRe, $hay);
        }));
    }

    // Profit report: admin only; pull ACTUAL cost per line from project_costs.
    // VAT is never part of profit, so VAT-inclusive costs are converted to their ex-VAT value.
    $isProfit = ($doc === 'profit');
    $vat = (float)($cfg['vat_rate'] ?? 0.16);
    $actualByLine = []; $curr = $q['currency'] ?: 'KES';
    $inputVat = 0.0;   // reclaimable VAT paid on VAT-inclusive costs
    if ($isProfit) {
        if (!$admin) { http_response_code(403); echo 'The profit report is admin-only.'; exit; }
        require_once __DIR__ . '/../project_costs.php';
        pc_table($pdo);
        foreach (pc_for_quote($pdo, $id) as $c) {
            $li  = (int)$c['line_index'];
            $actualByLine[$li] = ($actualByLine[$li] ?? 0) + pc_exvat($c['amount'], $c['vat_mode'] ?? 'none', $vat);
            $inputVat += pc_vat_amount($c['amount'], $c['vat_mode'] ?? 'none', $vat);   // VAT on inclusive + plus rows
        }
    }
    $inputVat  = round($inputVat, 2);
    $outputVat = round((float)($q['tax_amount'] ?? 0), 2);   // VAT charged to the client on the sale
    $netVat    = round($outputVat - $inputVat, 2);            // VAT to remit to KRA
    $grossCharged = round((float)($q['total'] ?? 0), 2);     // invoice total incl VAT
    $money = function($n) use ($curr) { return $curr . ' ' . number_format((float)$n, 0); };

    $docLabel = $doc === 'boq' ? 'BOQ' : ($isProfit ? 'Profit Report' : 'Job Card');
    $docTop   = $doc === 'boq' ? 'BILL OF<br>QUANTITIES' : ($isProfit ? 'PROFIT<br>REPORT' : 'DELIVERY NOTE /<br>JOB CARD');
    $itemsHdr = $doc === 'boq' ? 'Material &amp; Description' : 'Item &amp; Description';

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

    // Profit report rows (per line: charged ex-VAT, budgeted cost, actual cost, profit)
    $pRows = ''; $tCharged = 0; $tBudget = 0; $tActual = 0;
    if ($isProfit) {
        $k = 0;
        foreach ($items as $i => $it) {
            $k++;
            $qtyN    = (float)($it['qty'] ?? 0);
            $charged = isset($it['amount']) ? (float)$it['amount'] : round($qtyN * (float)($it['rate'] ?? 0), 2);
            $budget  = round($qtyN * (float)($it['cost'] ?? 0), 2);
            $actual  = (float)($actualByLine[$i] ?? 0);
            $profit  = $charged - $actual;
            $tCharged += $charged; $tBudget += $budget; $tActual += $actual;
            $pc = $profit < 0 ? '#c0392b' : '#1e7e34';
            $pRows .= '<tr><td class="c" style="width:30px">' . $k . '</td><td>' . jc_esc($it['name'] ?? '') . '</td>'
                . '<td class="r">' . $money($charged) . '</td><td class="r">' . $money($budget) . '</td>'
                . '<td class="r">' . $money($actual) . '</td><td class="r" style="color:' . $pc . ';font-weight:700">' . $money($profit) . '</td></tr>';
        }
    }
    $tProfit  = $tCharged - $tActual;
    $expProfit = (float)($q['profit'] ?? 0);          // budgeted (expected) profit, cached on the quote
    $actProfit = (float)($q['actual_profit'] ?? 0);   // actual profit, cached on the quote
    $margin   = $tCharged > 0 ? round($tProfit / $tCharged * 100) : 0;

    $addrHtml = nl2br(jc_esc($coAddr));

    header('Content-Type: text/html; charset=utf-8');
    ?><!doctype html><html class="<?php echo $theme; ?>"><head><meta charset="utf-8">
<title><?php echo jc_esc($docLabel); ?> <?php echo jc_esc($docNo); ?></title>
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
  /* Dark mode for on-screen viewing only — printing always uses the light "paper" styles above */
  @media screen{
    html.dark body{background:#0E1826;color:#E7EDF5}
    html.dark .sheet{background:#16212F;border-color:#2A3A4E}
    html.dark .top,html.dark .rowline,html.dark .billto .lbl,html.dark table.items td{border-color:#2A3A4E}
    html.dark .co .meta,html.dark .title .t,html.dark .title .no,html.dark .rowline .k,html.dark .billto .lbl,html.dark .billto .who .pin{color:#9FB0C4}
    html.dark .co .name,html.dark .billto .who b,html.dark table.items td{color:#E7EDF5}
    html.dark table.items td div{color:#9FB0C4!important}
    html.dark .sign{color:#E7EDF5}
    html.dark .sign span{border-top-color:#3A4C64}
  }
  @media print{.bar{display:none}body{background:#fff}.sheet{border:none;margin:0;max-width:none}}
</style></head>
<body>
  <div class="bar"<?php echo !empty($_GET['embed']) ? ' style="display:none"' : ''; ?>><span><?php echo jc_esc($docLabel); ?> <?php echo jc_esc($docNo); ?></span><button onclick="window.print()">Print / Save PDF</button><a href="#" onclick="window.close();return false;">Close</a></div>
  <div class="sheet">
    <div class="top">
      <div class="co"><?php echo $logoUrl !== '' ? '<img class="logo-img" src="'.jc_esc($logoUrl).'" alt="'.jc_esc($coName).'">' : '<div class="logo">912</div>'; ?>
        <div><div class="name"><?php echo jc_esc($coName); ?></div>
          <div class="meta">KRA PIN: <?php echo jc_esc($coPin); ?><br><?php echo $addrHtml; ?></div></div>
      </div>
      <div class="title"><div class="t"><?php echo $docTop; ?></div>
        <div class="no"># <b><?php echo jc_esc($docNo); ?></b></div></div>
    </div>
    <div class="rowline"><div class="k">Date</div><div class="k" style="font-weight:400">: <b><?php echo jc_esc($date); ?></b></div></div>
    <div class="billto">
      <div class="lbl">Bill To</div>
      <div class="who"><b><?php echo jc_esc($q['customer_name'] ?: '—'); ?></b>
        <?php if ($custPin !== '') echo '<div class="pin">KRA PIN: ' . jc_esc($custPin) . '</div>'; ?></div>
    </div>
    <div class="body">
      <?php if ($isProfit): ?>
      <table class="items">
        <thead><tr><th class="c" style="width:30px">#</th><th>Item</th><th class="r">Charged</th><th class="r">Budget cost</th><th class="r">Actual cost</th><th class="r">Profit</th></tr></thead>
        <tbody>
          <?php echo $pRows ?: '<tr><td colspan="6" style="text-align:center;color:#8a98a8;padding:18px">No line items.</td></tr>'; ?>
          <tr style="font-weight:800;border-top:2px solid #3a6ea5"><td></td><td>TOTAL</td>
            <td class="r"><?php echo $money($tCharged); ?></td><td class="r"><?php echo $money($tBudget); ?></td>
            <td class="r"><?php echo $money($tActual); ?></td>
            <td class="r" style="color:<?php echo $tProfit<0?'#c0392b':'#1e7e34'; ?>"><?php echo $money($tProfit); ?></td></tr>
        </tbody>
      </table>
      <div style="padding:14px 30px;font-size:12.5px;line-height:1.8">
        <b>Expected profit</b> (from budget): <b><?php echo $money($expProfit); ?></b>
        &nbsp;·&nbsp; <b>Actual profit</b>: <b style="color:<?php echo $actProfit<0?'#c0392b':'#1e7e34'; ?>"><?php echo $money($actProfit); ?></b>
        &nbsp;·&nbsp; Margin: <b><?php echo $margin; ?>%</b>
        <div style="color:#8a98a8;font-size:11px;margin-top:4px">All figures above are ex-VAT — VAT is never included in profit. VAT-inclusive costs are shown at their ex-VAT value. Actual cost = expenses captured against this job (labour included).</div>
      </div>
      <!-- VAT element (shown for reconciliation only; not part of profit) -->
      <table class="items" style="margin-top:6px">
        <thead><tr><th>VAT element (not part of profit)</th><th class="r" style="width:150px">Amount</th></tr></thead>
        <tbody>
          <tr><td>Output VAT — VAT charged to the client on the sale</td><td class="r"><?php echo $money($outputVat); ?></td></tr>
          <tr><td>Input VAT — reclaimable VAT paid on VAT-inclusive costs</td><td class="r"><?php echo $money($inputVat); ?></td></tr>
          <tr style="font-weight:800;border-top:2px solid #3a6ea5"><td>Net VAT to remit (output − input)</td><td class="r" style="color:<?php echo $netVat<0?'#1e7e34':'#15202B'; ?>"><?php echo $money($netVat); ?></td></tr>
          <tr><td style="color:#8a98a8">Invoice total charged to client (incl VAT)</td><td class="r" style="color:#8a98a8"><?php echo $money($grossCharged); ?></td></tr>
        </tbody>
      </table>
      <?php else: ?>
      <table class="items">
        <thead><tr><th class="c" style="width:34px">#</th><th><?php echo $itemsHdr; ?></th><th class="r" style="width:90px">Qty</th></tr></thead>
        <tbody><?php echo $rows ?: '<tr><td colspan="3" style="text-align:center;color:#8a98a8;padding:18px">' . ($doc === 'boq' ? 'No materials (all lines are labour).' : 'No items.') . '</td></tr>'; ?></tbody>
      </table>
      <?php endif; ?>
    </div>
    <?php if (!$isProfit): ?><div class="sign"><span>Authorized Signature</span></div><?php endif; ?>
  </div>
  <div style="text-align:center;padding:14px 12px 18px;line-height:1.7">
    <div style="font-size:11px;color:#64748B">This system is designed for <b>912 Holdings</b>, Zone Fibre Limited, Waitara Holdings Limited, Smart Zone Fibre Limited &amp; Global IT Limited</div>
    <div style="font-size:10.5px;color:#E07000;margin-top:4px">&#9888; If you are a staff member of any of the companies listed here and can see information of a company you are not associated with, report immediately to <b>Njuguna Waitara — +254 722 974 970</b> at a reward of <b>10,000 KES</b></div>
  </div>
</body></html><?php
} catch (Exception $e) {
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo '<p style="font-family:Arial;padding:20px;color:#b00">Could not generate job card: ' . jc_esc(err_ref($e)) . '</p>';
}

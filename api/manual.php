<?php
/* api/manual.php — printable technician user manual (save as PDF, share on WhatsApp). */
session_start();
if (empty($_SESSION['auth'])) { http_response_code(401); echo 'Not signed in.'; exit; }
$cfg = require __DIR__ . '/../config.php';
$co = htmlspecialchars($cfg['business_name'] ?? 'Nine One Two Holdings', ENT_QUOTES);
$scheme  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host    = $_SERVER['HTTP_HOST'] ?? 'nineonetwo.online';
$appUrl  = $scheme . '://' . $host . rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/WORKINGCAPITAL/api/x')), '/\\') . '/';
$appUrl  = htmlspecialchars($appUrl, ENT_QUOTES);
header('Content-Type: text/html; charset=utf-8');
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Technician Manual — <?php echo $co; ?></title>
<style>
  @page{size:A4;margin:14mm}
  *{box-sizing:border-box}
  body{font-family:Arial,Helvetica,sans-serif;color:#15202B;margin:0;background:#eef1f5;line-height:1.55}
  .sheet{background:#fff;max-width:820px;margin:18px auto;border:1px solid #C9D2DD;border-radius:6px;padding:34px 40px}
  .head{display:flex;align-items:center;gap:14px;border-bottom:3px solid #F56F00;padding-bottom:16px;margin-bottom:8px}
  .logo{width:54px;height:54px;border-radius:14px;background:#F56F00;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:18px;flex:0 0 auto}
  h1{font-size:23px;margin:0}
  .sub{color:#5a6b7b;font-size:13px;margin-top:2px}
  h2{font-size:16px;color:#F56F00;margin:26px 0 8px;border-bottom:1px solid #E6EAF0;padding-bottom:5px}
  ol,ul{margin:6px 0 6px 18px;padding:0}
  li{margin:5px 0;font-size:13.5px}
  p{font-size:13.5px;margin:6px 0}
  .tag{display:inline-block;background:#FFF1E3;color:#A24E00;border-radius:5px;padding:1px 7px;font-weight:700;font-size:12.5px}
  .note{background:#F4F7FB;border-left:3px solid #2350C5;padding:8px 12px;border-radius:0 6px 6px 0;font-size:12.5px;margin:10px 0}
  .step{font-weight:700}
  .bar{position:fixed;top:0;left:0;right:0;background:#15202B;color:#fff;padding:8px 14px;display:flex;gap:12px;justify-content:center;align-items:center;font-size:13px}
  .bar button{background:#F56F00;color:#fff;border:none;padding:7px 16px;border-radius:7px;font-weight:600;cursor:pointer;font-family:inherit}
  .bar a{color:#9AA7B8;text-decoration:none;font-size:12px}
  .foot{margin-top:30px;border-top:1px solid #E6EAF0;padding-top:12px;color:#94A3B8;font-size:11.5px;text-align:center}
  @media print{.bar{display:none}body{background:#fff}.sheet{border:none;margin:0;max-width:none;padding:0}}
</style></head>
<body>
  <div class="bar"><span>Technician Manual</span><button onclick="window.print()">Save as PDF / Print</button><a href="#" onclick="window.close();return false;">Close</a></div>
  <div class="sheet">
    <div class="head"><div class="logo">912</div>
      <div><h1>Technician Manual</h1><div class="sub"><?php echo $co; ?> · 912 Finance Console</div></div></div>

    <div class="note" style="border-left-color:#F56F00;background:#FFF6EE">
      <b>Open the system here:</b> <a href="<?php echo $appUrl; ?>" style="color:#A24E00;font-weight:700"><?php echo $appUrl; ?></a><br>
      It works on your <b>phone</b> and on a <b>computer</b> — just open the link in any web browser (Chrome, Safari, etc.). You can save it to your home screen for quick access.
    </div>

    <p>This short guide shows you how to raise quotes, get them approved, send them to clients, and generate a job card when a job is done. If anything is unclear, ask the admin.</p>

    <h2>1. Signing in</h2>
    <ol>
      <li>Open the app link the admin shared with you.</li>
      <li>Enter your <span class="tag">username or email</span> and your password, then tap <b>Open console</b>.</li>
      <li>To change your password, tap <b>🔑 Password</b> at the top right at any time.</li>
    </ol>

    <h2>2. Your dashboard</h2>
    <p>When you log in you land on your <b>Dashboard</b> — a quick view of your quotes (how many are awaiting approval, approved, or declined) and the tasks assigned to you. You only ever see your own work.</p>

    <h2>3. Creating a quote</h2>
    <ol>
      <li>Tap <b>Create → New Quote</b>. A form opens.</li>
      <li><span class="step">Customer:</span> type the client's name and pick them from the list.</li>
      <li><span class="step">Subject:</span> required — a short line saying what the quote is for.</li>
      <li><span class="step">Items:</span> for each item type the name, <b>Qty</b>, <b>Rate</b> (selling price) and <b>Unit cost</b> (what it costs you). Tap <b>⊕ Add new row</b> for more items.</li>
      <li>Choose <b>Tax</b> per line (VAT 16% or No tax). The totals calculate automatically.</li>
      <li>Tap <b>Save &amp; push to Zoho →</b> to send it for the admin to approve. (Use <b>Save draft</b> if you're not finished.)</li>
    </ol>
    <div class="note">You can quote for any client, but only in <b>KES</b>. Foreign-currency quotes are done by the admin.</div>

    <h2>4. Getting it approved</h2>
    <p>After you push a quote, the admin reviews it. Check <b>Create → My Quotes</b> to see the status:</p>
    <ul>
      <li><b>Pending approval</b> — waiting for the admin.</li>
      <li><b>Approved</b> — good to go; you can now send it to the customer or generate a job card.</li>
      <li><b>Declined</b> — the admin didn't approve it; talk to them.</li>
    </ul>
    <p>Use the search box and the <b>Status</b> chips to find quotes quickly.</p>

    <h2>5. Sending a quote to the customer</h2>
    <p>On an <b>approved</b> quote in <b>My Quotes</b>, tap <b>✉ Send to customer</b>. The customer receives the quote by email (with a PDF). You can also tap <b>⤓ PDF</b> to download it yourself.</p>

    <h2>6. Generating a Job Card (when the job is done)</h2>
    <ol>
      <li>On an approved quote, tap <b>🧾 Job Card</b>.</li>
      <li>Confirm that the job is complete when asked — this is final.</li>
      <li>The system creates the invoice and opens a printable <b>Delivery Note / Job Card</b> (no prices) for the customer to sign.</li>
      <li>Tap <b>Print / Save PDF</b> to print it or save and share it.</li>
    </ol>
    <div class="note">Once a job card is generated the quote becomes <b>Invoiced</b>. You can re-open past job cards any time under <b>Create → Job Cards</b>.</div>

    <h2>7. Your tasks</h2>
    <p>Tap <b>Tasks → To-Do</b> to see the tasks assigned to you. Tick a task when it's done.</p>

    <div class="foot">Need help? Contact your admin. · <?php echo $co; ?></div>
  </div>
</body></html>

<?php
/* portal_fiscal.php — FISCALISATION portal. Login-protected work queue for the
   worker who fiscalises invoices (gets the KRA ETR). She sees exactly the invoices
   the owner has FILED (dropped into "INVOICES TO SORT") that do NOT yet have an ETR
   scan — i.e. "filed minus fiscalised". That queue is computed on the owner's PC by
   the local reconciliation app and pushed here (fiscal_sync.php) into fiscal_queue.
   She taps "Fiscalised ✓" to clear each one (fiscal_flags); once the owner's folder
   shows the scan, the item drops from the pushed queue automatically.
   Included by index.php; served at ?portal=fiscal (and the /fiscal shortcut).
   Credentials + disable switch are managed by the admin in the Portals tab. */

if (!function_exists('fp_build')) {
    function fp_prefs($dir) { $f = $dir . '/fiscal_prefs.json'; $j = is_file($f) ? (json_decode(@file_get_contents($f), true) ?: []) : []; return ['disabled' => !empty($j['disabled'])]; }
    function fp_queue_table($pdo) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS fiscal_queue (
            invoice_number VARCHAR(64) PRIMARY KEY,
            invoice_id VARCHAR(64) DEFAULT '',
            customer_name VARCHAR(190) DEFAULT '',
            amount DECIMAL(14,2) DEFAULT 0,
            currency VARCHAR(8) DEFAULT 'KES',
            inv_date VARCHAR(20) DEFAULT '',
            descr VARCHAR(255) DEFAULT '',
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS fiscal_flags (
            invoice_number VARCHAR(64) PRIMARY KEY,
            invoice_id VARCHAR(64) DEFAULT '',
            customer_name VARCHAR(190) DEFAULT '',
            done TINYINT(1) DEFAULT 1,
            done_by VARCHAR(80) DEFAULT '',
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    /* One invoice's line items from Zoho + a short "what it's for" summary (detail popup). */
    function fp_inv_fetch_raw($id) {
        try {
            [$d, $c] = zoho_api('GET', 'invoices/' . rawurlencode($id));
            if ($c >= 400 || empty($d['invoice'])) return null;
            $iv = $d['invoice'];
            $items = [];
            foreach (($iv['line_items'] ?? []) as $li) {
                $items[] = ['name'=>(string)($li['name'] ?? ''), 'desc'=>(string)($li['description'] ?? ''),
                    'qty'=>(float)($li['quantity'] ?? 0), 'rate'=>(float)($li['rate'] ?? 0), 'amt'=>(float)($li['item_total'] ?? 0),
                    'tax'=>(string)($li['tax_name'] ?? ''), 'taxpc'=>(float)($li['tax_percentage'] ?? 0)];
            }
            $taxes = [];
            foreach (($iv['taxes'] ?? []) as $tx) { $taxes[] = ['name'=>(string)($tx['tax_name'] ?? ''), 'amount'=>(float)($tx['tax_amount'] ?? 0)]; }
            return [
                'id'=>(string)$id, 'number'=>(string)($iv['invoice_number'] ?? ''), 'customer'=>(string)($iv['customer_name'] ?? ''),
                'date'=>substr((string)($iv['date'] ?? ''), 0, 10), 'due'=>substr((string)($iv['due_date'] ?? ''), 0, 10),
                'status'=>(string)($iv['status'] ?? ''), 'currency'=>strtoupper((string)($iv['currency_code'] ?? '')),
                'sub_total'=>(float)($iv['sub_total'] ?? 0), 'tax_total'=>(float)($iv['tax_total'] ?? 0),
                'discount'=>(float)($iv['discount_total'] ?? ($iv['discount'] ?? 0)),
                'shipping'=>(float)($iv['shipping_charge'] ?? 0), 'adjustment'=>(float)($iv['adjustment'] ?? 0),
                'inclusive'=>!empty($iv['is_inclusive_tax']), 'taxes'=>$taxes,
                'total'=>(float)($iv['total'] ?? 0), 'balance'=>(float)($iv['balance'] ?? 0),
                'notes'=>(string)($iv['notes'] ?? ''), 'items'=>$items,
            ];
        } catch (\Throwable $e) { return null; }
    }
    /* The fiscalisation queue: rows pushed by the local app (filed − fiscalised, KES),
       with each marked done=true when the worker has cleared it (fiscal_flags). */
    function fp_build() {
        $today = date('Y-m-d');
        $rows = []; $done = [];
        try {
            $pdo = db(); fp_queue_table($pdo);
            foreach ($pdo->query("SELECT invoice_number FROM fiscal_flags WHERE done=1")->fetchAll(PDO::FETCH_COLUMN) as $v) $done[(string)$v] = true;
            foreach ($pdo->query("SELECT invoice_number,invoice_id,customer_name,amount,currency,inv_date,descr FROM fiscal_queue")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $num = (string)$r['invoice_number'];
                $dt = substr((string)$r['inv_date'], 0, 10);
                $days = ($dt !== '') ? (int)floor((strtotime($today) - strtotime($dt)) / 86400) : 0; if ($days < 0) $days = 0;
                $rows[] = [
                    'number'=>$num, 'id'=>(string)$r['invoice_id'], 'customer'=>(string)$r['customer_name'],
                    'amount'=>(float)$r['amount'], 'currency'=>strtoupper((string)$r['currency'] ?: 'KES'),
                    'date'=>$dt, 'descr'=>(string)$r['descr'], 'days'=>$days, 'done'=>isset($done[$num]),
                ];
            }
        } catch (\Throwable $e) { /* empty queue */ }
        /* oldest filed first — she clears the longest-waiting ones first */
        usort($rows, fn($a, $b) => ($b['days'] <=> $a['days']));
        $queue = array_values(array_filter($rows, fn($r) => !$r['done']));
        $queueTotal = array_sum(array_map(fn($r) => $r['amount'], $queue));
        $doneCount = count(array_filter($rows, fn($r) => $r['done']));
        return ['ok'=>true, 'asOf'=>date('c'), 'rows'=>$rows,
            'queueCount'=>count($queue), 'queueTotal'=>$queueTotal, 'doneCount'=>$doneCount];
    }
}

if (isset($_GET['portal']) && $_GET['portal'] === 'fiscal') {
    session_name('FISCALPORTAL');
    session_start();
    require_once __DIR__ . '/csrf.php'; csrf_guard();
    require_once __DIR__ . '/errors.php';
    require_once __DIR__ . '/zoho.php';
    require_once __DIR__ . '/db.php';
    @set_time_limit(120);
    $fDir = __DIR__ . '/data';

    /* PIN login (4–6 digits), like the other portals. PIN hash in data/fiscal_auth.json. */
    $fAuthFile = $fDir . '/fiscal_auth.json';
    $fPinHash = '';
    if (is_file($fAuthFile)) {
        $j = json_decode(@file_get_contents($fAuthFile), true);
        if (is_array($j)) $fPinHash = (string)($j['pin_hash'] ?? '');
    }
    $fConfigured = ($fPinHash !== '');
    $fDisabled = !empty(fp_prefs($fDir)['disabled']);

    if (isset($_GET['logout'])) { $_SESSION = []; session_destroy(); header('Location: index.php?portal=fiscal'); exit; }

    $fErr = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['fiscal_login'])) {
        $pin = preg_replace('/\D/', '', (string)($_POST['pin'] ?? ''));
        $ok = (!$fDisabled && $fConfigured && preg_match('/^\d{4,6}$/', $pin) && password_verify($pin, $fPinHash));
        if ($ok) { session_regenerate_id(true); $_SESSION['fiscal_auth'] = true; header('Location: index.php?portal=fiscal'); exit; }
        $fErr = $fDisabled ? 'This portal is currently disabled.' : 'Wrong PIN.';
    }
    $fAuthed = !$fDisabled && !empty($_SESSION['fiscal_auth']);

    if (isset($_GET['data'])) {
        header('Content-Type: application/json; charset=utf-8');
        if (!$fAuthed) { http_response_code(403); echo json_encode(['ok'=>false, 'error'=>'Not signed in.']); exit; }
        try { echo json_encode(fp_build()); }
        catch (\Throwable $e) { http_response_code(500); echo api_fail($e); }
        exit;
    }

    /* Mark an invoice fiscalised (done) or undo. */
    if (isset($_GET['mark'])) {
        header('Content-Type: application/json; charset=utf-8');
        if (!$fAuthed) { http_response_code(403); echo json_encode(['ok'=>false, 'error'=>'Not signed in.']); exit; }
        try {
            $in = json_decode(file_get_contents('php://input'), true) ?: [];
            $num = mb_substr(trim((string)($in['invoice_number'] ?? '')), 0, 64);
            if ($num === '') { echo json_encode(['ok'=>false, 'error'=>'No invoice number.']); exit; }
            $iid = mb_substr(trim((string)($in['invoice_id'] ?? '')), 0, 64);
            $name = mb_substr(trim((string)($in['customer_name'] ?? '')), 0, 190);
            $on = !empty($in['done']);
            $by = mb_substr((string)($_SESSION['fiscal_user'] ?? ''), 0, 80);
            $pdo = db(); fp_queue_table($pdo);
            if ($on) {
                $pdo->prepare("INSERT INTO fiscal_flags (invoice_number,invoice_id,customer_name,done,done_by) VALUES (?,?,?,1,?)
                    ON DUPLICATE KEY UPDATE invoice_id=VALUES(invoice_id),customer_name=VALUES(customer_name),done=1,done_by=VALUES(done_by)")
                    ->execute([$num, $iid, $name, $by]);
            } else {
                $pdo->prepare("DELETE FROM fiscal_flags WHERE invoice_number=?")->execute([$num]);
            }
            echo json_encode(['ok'=>true, 'invoice_number'=>$num, 'done'=>$on]);
        } catch (\Throwable $e) { echo api_fail($e); }
        exit;
    }

    /* Invoice detail as JSON for the "what it's for" popup. */
    if (isset($_GET['inv'])) {
        header('Content-Type: application/json; charset=utf-8');
        if (!$fAuthed) { http_response_code(403); echo json_encode(['ok'=>false, 'error'=>'Not signed in.']); exit; }
        $iid = preg_replace('/[^0-9]/', '', (string)$_GET['inv']);
        if ($iid === '') { echo json_encode(['ok'=>false, 'error'=>'Invoice not found.']); exit; }
        try {
            $det = fp_inv_fetch_raw($iid);
            if (!$det) { http_response_code(502); echo json_encode(['ok'=>false, 'error'=>'Could not load this invoice.']); exit; }
            echo json_encode(['ok'=>true, 'invoice'=>$det]);
        } catch (\Throwable $e) { echo api_fail($e); }
        exit;
    }

    $fEsc = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    ?>
<!doctype html><html><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>FISCALISATION · WAITARA HOLDINGS GROUP</title>
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'%3E%3Crect width='64' height='64' rx='16' fill='%23F56F00'/%3E%3Ctext x='32' y='45' font-family='Arial' font-size='29' font-weight='700' fill='white' text-anchor='middle'%3E912%3C/text%3E%3C/svg%3E">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@500;600&display=swap" rel="stylesheet">
<style>
  :root{
    --ink:#1B1A17;--mute:#6E6A63;--line:#D7D3CB;--hair:#E6E2DB;--bg:#E8E6E1;--card:#FFFFFF;--soft:#F5F3EF;
    --brand:#D95E07;--red:#BE2A17;--amber:#9A6700;--green:#1D7A3A;--mail:#1F4FD0;
    --red-bg:#F6E5E1;--amber-bg:#F4EEDC;--green-bg:#E3EEE6;--blue-bg:#E6ECF9;
    --mono:'IBM Plex Mono',ui-monospace,Menlo,Consolas,monospace;
  }
  *{box-sizing:border-box}
  body{margin:0;font-family:'IBM Plex Sans',system-ui,'Segoe UI',Arial,sans-serif;background:var(--bg);color:var(--ink);font-size:13.5px;-webkit-font-smoothing:antialiased}
  .muted{color:var(--mute)}
  .mono{font-family:var(--mono);font-variant-numeric:tabular-nums}
  .top{background:#1B1A17;color:#fff;padding:0 16px;height:52px;display:flex;align-items:center;gap:12px;position:sticky;top:0;z-index:50;border-bottom:2px solid var(--brand)}
  .b{width:30px;height:30px;border-radius:3px;background:var(--brand);display:grid;place-items:center;font-weight:700;font-size:12px;color:#fff;flex:0 0 auto;font-family:var(--mono)}
  .top h1{font-size:14px;margin:0;font-weight:600;letter-spacing:.3px;line-height:1.1;text-transform:uppercase}
  .top .sub{font-size:9.5px;color:#9A948B;letter-spacing:.8px;margin-top:1px;font-weight:500}
  .top .sp{margin-left:auto;display:flex;gap:8px}
  .tbtn{border:1px solid #3A3833;color:#E7E3DC;background:transparent;border-radius:3px;padding:7px 12px;font-size:11.5px;font-weight:600;cursor:pointer;font-family:inherit;text-decoration:none;white-space:nowrap;letter-spacing:.3px}
  .tbtn:hover{background:#2A2824}
  .wrap{width:100%;max-width:none;margin:0;padding:18px clamp(14px,3.2vw,52px) 48px}
  .hero{background:var(--card);border:1px solid var(--line);border-top:3px solid var(--brand);padding:16px 18px;display:flex;align-items:flex-end;gap:28px;flex-wrap:wrap}
  .hero .hl{font-size:10px;font-weight:600;letter-spacing:1px;text-transform:uppercase;color:var(--mute)}
  .hero .ht{font-family:var(--mono);font-weight:600;font-size:27px;line-height:1.05;margin-top:5px;color:var(--ink)}
  .hero .hs{font-size:12px;margin-top:6px;color:var(--mute)}
  .hero .hr{margin-left:auto;display:flex;gap:0}
  .stat{padding:2px 22px;border-left:1px solid var(--hair)}
  .stat:first-child{border-left:0;padding-left:0}
  .stat .n{font-family:var(--mono);font-size:22px;font-weight:600;line-height:1}
  .stat .l{font-size:9.5px;color:var(--mute);font-weight:500;margin-top:5px;text-transform:uppercase;letter-spacing:.5px}
  .tool{position:sticky;top:52px;z-index:20;background:var(--bg);padding:14px 0 10px;margin-top:4px;border-bottom:1px solid var(--line)}
  .srch{width:100%;padding:11px 13px;border:1px solid var(--line);border-radius:3px;font-family:inherit;font-size:14px;background:var(--card)}
  .srch:focus{outline:none;border-color:var(--brand);box-shadow:inset 0 0 0 1px var(--brand)}
  .chips{display:flex;gap:6px;margin-top:9px;overflow-x:auto;padding-bottom:2px;-webkit-overflow-scrolling:touch;scrollbar-width:none}
  .chips::-webkit-scrollbar{display:none}
  .chip{border:1px solid var(--line);background:var(--card);color:var(--ink);border-radius:3px;padding:8px 13px;font-size:12.5px;font-weight:600;cursor:pointer;font-family:inherit;white-space:nowrap;flex:0 0 auto}
  .chip .c{color:var(--mute);font-weight:600;margin-left:5px;font-family:var(--mono)}
  .chip.on{background:var(--ink);color:#fff;border-color:var(--ink)}.chip.on .c{color:#B9B3A9}
  .chip.on.green{background:var(--green);border-color:var(--green)} .chip.on.amber{background:var(--amber);border-color:var(--amber)}
  .ftbl{width:100%;border-collapse:collapse;background:var(--card);border:1px solid var(--line);font-size:13px}
  .ftbl thead th{text-align:left;font-size:9.5px;text-transform:uppercase;letter-spacing:.5px;color:var(--mute);font-weight:700;padding:10px 14px;background:var(--soft);border-bottom:1px solid var(--line);white-space:nowrap}
  .ftbl th.r,.ftbl td.r{text-align:right}
  .ftbl td{padding:11px 14px;border-bottom:1px solid var(--hair);vertical-align:middle}
  .ftbl tr.irow:hover{background:var(--soft)}
  .ftbl tr.irow.done{background:var(--soft);color:var(--mute)}
  .ftbl tr.grow td{background:var(--ink);color:#fff;border-bottom:2px solid var(--brand);padding:9px 14px}
  .ftbl tr.grow .gname{font-size:13px;font-weight:700;letter-spacing:.2px}
  .ftbl tr.grow .gmeta{font-family:var(--mono);font-size:11px;color:#C9C4BB;margin-left:10px}
  .ftbl .num{font-family:var(--mono);font-size:13.5px;font-weight:600;cursor:pointer;color:var(--mail);background:none;border:0;padding:0}
  .ftbl .num:hover{text-decoration:underline}
  .ftbl .amt{font-family:var(--mono);font-weight:600;white-space:nowrap}
  .ftbl .dte{font-family:var(--mono);color:var(--mute);white-space:nowrap}
  .ftbl td.desc{color:var(--mute);max-width:320px}
  .fbtn{border:0;border-radius:3px;padding:8px 13px;font-size:12.5px;font-weight:700;cursor:pointer;font-family:inherit;letter-spacing:.2px;background:var(--green);color:#fff;white-space:nowrap}
  .fbtn.undo{background:var(--card);border:1px solid var(--line);color:var(--mute);font-weight:600}
  .fbtn:disabled{opacity:.6;cursor:default}
  /* phone: collapse the table into labeled stacked rows */
  @media(max-width:640px){
    .ftbl,.ftbl tbody{display:block}
    .ftbl thead{display:none}
    .ftbl tr.grow{display:block}
    .ftbl tr.grow td{display:block;width:100%}
    .ftbl tr.irow{display:grid;grid-template-columns:1fr auto;gap:4px 10px;padding:10px 14px;border-bottom:1px solid var(--hair)}
    .ftbl tr.irow td{display:block;border:0;padding:0}
    .ftbl td.desc{grid-column:1 / -1;max-width:none}
    .ftbl td[data-l="Filed"],.ftbl td[data-l="Waiting"]{grid-column:1 / -1}
    .ftbl td.amt{text-align:left;font-size:15px}
    .ftbl td[data-l="Action"]{grid-row:1 / 3;grid-column:2;align-self:center}
  }
  .pill{display:inline-flex;align-items:center;gap:4px;font-size:11px;font-weight:600;padding:3px 8px;border-radius:2px}
  .pill.red{background:var(--red-bg);color:var(--red)} .pill.amber{background:var(--amber-bg);color:var(--amber)} .pill.green{background:var(--green-bg);color:var(--green)}
  .donetag{font-size:9.5px;font-weight:800;color:#0F7A34;background:var(--green-bg);border:1px solid #BFE0CB;border-radius:3px;padding:2px 7px;text-transform:uppercase;letter-spacing:.04em}
  .modal{position:fixed;inset:0;background:rgba(20,16,12,.55);display:none;z-index:200;overflow:auto;padding:16px}
  .modal.open{display:block}
  .mcard{background:var(--card);border:1px solid var(--line);border-top:3px solid var(--brand);max-width:640px;margin:36px auto}
  .mhead{display:flex;align-items:center;gap:10px;padding:14px 18px;border-bottom:1px solid var(--line);position:sticky;top:0;background:var(--card)}
  .mhead .mt{font-family:var(--mono);font-weight:600;font-size:15px}
  .mx{margin-left:auto;background:var(--card);border:1px solid var(--line);border-radius:3px;width:30px;height:30px;cursor:pointer;font-size:16px;font-family:inherit;color:var(--mute);line-height:1}
  .mbody{padding:16px 18px}
  .mmeta{display:flex;gap:18px;flex-wrap:wrap;font-size:12px;color:var(--mute);margin-bottom:14px}
  .mtbl{width:100%;border-collapse:collapse;font-size:12.5px}
  .mtbl th{text-align:left;font-size:9.5px;text-transform:uppercase;letter-spacing:.4px;color:var(--mute);padding:6px 8px;border-bottom:1px solid var(--line)}
  .mtbl td{padding:8px 8px;border-bottom:1px solid var(--hair);vertical-align:top}
  .mtbl td.r{text-align:right;font-family:var(--mono);white-space:nowrap}
  .mtot{display:flex;justify-content:space-between;margin-top:12px;font-weight:600;font-size:14px}
  .bar{position:fixed;top:0;left:0;height:2px;background:var(--brand);width:0;transition:width .2s,opacity .3s;opacity:0;z-index:999}
  .pgfoot{text-align:center;padding:22px 12px 28px}.pgfoot .cn{font-size:11.5px;color:var(--mute)}.pgfoot .sc2{font-size:10px;color:var(--mute);margin-top:4px;letter-spacing:1px;text-transform:uppercase}
  .empty{background:var(--card);border:1px solid var(--line);padding:26px 16px;text-align:center;color:var(--mute);font-weight:500}
  .login{max-width:360px;margin:9vh auto 0;padding:0 18px}
  .lcard{background:var(--card);border:1px solid var(--line);border-top:3px solid var(--brand);padding:28px 26px}
  .login .lb{width:40px;height:40px;border-radius:3px;background:var(--brand);display:grid;place-items:center;font-weight:700;color:#fff;font-size:14px;margin-bottom:16px;font-family:var(--mono)}
  .login h2{margin:0 0 4px;font-size:19px;font-weight:600}
  .login .in{width:100%;padding:12px 13px;border:1px solid var(--line);border-radius:3px;font-family:inherit;font-size:14.5px;margin-top:11px;background:var(--soft)}
  .login .in:focus{outline:none;border-color:var(--brand)}
  .login .go{width:100%;margin-top:16px;border:0;background:var(--brand);color:#fff;padding:13px;border-radius:3px;font-weight:600;font-size:14.5px;cursor:pointer;font-family:inherit;letter-spacing:.4px}
  .login .err{background:var(--red-bg);color:#9B2016;border-radius:3px;padding:10px 12px;font-size:12.5px;margin-top:12px}
  @media(max-width:520px){ .hero{gap:16px} .hero .hr{margin-left:0;width:100%} .stat{padding:2px 16px} .hero .ht{font-size:24px} }
</style></head>
<body>
<div id="bar" class="bar"></div>
<div class="top">
  <div class="b">912</div>
  <div><h1>Fiscalisation</h1><div class="sub">WAITARA HOLDINGS GROUP</div></div>
  <?php if ($fAuthed): ?>
  <div class="sp"><button class="tbtn" onclick="load()">Refresh</button><a class="tbtn" href="index.php?portal=fiscal&logout=1">Sign out</a></div>
  <?php endif; ?>
</div>
<?php if (!$fAuthed): ?>
<div class="login">
  <div class="lcard">
    <div class="lb">912</div>
    <h2>Welcome</h2>
    <div class="muted" style="font-size:13.5px">Enter your PIN to see invoices waiting to be fiscalised.</div>
    <?php if ($fDisabled): ?>
      <div class="err">This portal is currently disabled. Please contact the administrator.</div>
    <?php elseif (!$fConfigured): ?>
      <div class="err">This portal isn't set up yet. The administrator needs to set a PIN (Settings → Portals).</div>
    <?php else: ?>
    <form method="post" action="index.php?portal=fiscal" autocomplete="off">
      <input type="hidden" name="fiscal_login" value="1">
      <input class="in" type="password" name="pin" inputmode="numeric" pattern="[0-9]*" maxlength="6" placeholder="PIN" autocomplete="off" style="text-align:center;letter-spacing:.5em;font-family:var(--mono)" required autofocus>
      <?php if ($fErr): ?><div class="err"><?= $fEsc($fErr) ?></div><?php endif; ?>
      <button class="go" type="submit">Sign in →</button>
    </form>
    <?php endif; ?>
  </div>
  <div class="pgfoot"><div class="cn">Waitara Holdings Group of Companies</div><div class="sc2">SECURE PORTAL</div></div>
</div>
<?php else: ?>
<div class="wrap" id="app"><div class="empty">Loading the fiscalisation queue…</div></div>
<div class="pgfoot"><div class="cn">Waitara Holdings Group of Companies · Fiscalisation</div><div class="sc2">CONFIDENTIAL</div></div>
<div id="invModal" class="modal" onclick="if(event.target===this)closeInv()">
  <div class="mcard">
    <div class="mhead"><span class="mt" id="invTitle">Invoice</span><button class="mx" onclick="closeInv()">×</button></div>
    <div class="mbody" id="invBody"></div>
  </div>
</div>
<script>
const esc=s=>String(s==null?'':s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
const att=s=>esc(s).replace(/'/g,'&#39;');
const jsq=s=>String(s==null?'':s).replace(/\\/g,'\\\\').replace(/'/g,"\\'");
const fmtC=(cur,n)=>(cur||'')+' '+Math.round(n||0).toLocaleString('en-US');
let DATA=null, Q='', VIEW='todo';
function bar(s){const b=document.getElementById('bar');if(!b)return;if(s){b.style.opacity='1';b.style.width='80%';}else{b.style.width='100%';setTimeout(()=>{b.style.opacity='0';b.style.width='0';},300);}}
async function load(){bar(true);try{const r=await fetch('index.php?portal=fiscal&data=1',{credentials:'same-origin'});DATA=await r.json();}catch(e){DATA={ok:false,error:String(e)};}bar(false);render();}
function row(n){return (DATA.rows||[]).find(x=>x.number===n);}
function setView(v){VIEW=v;renderList();}
function daysPill(d){if(d>=30)return `<span class="pill red">${d} days waiting</span>`;if(d>0)return `<span class="pill amber">${d} day${d===1?'':'s'} waiting</span>`;return `<span class="pill green">Filed today</span>`;}
async function mark(num,done,btn){
  const r=row(num);if(!r)return;
  if(btn){btn.disabled=true;btn.textContent='…';}
  try{
    const res=await fetch('index.php?portal=fiscal&mark=1',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},
      body:JSON.stringify({invoice_number:num,invoice_id:r.id,customer_name:r.customer,done:done})});
    const j=await res.json();
    if(j&&j.ok){ r.done=done; recount(); renderList(); }
    else { if(btn){btn.disabled=false;btn.textContent=done?'Fiscalised ✓':'Undo';} alert('Could not update: '+((j&&j.error)||'failed')); }
  }catch(e){ if(btn){btn.disabled=false;btn.textContent=done?'Fiscalised ✓':'Undo';} alert('Error: '+e); }
}
function recount(){
  const rows=DATA.rows||[];
  const q=rows.filter(r=>!r.done);
  DATA.queueCount=q.length; DATA.doneCount=rows.filter(r=>r.done).length;
  DATA.queueTotal=q.reduce((s,r)=>s+(r.amount||0),0);
}
function closeInv(){const m=document.getElementById('invModal');if(m)m.classList.remove('open');}
async function openInv(id){
  const m=document.getElementById('invModal'),b=document.getElementById('invBody'),t=document.getElementById('invTitle');
  if(!m)return; m.classList.add('open'); t.textContent='Invoice'; b.innerHTML='<div class="muted" style="padding:8px">Loading invoice…</div>';
  try{
    const r=await fetch('index.php?portal=fiscal&inv='+encodeURIComponent(id),{credentials:'same-origin'});
    const j=await r.json();
    if(!j||!j.ok){ b.innerHTML='<div style="padding:8px;color:var(--red)">'+esc((j&&j.error)||'Could not load this invoice.')+'</div>'; return; }
    const iv=j.invoice; t.textContent=iv.number||'Invoice';
    b.innerHTML=invDetailHTML(iv);
  }catch(e){ b.innerHTML='<div style="padding:8px;color:var(--red)">Error: '+esc(String(e))+'</div>'; }
}
function invDetailHTML(iv){
  const cur=iv.currency||'';
  const rows=(iv.items||[]).map(it=>`<tr>
      <td><div style="color:var(--ink);font-weight:600">${esc(it.name||it.desc||'—')}</div>${(it.name&&it.desc)?`<div class="muted" style="font-size:11px;margin-top:2px">${esc(it.desc)}</div>`:''}</td>
      <td class="r">${it.qty?(+it.qty).toLocaleString('en-US'):''}</td>
      <td class="r">${it.rate?fmtC(cur,it.rate):''}</td>
      <td class="r">${it.tax?esc(it.tax):(it.taxpc?(+it.taxpc)+'%':'—')}</td>
      <td class="r">${fmtC(cur,it.amt)}</td></tr>`).join('');
  const line=(lbl,val,extra)=>`<div class="mtot" style="font-weight:500;font-size:13px;${extra||''}"><span class="muted">${lbl}</span><span class="mono">${val}</span></div>`;
  let breakdown='';
  if(iv.sub_total||iv.tax_total||(iv.taxes&&iv.taxes.length)){
    breakdown+=line('Subtotal'+(iv.inclusive?' (tax inclusive)':''), fmtC(cur,iv.sub_total));
    if(+iv.discount>0) breakdown+=line('Discount', '− '+fmtC(cur,iv.discount));
    if(iv.taxes&&iv.taxes.length) iv.taxes.forEach(tx=>{ breakdown+=line(esc(tx.name||'Tax'), fmtC(cur,tx.amount)); });
    else if(+iv.tax_total>0) breakdown+=line('Tax (VAT)', fmtC(cur,iv.tax_total));
    if(+iv.shipping>0) breakdown+=line('Shipping', fmtC(cur,iv.shipping));
    if(+iv.adjustment) breakdown+=line('Adjustment', fmtC(cur,iv.adjustment));
  }
  return `
    <div class="mmeta"><span><b style="color:var(--ink)">${esc(iv.customer||'')}</b></span><span>Date: ${esc(iv.date||'—')}</span><span>Due: ${esc(iv.due||'—')}</span>${iv.status?`<span>Status: ${esc(iv.status)}</span>`:''}</div>
    ${rows?`<table class="mtbl"><thead><tr><th>Item / description</th><th class="r">Qty</th><th class="r">Rate</th><th class="r">Tax</th><th class="r">Amount</th></tr></thead><tbody>${rows}</tbody></table>`:'<div class="muted">No line items recorded on this invoice.</div>'}
    <div style="margin-top:12px;border-top:1px solid var(--hair);padding-top:10px">${breakdown}</div>
    <div class="mtot"><span>Invoice total</span><span class="mono">${fmtC(cur,iv.total)}</span></div>
    ${(iv.balance!=null)?`<div class="mtot" style="color:var(--red)"><span>Balance due</span><span class="mono">${fmtC(cur,iv.balance)}</span></div>`:''}
    ${iv.notes?`<div class="muted" style="margin-top:14px;font-size:12px"><b style="color:var(--ink)">Notes:</b> ${esc(iv.notes)}</div>`:''}`;
}
function render(){
  const app=document.getElementById('app');if(!DATA)return;
  if(DATA.ok===false){app.innerHTML='<div class="empty" style="color:var(--red)">Couldn\'t load: '+esc(DATA.error||'failed')+'</div>';return;}
  app.innerHTML=`
    <div class="hero">
      <div>
        <div class="hl">To fiscalise</div>
        <div class="ht" id="hTotal">${esc(fmtC('KES',DATA.queueTotal))}</div>
        <div class="hs"><span id="hCount">${DATA.queueCount||0}</span> invoice${(DATA.queueCount===1)?'':'s'} waiting for an ETR</div>
      </div>
      <div class="hr">
        <div class="stat"><div class="n" id="hDone">${DATA.doneCount||0}</div><div class="l">Done</div></div>
      </div>
    </div>
    <div class="tool">
      <input class="srch" type="text" placeholder="Search invoice number or customer…" value="${att(Q)}" oninput="Q=this.value.toLowerCase();renderList()">
      <div class="chips" id="fchips"></div>
    </div>
    <div id="fList"></div>
    <div class="muted" style="font-size:11.5px;text-align:center;margin-top:14px">Updated ${DATA.asOf?new Date(DATA.asOf).toLocaleString('en-GB'):'now'} · the queue is set by what's been filed on the office PC</div>`;
  renderList();
}
function renderChips(){
  const box=document.getElementById('fchips');if(!box||!DATA)return;
  const rows=DATA.rows||[];
  const defs=[
    {k:'todo',label:'To fiscalise',cls:'amber',n:rows.filter(r=>!r.done).length},
    {k:'done',label:'Done',cls:'green',n:rows.filter(r=>r.done).length},
    {k:'all',label:'All',cls:'',n:rows.length},
  ];
  box.innerHTML=defs.map(d=>`<button class="chip ${VIEW===d.k?'on '+d.cls:''}" onclick="setView('${d.k}')">${d.label}<span class="c">${d.n}</span></button>`).join('');
}
function renderList(){
  renderChips();
  const h1=document.getElementById('hCount'),h2=document.getElementById('hDone'),h3=document.getElementById('hTotal');
  if(h1)h1.textContent=DATA.queueCount||0; if(h2)h2.textContent=DATA.doneCount||0; if(h3)h3.textContent=fmtC('KES',DATA.queueTotal);
  const box=document.getElementById('fList');if(!box||!DATA)return;
  let list=(DATA.rows||[]).slice();
  if(VIEW==='todo')list=list.filter(r=>!r.done);
  else if(VIEW==='done')list=list.filter(r=>r.done);
  if(Q)list=list.filter(r=>((r.number||'')+' '+(r.customer||'')).toLowerCase().indexOf(Q)>=0);
  if(!list.length){box.innerHTML='<div class="empty">'+(VIEW==='todo'?'Nothing waiting to fiscalise 🎉':'Nothing here.')+'</div>';return;}
  /* group by client */
  const groups={};
  list.forEach(r=>{ const k=(r.customer||'(unnamed)'); (groups[k]=groups[k]||[]).push(r); });
  const names=Object.keys(groups).sort((a,b)=>{
    const ma=Math.max(...groups[a].map(r=>r.days||0)), mb=Math.max(...groups[b].map(r=>r.days||0));
    return mb-ma || a.localeCompare(b);
  });
  box.innerHTML=`<table class="ftbl">
    <thead><tr><th>Invoice</th><th>What it's for</th><th>Filed</th><th>Waiting</th><th class="r">Amount (KES)</th><th class="r">Action</th></tr></thead>
    <tbody>${names.map(nm=>renderGroup(nm, groups[nm])).join('')}</tbody>
  </table>`;
}
function renderGroup(name, rows){
  rows=rows.slice().sort((a,b)=>(b.days||0)-(a.days||0));
  const tot=rows.reduce((s,r)=>s+(r.amount||0),0);
  const n=rows.length;
  const head=`<tr class="grow"><td colspan="6"><span class="gname">${esc(name)}</span><span class="gmeta">${n} invoice${n===1?'':'s'} · ${esc(fmtC('KES',tot))}</span></td></tr>`;
  const body=rows.map(r=>{
    const num=jsq(r.number);
    const btn=r.done
      ? `<button class="fbtn undo" onclick="mark('${num}',false,this)">Undo</button>`
      : `<button class="fbtn" onclick="mark('${num}',true,this)">Fiscalised ✓</button>`;
    const numCell=r.id?`<button class="num" onclick="openInv('${jsq(r.id)}')">${esc(r.number)}</button>`:`<span class="num" style="color:var(--ink)">${esc(r.number)}</span>`;
    return `<tr class="irow ${r.done?'done':''}">
      <td data-l="Invoice">${numCell}${r.done?' <span class="donetag">Done</span>':''}</td>
      <td data-l="What it's for" class="desc">${esc(r.descr||'—')}</td>
      <td data-l="Filed" class="mono dte">${esc(r.date||'—')}</td>
      <td data-l="Waiting">${daysPill(r.days||0)}</td>
      <td data-l="Amount" class="r amt">${esc(fmtC(r.currency||'KES',r.amount))}</td>
      <td data-l="Action" class="r">${btn}</td>
    </tr>`;
  }).join('');
  return head+body;
}
load();
</script>
<?php endif; ?>
</body></html>
    <?php
    exit;
}

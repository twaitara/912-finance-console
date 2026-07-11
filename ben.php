<?php
/* ben.php — BEN PORTAL. Private, login-protected view of ALL 2025–2026 invoices
   for the Fabrimetal / CIMMETAL / SteelRwa group of companies.
   Self-contained: only needs zoho.php + config.php (already on the server).
   Credentials are set by the admin in the console (Settings → Ben Portal access)
   and stored hashed in data/ben_auth.json. */

session_name('BENPORTAL');
session_start();
require __DIR__ . '/zoho.php';           // zoho_config(), zoho_api()
@set_time_limit(120);

$cfg      = zoho_config();
$authFile = __DIR__ . '/data/ben_auth.json';

/* ---- who is allowed in ---- */
function ben_creds($cfg, $authFile) {
    if (is_file($authFile)) {
        $j = json_decode(@file_get_contents($authFile), true);
        if (is_array($j) && !empty($j['user']) && !empty($j['hash'])) return ['user'=>$j['user'], 'hash'=>$j['hash'], 'mode'=>'hash'];
    }
    $u = trim((string)($cfg['ben_user'] ?? '')); $p = (string)($cfg['ben_pass'] ?? '');   // optional config.php fallback
    if ($u !== '' && $p !== '') return ['user'=>$u, 'pass'=>$p, 'mode'=>'plain'];
    return null;
}
$creds = ben_creds($cfg, $authFile);

/* ---- logout ---- */
if (isset($_GET['logout'])) { $_SESSION = []; session_destroy(); header('Location: ben.php'); exit; }

/* ---- login ---- */
$loginErr = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ben_login'])) {
    $u = trim((string)($_POST['u'] ?? '')); $p = (string)($_POST['p'] ?? '');
    $ok = false;
    if ($creds) {
        if ($creds['mode'] === 'hash') $ok = hash_equals(strtolower($creds['user']), strtolower($u)) && password_verify($p, $creds['hash']);
        else                           $ok = hash_equals($creds['user'], $u) && hash_equals($creds['pass'], $p);
    }
    if ($ok) { session_regenerate_id(true); $_SESSION['ben_auth'] = true; $_SESSION['ben_user'] = $u; header('Location: ben.php'); exit; }
    $loginErr = 'Wrong username or password.';
}
$authed = !empty($_SESSION['ben_auth']);

/* ---- JSON data endpoint (requires auth) ---- */
if (isset($_GET['data'])) {
    header('Content-Type: application/json; charset=utf-8');
    if (!$authed) { http_response_code(403); echo json_encode(['ok'=>false, 'error'=>'Not signed in.']); exit; }
    try { echo json_encode(ben_build_data(isset($_GET['refresh']))); }
    catch (\Throwable $e) { http_response_code(500); echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]); }
    exit;
}

/* ================= data builder ================= */
function ben_companies() {
    return [
        'Fabri Metal Congo SARL', 'CIMMETAL Burkina', 'SteelRwa Industries Ltd',
        'Fabrimetal Senegal Sebikotane', 'Fabrimetal Burundi', 'Fabrimetal Angola',
        'IMAFER Mali', 'Fabrimetal Ghana Ltd.', 'Fabrimetal Benin', 'MMD',
        'Fabrimetal Senegal SINDIA',
    ];
}
/* tolerant match key: lowercase, strip everything but letters/digits.
   "Fabri Metal Congo SARL" and "Fabrimetal Congo Sarl" collapse to the same key. */
function ben_key($s) { return preg_replace('/[^a-z0-9]/', '', strtolower((string)$s)); }

function ben_build_data($force) {
    $dir = __DIR__ . '/data'; if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $cache = $dir . '/ben_invoices_v1.json';
    if (!$force && is_file($cache) && (time() - filemtime($cache) < 900)) {
        $j = json_decode(file_get_contents($cache), true);
        if (is_array($j)) { $j['cached'] = true; return $j; }
    }

    $cfg = zoho_config();
    $companies = ben_companies();
    $map = [];
    foreach ($companies as $c) $map[ben_key($c)] = $c;

    // Pull invoices newest-first; stop once we drop below 2025 (list is date-sorted).
    $rows = []; $page = 1; $stop = false;
    do {
        [$d, $code] = zoho_api('GET', 'invoices', null, [
            'per_page' => 200, 'page' => $page, 'sort_column' => 'date', 'sort_order' => 'D',
        ]);
        if ($code >= 400) throw new Exception($d['message'] ?? 'Zoho error (invoices)');
        foreach (($d['invoices'] ?? []) as $inv) {
            $date = substr((string)($inv['date'] ?? ''), 0, 10);
            $yr   = substr($date, 0, 4);
            if ($yr !== '' && $yr < '2025') { $stop = true; continue; }   // older than 2025 → done after this page
            if ($yr !== '2025' && $yr !== '2026') continue;               // skip future-dated
            $st = (string)($inv['status'] ?? '');
            if ($st === 'void' || $st === 'draft') continue;
            $k = ben_key($inv['customer_name'] ?? '');
            if (!isset($map[$k])) continue;
            $rows[] = [
                'company'  => $map[$k],
                'number'   => (string)($inv['invoice_number'] ?? ''),
                'date'     => $date,
                'dueDate'  => substr((string)($inv['due_date'] ?? ''), 0, 10),
                'status'   => $st,
                'total'    => (float)($inv['total'] ?? 0),
                'balance'  => (float)($inv['balance'] ?? 0),
                'currency' => strtoupper((string)($inv['currency_code'] ?? ($cfg['currency'] ?? 'KES'))),
            ];
        }
        $more = $d['page_context']['has_more_page'] ?? false;
        $page++;
    } while ($more && !$stop && $page <= 80);

    // group by company (start from full list so every company appears, even with 0)
    $byCompany = [];
    foreach ($companies as $c) $byCompany[$c] = ['name'=>$c, 'invoices'=>[], 'invoicedByCur'=>[], 'outstandingByCur'=>[], 'count'=>0];
    $sumInv = []; $sumOut = []; $byYear = ['2025'=>[], '2026'=>[]]; $count = 0;
    foreach ($rows as $r) {
        $g   = &$byCompany[$r['company']];
        $cur = $r['currency'];
        $g['invoices'][] = $r; $g['count']++;
        $g['invoicedByCur'][$cur]    = ($g['invoicedByCur'][$cur] ?? 0) + $r['total'];
        $g['outstandingByCur'][$cur] = ($g['outstandingByCur'][$cur] ?? 0) + $r['balance'];
        $sumInv[$cur] = ($sumInv[$cur] ?? 0) + $r['total'];
        $sumOut[$cur] = ($sumOut[$cur] ?? 0) + $r['balance'];
        $yr = substr($r['date'], 0, 4);
        if (isset($byYear[$yr])) $byYear[$yr][$cur] = ($byYear[$yr][$cur] ?? 0) + $r['total'];
        $count++;
        unset($g);
    }
    foreach ($byCompany as &$g) { usort($g['invoices'], fn($a, $b) => strcmp($b['date'], $a['date'])); }
    unset($g);
    $companiesOut = array_values($byCompany);
    usort($companiesOut, function ($a, $b) { if ($b['count'] !== $a['count']) return $b['count'] - $a['count']; return strcasecmp($a['name'], $b['name']); });

    $out = [
        'ok' => true, 'asOf' => date('c'), 'cached' => false,
        'summary' => [
            'invoicedByCur'    => $sumInv,
            'outstandingByCur' => $sumOut,
            'byYear'           => $byYear,
            'count'            => $count,
            'companies'        => count(array_filter($companiesOut, fn($c) => $c['count'] > 0)),
        ],
        'companies' => $companiesOut,
    ];
    @file_put_contents($cache, json_encode($out));
    return $out;
}

$esc = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?>
<!doctype html><html><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>BEN PORTAL — WAITARA HOLDINGS GROUP OF COMPANIES CONSOLE</title>
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'%3E%3Crect width='64' height='64' rx='16' fill='%23F56F00'/%3E%3Ctext x='32' y='45' font-family='Arial' font-size='29' font-weight='700' fill='white' text-anchor='middle'%3E912%3C/text%3E%3C/svg%3E">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  :root{--orange:#F56F00;--blue:#2350C5;--ink:#15202B;--mute:#64748B;--line:#E6EAF0;--bg:#F4F6FA;--good:#16A34A;--bad:#D64933;--purple:#7C3AED}
  *{box-sizing:border-box}
  body{margin:0;font-family:Poppins,system-ui,Arial,sans-serif;background:var(--bg);color:var(--ink);font-size:13px;-webkit-font-smoothing:antialiased}
  .top{background:linear-gradient(135deg,#1B2A3A,#15202B);color:#fff;padding:10px 14px;display:flex;align-items:center;gap:10px;position:sticky;top:0;z-index:50;box-shadow:0 2px 10px rgba(0,0,0,.25)}
  .b{width:28px;height:28px;border-radius:7px;background:var(--orange);display:grid;place-items:center;font-weight:800;font-size:11px;color:#fff;flex:0 0 auto}
  .top h1{font-size:12px;margin:0;font-weight:700;letter-spacing:.4px}
  .top .sub{font-size:9.5px;color:#9AA7B8;letter-spacing:.4px}
  .top .sp{margin-left:auto;display:flex;gap:8px;align-items:center}
  .tbtn{border:1px solid rgba(255,255,255,.2);color:#fff;background:rgba(255,255,255,.1);border-radius:7px;padding:5px 11px;font-size:11px;font-weight:600;cursor:pointer;font-family:inherit;text-decoration:none;transition:background .15s}
  .tbtn:hover{background:rgba(255,255,255,.18)}
  .wrap{max-width:1000px;margin:0 auto;padding:12px 12px 24px}
  .card{background:#fff;border:1px solid var(--line);border-radius:12px}
  .muted{color:var(--mute);font-size:11px}
  .lab{font-size:9.5px;color:var(--mute);text-transform:uppercase;letter-spacing:.4px;font-weight:600}
  .val{font-weight:700;font-size:15px;margin-top:2px;line-height:1.25}
  .sum{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px}
  .sum .card{flex:1;min-width:150px;padding:10px 13px}
  .section-h{font-weight:700;font-size:11px;margin:16px 2px 6px;text-transform:uppercase;letter-spacing:.6px;color:var(--mute)}
  .co{margin-bottom:10px;overflow:hidden}
  .cohead{display:flex;align-items:center;gap:10px;flex-wrap:wrap;padding:10px 13px;background:linear-gradient(180deg,#FAFBFE,#F3F6FB);border-bottom:1px solid var(--line);cursor:pointer}
  .cohead .nm{font-weight:700;font-size:13px}
  .cohead .cnt{font-size:10px;font-weight:700;color:#fff;background:var(--purple);border-radius:20px;padding:1px 8px}
  .cohead .mtot{margin-left:auto;text-align:right;font-size:11px}
  .cohead .mtot .o{color:var(--bad);font-weight:700}
  .cohead .mtot .i{color:var(--ink);font-weight:600}
  table{width:100%;border-collapse:collapse;font-size:12px}
  th,td{padding:7px 10px;border-bottom:1px solid #F0F2F6;text-align:left}
  th{font-size:9px;text-transform:uppercase;color:var(--mute);letter-spacing:.3px;background:#F8FAFC;font-weight:700}
  td.amt,th.amt{text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums}
  tbody tr:hover{background:#FFF8F3}
  tfoot td{font-weight:700;border-top:2px solid var(--ink);background:#F4F7FB}
  .pill{display:inline-block;font-size:9.5px;font-weight:700;padding:2px 8px;border-radius:20px;text-transform:capitalize}
  .pill.paid{background:#E7F6EC;color:#0F7A34}
  .pill.overdue{background:#FDECEA;color:#B42318}
  .pill.sent,.pill.unpaid{background:#EEF2FE;color:#2350C5}
  .pill.partially_paid{background:#FFF4E5;color:#B45309}
  .pill.other{background:#EEF1F5;color:#475569}
  .empty td{color:var(--mute);font-style:italic}
  .bar{position:fixed;top:0;left:0;height:3px;background:var(--orange);width:0;transition:width .2s,opacity .3s;opacity:0;box-shadow:0 0 8px var(--orange);z-index:999}
  .pgfoot{text-align:center;padding:16px 12px 20px;line-height:1.7}
  .pgfoot .cn{font-size:11.5px;color:#64748B}.pgfoot .sc{font-size:11px;color:#F56F00;margin-top:4px}
  /* login */
  .login{max-width:360px;margin:9vh auto 0;padding:0 16px}
  .login .card{padding:22px}
  .login h2{margin:0 0 4px;font-size:17px}
  .login .in{width:100%;padding:11px 13px;border:1.5px solid var(--line);border-radius:9px;font-family:inherit;font-size:14px;margin-top:12px}
  .login .in:focus{outline:none;border-color:var(--orange);box-shadow:0 0 0 3px rgba(245,111,0,.12)}
  .login .go{width:100%;margin-top:14px;border:0;background:var(--orange);color:#fff;padding:12px;border-radius:9px;font-weight:700;font-size:14px;cursor:pointer;font-family:inherit}
  .login .go:hover{filter:brightness(1.05)}
  .login .err{background:#FDECEA;color:#B42318;border-radius:8px;padding:9px 11px;font-size:12px;margin-top:12px}
  @media(max-width:560px){ .sum .card{min-width:120px} .cohead .mtot{width:100%;text-align:left;margin-left:0} }
</style></head>
<body>
<div id="bar" class="bar"></div>
<div class="top">
  <div class="b">912</div>
  <div><h1>BEN PORTAL</h1><div class="sub">FABRIMETAL GROUP · 2025–2026 INVOICES</div></div>
  <?php if ($authed): ?>
  <div class="sp">
    <button class="tbtn" onclick="load(true)">⟳ Refresh</button>
    <a class="tbtn" href="ben.php?logout=1">Sign out</a>
  </div>
  <?php endif; ?>
</div>

<?php if (!$authed): ?>
<div class="login">
  <div class="card">
    <h2>Ben Portal</h2>
    <div class="muted">Sign in to view the group's 2025–2026 invoices.</div>
    <?php if (!$creds): ?>
      <div class="err">This portal isn't set up yet. The administrator needs to set a username and password in the console (Settings → Ben Portal access).</div>
    <?php else: ?>
    <form method="post" autocomplete="off">
      <input type="hidden" name="ben_login" value="1">
      <input class="in" type="text" name="u" placeholder="Username" autocapitalize="none" autocorrect="off" required>
      <input class="in" type="password" name="p" placeholder="Password" required>
      <?php if ($loginErr): ?><div class="err"><?= $esc($loginErr) ?></div><?php endif; ?>
      <button class="go" type="submit">Sign in</button>
    </form>
    <?php endif; ?>
  </div>
  <div class="pgfoot"><div class="cn">Waitara Holdings Group of Companies</div><div class="sc">SECURE PORTAL</div></div>
</div>
<?php else: ?>
<div class="wrap" id="app"><div class="card muted" style="padding:14px">Loading invoices…</div></div>
<div class="pgfoot"><div class="cn">Waitara Holdings Group of Companies · Ben Portal</div><div class="sc">CONFIDENTIAL</div></div>
<script>
const esc = s => String(s==null?'':s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
const fmtC = (cur,n) => (cur||'') + ' ' + Math.round(n||0).toLocaleString('en-US');
const fmtMap = m => { const k=Object.keys(m||{}); if(!k.length) return '—'; return k.sort().map(c=>fmtC(c,m[c])).join('  ·  '); };
let DATA=null;
function bar(s){ const b=document.getElementById('bar'); if(!b)return; if(s){b.style.opacity='1';b.style.width='80%';}else{b.style.width='100%';setTimeout(()=>{b.style.opacity='0';b.style.width='0';},300);} }

async function load(refresh){
  bar(true);
  try{ const r=await fetch('ben.php?data=1'+(refresh?'&refresh=1':''),{credentials:'same-origin'}); DATA=await r.json(); }
  catch(e){ DATA={ok:false,error:String(e)}; }
  bar(false); render();
}
function pillClass(s){ s=(s||'').toLowerCase(); return ['paid','overdue','sent','unpaid','partially_paid'].includes(s)?s:'other'; }

function render(){
  const app=document.getElementById('app'); if(!DATA) return;
  if(DATA.ok===false){ app.innerHTML='<div class="card" style="color:var(--bad);padding:14px">Error: '+esc(DATA.error||'failed')+'</div>'; return; }
  const s=DATA.summary||{};
  let html=`<div class="sum">
    <div class="card"><div class="lab">Total invoiced (2025–26)</div><div class="val">${esc(fmtMap(s.invoicedByCur))}</div></div>
    <div class="card"><div class="lab">Outstanding</div><div class="val" style="color:var(--bad)">${esc(fmtMap(s.outstandingByCur))}</div></div>
    <div class="card"><div class="lab">Invoices</div><div class="val">${s.count||0}</div></div>
    <div class="card"><div class="lab">Companies</div><div class="val">${s.companies||0}</div></div>
  </div>
  <div class="muted" style="margin:0 2px 6px">As of ${DATA.asOf?new Date(DATA.asOf).toLocaleString('en-GB'):'now'}. Invoiced by year — 2025: ${esc(fmtMap((s.byYear||{})['2025']))} · 2026: ${esc(fmtMap((s.byYear||{})['2026']))}</div>`;

  (DATA.companies||[]).forEach(c=>{
    const rows = c.invoices.length ? c.invoices.map(iv=>`<tr>
        <td>${esc(iv.number)}</td>
        <td>${esc(iv.date||'')}</td>
        <td><span class="pill ${pillClass(iv.status)}">${esc((iv.status||'').replace(/_/g,' '))}</span></td>
        <td class="amt">${esc(fmtC(iv.currency,iv.total))}</td>
        <td class="amt" style="color:${iv.balance>0?'var(--bad)':'var(--good)'}">${esc(fmtC(iv.currency,iv.balance))}</td>
      </tr>`).join('')
      : `<tr class="empty"><td colspan="5">No 2025–2026 invoices for this company.</td></tr>`;
    const foot = c.invoices.length ? `<tfoot><tr>
        <td colspan="3">Total · ${c.count} invoice${c.count===1?'':'s'}</td>
        <td class="amt">${esc(fmtMap(c.invoicedByCur))}</td>
        <td class="amt" style="color:var(--bad)">${esc(fmtMap(c.outstandingByCur))}</td></tr></tfoot>` : '';
    html += `<div class="section-h" style="margin-bottom:4px">&nbsp;</div>
      <div class="card co">
        <div class="cohead">
          <span class="nm">${esc(c.name)}</span>
          <span class="cnt">${c.count}</span>
          <span class="mtot"><span class="i">${esc(fmtMap(c.invoicedByCur))}</span> &nbsp;·&nbsp; <span class="o">${esc(fmtMap(c.outstandingByCur))} due</span></span>
        </div>
        <div style="overflow-x:auto"><table>
          <thead><tr><th>Invoice #</th><th>Date</th><th>Status</th><th class="amt">Total</th><th class="amt">Balance</th></tr></thead>
          <tbody>${rows}</tbody>${foot}
        </table></div>
      </div>`;
  });
  app.innerHTML=html;
}
load(false);
</script>
<?php endif; ?>
</body></html>

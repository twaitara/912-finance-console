<?php
/* portal_grace.php — GRACE collections portal. Login-protected tracker of ALL
   unpaid invoices for chasing payment: customer, phone (tap-to-call + WhatsApp),
   amount, days overdue, and a per-customer status + notes she fills in.
   Included by index.php; served at ?portal=grace (and the /grace shortcut).
   Credentials + disable switch are managed by the admin in the Portals tab. */

if (!function_exists('gp_build')) {
    function gp_prefs($dir) { $f = $dir . '/grace_prefs.json'; $j = is_file($f) ? (json_decode(@file_get_contents($f), true) ?: []) : []; return ['disabled' => !empty($j['disabled'])]; }
    function gp_phones_load($dir) { $f = $dir . '/grace_phones.json'; return is_file($f) ? (json_decode(@file_get_contents($f), true) ?: []) : []; }
    function gp_notes_table($pdo) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS grace_notes (
            zoho_customer_id VARCHAR(64) PRIMARY KEY,
            customer_name VARCHAR(190) DEFAULT '',
            status VARCHAR(20) DEFAULT '',
            note TEXT NULL,
            promised_date DATE NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    /* All unpaid invoices grouped by customer, with cached phone + overlaid notes. */
    function gp_build($force) {
        $dir = __DIR__ . '/data'; if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $cache = $dir . '/grace_unpaid_v1.json';
        if (!$force && is_file($cache) && (time() - filemtime($cache) < 900)) { $j = json_decode(file_get_contents($cache), true); if (is_array($j)) { $j['cached'] = true; return $j; } }
        $cfg = zoho_config();

        // 1) all unpaid invoices
        $rows = []; $page = 1;
        do {
            [$d, $c] = zoho_api('GET', 'invoices', null, ['filter_by'=>'Status.Unpaid', 'per_page'=>200, 'page'=>$page, 'sort_column'=>'due_date']);
            if ($c >= 400) throw new Exception($d['message'] ?? 'Zoho error (invoices)');
            foreach (($d['invoices'] ?? []) as $inv) {
                $bal = (float)($inv['balance'] ?? 0); if ($bal <= 0) continue;
                if (($inv['status'] ?? '') === 'void') continue;
                $rows[] = [
                    'cid'      => (string)($inv['customer_id'] ?? ''),
                    'customer' => (string)($inv['customer_name'] ?? ''),
                    'number'   => (string)($inv['invoice_number'] ?? ''),
                    'date'     => substr((string)($inv['date'] ?? ''), 0, 10),
                    'due'      => substr((string)($inv['due_date'] ?? ''), 0, 10),
                    'balance'  => $bal,
                    'currency' => strtoupper((string)($inv['currency_code'] ?? ($cfg['currency'] ?? 'KES'))),
                ];
            }
            $more = $d['page_context']['has_more_page'] ?? false; $page++;
        } while ($more && $page <= 40);

        // 2) group by customer
        $today = date('Y-m-d');
        $byCust = [];
        foreach ($rows as $r) {
            $key = $r['cid'] !== '' ? $r['cid'] : ('name:' . mb_strtolower($r['customer']));
            if (!isset($byCust[$key])) $byCust[$key] = ['cid'=>$r['cid'], 'customer'=>$r['customer'], 'invoices'=>[], 'totalByCur'=>[], 'sortTotal'=>0, 'maxOverdue'=>0];
            $days = ($r['due'] !== '') ? (int)floor((strtotime($today) - strtotime($r['due'])) / 86400) : 0; if ($days < 0) $days = 0;
            $r['overdue'] = $days;
            $g = &$byCust[$key];
            $g['invoices'][] = $r;
            $g['totalByCur'][$r['currency']] = ($g['totalByCur'][$r['currency']] ?? 0) + $r['balance'];
            $g['sortTotal'] += $r['balance'];
            if ($days > $g['maxOverdue']) $g['maxOverdue'] = $days;
            unset($g);
        }

        // 3) phones (cached persistently; fetch uncached, capped per load)
        $phones = gp_phones_load($dir); $dirty = false; $fetched = 0; $CAP = 60;
        foreach ($byCust as &$g) {
            $id = $g['cid'];
            if ($id === '') { $g['phone'] = ''; $g['mobile'] = ''; continue; }
            if (isset($phones[$id])) { $g['phone'] = (string)($phones[$id]['phone'] ?? ''); $g['mobile'] = (string)($phones[$id]['mobile'] ?? ''); continue; }
            if ($fetched >= $CAP) { $g['phone'] = ''; $g['mobile'] = ''; continue; }
            $ph = ''; $mob = '';
            try {
                [$cd, $cc] = zoho_api('GET', 'contacts/' . rawurlencode($id));
                if ($cc < 400 && !empty($cd['contact'])) {
                    $ct = $cd['contact'];
                    $ph = trim((string)($ct['phone'] ?? '')); $mob = trim((string)($ct['mobile'] ?? ''));
                    if ($ph === '' && $mob === '') { foreach (($ct['contact_persons'] ?? []) as $cp) { $ph = trim((string)($cp['phone'] ?? '')); $mob = trim((string)($cp['mobile'] ?? '')); if ($ph !== '' || $mob !== '') break; } }
                }
            } catch (Exception $e) { /* leave blank */ }
            $phones[$id] = ['phone'=>$ph, 'mobile'=>$mob, 'name'=>$g['customer']]; $dirty = true; $fetched++;
            $g['phone'] = $ph; $g['mobile'] = $mob;
        }
        unset($g);
        if ($dirty) @file_put_contents($dir . '/grace_phones.json', json_encode($phones));

        // 4) notes overlay
        $notes = [];
        try { $pdo = db(); gp_notes_table($pdo); foreach ($pdo->query("SELECT zoho_customer_id,status,note,promised_date FROM grace_notes")->fetchAll(PDO::FETCH_ASSOC) as $n) { $notes[(string)$n['zoho_customer_id']] = $n; } } catch (Exception $e) { /* no notes yet */ }

        // 5) compose
        $out = []; $totalByCur = []; $invCount = 0;
        foreach ($byCust as $g) {
            usort($g['invoices'], fn($a, $b) => strcmp($a['due'], $b['due']));   // most overdue first
            $n = ($g['cid'] !== '' && isset($notes[$g['cid']])) ? $notes[$g['cid']] : null;
            $out[] = [
                'cid'=>$g['cid'], 'customer'=>$g['customer'], 'phone'=>$g['phone'], 'mobile'=>$g['mobile'],
                'totalByCur'=>$g['totalByCur'], 'sortTotal'=>$g['sortTotal'], 'count'=>count($g['invoices']),
                'maxOverdue'=>$g['maxOverdue'], 'invoices'=>$g['invoices'],
                'status'=>$n['status'] ?? '', 'note'=>$n['note'] ?? '', 'promised'=>$n['promised_date'] ?? '',
            ];
            foreach ($g['totalByCur'] as $cur => $amt) $totalByCur[$cur] = ($totalByCur[$cur] ?? 0) + $amt;
            $invCount += count($g['invoices']);
        }
        usort($out, fn($a, $b) => $b['sortTotal'] <=> $a['sortTotal']);   // biggest owed first
        $res = ['ok'=>true, 'asOf'=>date('c'), 'cached'=>false, 'customers'=>$out, 'totalByCur'=>$totalByCur, 'custCount'=>count($out), 'invCount'=>$invCount];
        @file_put_contents($cache, json_encode($res));
        return $res;
    }
}

if (isset($_GET['portal']) && $_GET['portal'] === 'grace') {
    session_name('GRACEPORTAL');
    session_start();
    require_once __DIR__ . '/csrf.php'; csrf_guard();
    require_once __DIR__ . '/errors.php';
    require_once __DIR__ . '/zoho.php';
    require_once __DIR__ . '/db.php';
    @set_time_limit(120);
    $gDir = __DIR__ . '/data';

    $gAuthFile = $gDir . '/grace_auth.json';
    $gCreds = null;
    if (is_file($gAuthFile)) { $j = json_decode(@file_get_contents($gAuthFile), true); if (is_array($j) && !empty($j['user']) && !empty($j['hash'])) $gCreds = ['user'=>$j['user'], 'hash'=>$j['hash']]; }
    $gDisabled = !empty(gp_prefs($gDir)['disabled']);

    if (isset($_GET['logout'])) { $_SESSION = []; session_destroy(); header('Location: index.php?portal=grace'); exit; }

    $gErr = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['grace_login'])) {
        $u = trim((string)($_POST['u'] ?? '')); $p = (string)($_POST['p'] ?? '');
        $ok = false;
        if ($gCreds && !$gDisabled) $ok = hash_equals(strtolower($gCreds['user']), strtolower($u)) && password_verify($p, $gCreds['hash']);
        if ($ok) { session_regenerate_id(true); $_SESSION['grace_auth'] = true; $_SESSION['grace_user'] = $u; header('Location: index.php?portal=grace'); exit; }
        $gErr = $gDisabled ? 'This portal is currently disabled.' : 'Wrong username or password.';
    }
    $gAuthed = !$gDisabled && !empty($_SESSION['grace_auth']);

    if (isset($_GET['data'])) {
        header('Content-Type: application/json; charset=utf-8');
        if (!$gAuthed) { http_response_code(403); echo json_encode(['ok'=>false, 'error'=>'Not signed in.']); exit; }
        try { echo json_encode(gp_build(isset($_GET['refresh']))); }
        catch (\Throwable $e) { http_response_code(500); echo api_fail($e); }
        exit;
    }

    if (isset($_GET['save'])) {
        header('Content-Type: application/json; charset=utf-8');
        if (!$gAuthed) { http_response_code(403); echo json_encode(['ok'=>false, 'error'=>'Not signed in.']); exit; }
        try {
            $in = json_decode(file_get_contents('php://input'), true) ?: [];
            $cid = trim((string)($in['customer_id'] ?? '')); if ($cid === '') { echo json_encode(['ok'=>false, 'error'=>'No customer id.']); exit; }
            $name = mb_substr(trim((string)($in['customer_name'] ?? '')), 0, 190);
            $status = in_array(($in['status'] ?? ''), ['Called', 'Promised', 'No answer', 'Paid', ''], true) ? (string)($in['status'] ?? '') : '';
            $note = mb_substr((string)($in['note'] ?? ''), 0, 3000);
            $pd = trim((string)($in['promised_date'] ?? '')); $pd = preg_match('/^\d{4}-\d{2}-\d{2}$/', $pd) ? $pd : null;
            $pdo = db(); gp_notes_table($pdo);
            $pdo->prepare("INSERT INTO grace_notes (zoho_customer_id,customer_name,status,note,promised_date) VALUES (?,?,?,?,?)
                ON DUPLICATE KEY UPDATE customer_name=VALUES(customer_name),status=VALUES(status),note=VALUES(note),promised_date=VALUES(promised_date)")
                ->execute([$cid, $name, $status, $note, $pd]);
            echo json_encode(['ok'=>true]);
        } catch (\Throwable $e) { echo api_fail($e); }
        exit;
    }

    $gEsc = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    ?>
<!doctype html><html><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>GRACE — COLLECTIONS · WAITARA HOLDINGS GROUP</title>
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'%3E%3Crect width='64' height='64' rx='16' fill='%23F56F00'/%3E%3Ctext x='32' y='45' font-family='Arial' font-size='29' font-weight='700' fill='white' text-anchor='middle'%3E912%3C/text%3E%3C/svg%3E">
<style>
  :root{--orange:#F56F00;--blue:#2350C5;--ink:#15202B;--mute:#64748B;--line:#E6EAF0;--bg:#F4F6FA;--card:#fff;--good:#16A34A;--bad:#D64933;--warn:#B45309}
  *{box-sizing:border-box}
  body{margin:0;font-family:'Segoe UI',system-ui,Arial,sans-serif;background:var(--bg);color:var(--ink);font-size:13px;-webkit-font-smoothing:antialiased}
  .top{background:linear-gradient(135deg,#1B2A3A,#111c29);color:#fff;padding:10px 14px;display:flex;align-items:center;gap:10px;position:sticky;top:0;z-index:50;box-shadow:0 2px 10px rgba(0,0,0,.25)}
  .b{width:28px;height:28px;border-radius:7px;background:var(--orange);display:grid;place-items:center;font-weight:800;font-size:11px;color:#fff;flex:0 0 auto}
  .top h1{font-size:12px;margin:0;font-weight:700;letter-spacing:.4px}
  .top .sub{font-size:9.5px;color:#9AA7B8;letter-spacing:.4px}
  .top .sp{margin-left:auto;display:flex;gap:8px}
  .tbtn{border:1px solid rgba(255,255,255,.2);color:#fff;background:rgba(255,255,255,.1);border-radius:8px;padding:6px 12px;font-size:11px;font-weight:600;cursor:pointer;font-family:inherit;text-decoration:none}
  .tbtn:hover{background:rgba(255,255,255,.18)}
  .wrap{max-width:1000px;margin:0 auto;padding:12px 12px 28px}
  .card{background:var(--card);border:1px solid var(--line);border-radius:14px}
  .muted{color:var(--mute);font-size:11px}
  .sum{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px}
  .sum .card{flex:1;min-width:150px;padding:11px 13px}
  .lab{font-size:9.5px;color:var(--mute);text-transform:uppercase;letter-spacing:.4px;font-weight:600}
  .val{font-weight:700;font-size:16px;margin-top:2px;line-height:1.2}
  .ctrls{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:12px}
  .ctrls input{flex:1;min-width:180px;padding:11px 13px;border:1.5px solid var(--line);border-radius:10px;font-family:inherit;font-size:14px}
  .ctrls input:focus{outline:none;border-color:var(--orange);box-shadow:0 0 0 3px rgba(245,111,0,.12)}
  .sortbtn{border:1px solid var(--line);background:var(--card);border-radius:10px;padding:10px 14px;font-size:12px;font-weight:600;cursor:pointer;font-family:inherit;color:var(--ink);white-space:nowrap}
  .cu{margin-bottom:10px;overflow:hidden}
  .cuhead{display:flex;align-items:flex-start;gap:10px;flex-wrap:wrap;padding:12px 14px;border-bottom:1px solid var(--line);background:linear-gradient(180deg,#FAFBFE,#F3F6FB)}
  .cuhead .nm{font-weight:700;font-size:14px}
  .cuhead .ph{display:flex;gap:8px;flex-wrap:wrap;margin-top:6px}
  .calbtn{display:inline-flex;align-items:center;gap:6px;border-radius:20px;padding:6px 13px;font-size:12px;font-weight:700;text-decoration:none}
  .calbtn.tel{background:#EEF2FE;color:var(--blue)}
  .calbtn.wa{background:#E7F6EC;color:#0F7A34}
  .calbtn.none{background:#F1F4F8;color:var(--mute);font-weight:600}
  .cuhead .rt{margin-left:auto;text-align:right}
  .cuhead .owed{font-weight:800;font-size:15px;color:var(--ink)}
  .ovd{display:inline-block;border-radius:20px;padding:2px 9px;font-size:10px;font-weight:700;margin-top:4px}
  .ovd.lo{background:#FFF4E5;color:var(--warn)} .ovd.hi{background:#FDECEA;color:var(--bad)} .ovd.ok{background:#E7F6EC;color:#0F7A34}
  table{width:100%;border-collapse:collapse;font-size:12px}
  th,td{padding:7px 12px;border-bottom:1px solid #F0F2F6;text-align:left}
  th{font-size:9px;text-transform:uppercase;color:var(--mute);letter-spacing:.3px;background:#F8FAFC;font-weight:700}
  td.amt,th.amt{text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums}
  .foll{padding:12px 14px;background:#FCFDFF;border-top:1px solid var(--line);display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end}
  .foll .fld{display:flex;flex-direction:column;gap:3px}
  .foll label{font-size:9.5px;color:var(--mute);text-transform:uppercase;letter-spacing:.3px;font-weight:600}
  .foll select,.foll input[type=date]{padding:8px 10px;border:1px solid var(--line);border-radius:9px;font-family:inherit;font-size:12.5px;background:#fff}
  .foll textarea{flex:1;min-width:200px;min-height:40px;padding:8px 11px;border:1px solid var(--line);border-radius:9px;font-family:inherit;font-size:12.5px;resize:vertical}
  .foll textarea:focus,.foll select:focus,.foll input:focus{outline:none;border-color:var(--orange)}
  .saved{font-size:10px;color:var(--good);font-weight:700;opacity:0;transition:opacity .2s}
  .saved.show{opacity:1}
  .bar{position:fixed;top:0;left:0;height:3px;background:var(--orange);width:0;transition:width .2s,opacity .3s;opacity:0;z-index:999}
  .pgfoot{text-align:center;padding:16px 12px 24px}.pgfoot .cn{font-size:11.5px;color:#64748B}.pgfoot .sc{font-size:11px;color:#F56F00;margin-top:4px}
  .login{max-width:360px;margin:9vh auto 0;padding:0 16px}
  .login .card{padding:22px}.login h2{margin:0 0 4px;font-size:17px}
  .login .in{width:100%;padding:11px 13px;border:1.5px solid var(--line);border-radius:9px;font-family:inherit;font-size:14px;margin-top:12px}
  .login .in:focus{outline:none;border-color:var(--orange);box-shadow:0 0 0 3px rgba(245,111,0,.12)}
  .login .go{width:100%;margin-top:14px;border:0;background:var(--orange);color:#fff;padding:12px;border-radius:9px;font-weight:700;font-size:14px;cursor:pointer;font-family:inherit}
  .login .err{background:#FDECEA;color:#B42318;border-radius:8px;padding:9px 11px;font-size:12px;margin-top:12px}
  @media(max-width:560px){ .sum .card{min-width:120px} .cuhead .rt{margin-left:0;width:100%;text-align:left;margin-top:6px} }
</style></head>
<body>
<div id="bar" class="bar"></div>
<div class="top">
  <div class="b">912</div>
  <div><h1>GRACE · COLLECTIONS</h1><div class="sub">UNPAID INVOICES — FOLLOW-UP TRACKER</div></div>
  <?php if ($gAuthed): ?>
  <div class="sp"><button class="tbtn" onclick="load(true)">⟳ Refresh</button><a class="tbtn" href="index.php?portal=grace&logout=1">Sign out</a></div>
  <?php endif; ?>
</div>
<?php if (!$gAuthed): ?>
<div class="login">
  <div class="card">
    <h2>Grace — Collections</h2>
    <div class="muted">Sign in to see unpaid invoices and record your follow-up.</div>
    <?php if ($gDisabled): ?>
      <div class="err">This portal is currently disabled. Please contact the administrator.</div>
    <?php elseif (!$gCreds): ?>
      <div class="err">This portal isn't set up yet. The administrator needs to set a username and password (Settings → Portals).</div>
    <?php else: ?>
    <form method="post" action="index.php?portal=grace" autocomplete="off">
      <input type="hidden" name="grace_login" value="1">
      <input class="in" type="text" name="u" placeholder="Username" autocapitalize="none" autocorrect="off" required>
      <input class="in" type="password" name="p" placeholder="Password" required>
      <?php if ($gErr): ?><div class="err"><?= $gEsc($gErr) ?></div><?php endif; ?>
      <button class="go" type="submit">Sign in</button>
    </form>
    <?php endif; ?>
  </div>
  <div class="pgfoot"><div class="cn">Waitara Holdings Group of Companies</div><div class="sc">SECURE PORTAL</div></div>
</div>
<?php else: ?>
<div class="wrap" id="app"><div class="card muted" style="padding:14px">Loading unpaid invoices…</div></div>
<div class="pgfoot"><div class="cn">Waitara Holdings Group of Companies · Collections</div><div class="sc">CONFIDENTIAL</div></div>
<script>
const esc=s=>String(s==null?'':s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
const fmtC=(cur,n)=>(cur||'')+' '+Math.round(n||0).toLocaleString('en-US');
const fmtMap=m=>{const k=Object.keys(m||{});if(!k.length)return '—';return k.sort().map(c=>fmtC(c,m[c])).join('  ·  ');};
let DATA=null, Q='', SORT='owed';
function bar(s){const b=document.getElementById('bar');if(!b)return;if(s){b.style.opacity='1';b.style.width='80%';}else{b.style.width='100%';setTimeout(()=>{b.style.opacity='0';b.style.width='0';},300);}}
async function load(refresh){bar(true);try{const r=await fetch('index.php?portal=grace&data=1'+(refresh?'&refresh=1':''),{credentials:'same-origin'});DATA=await r.json();}catch(e){DATA={ok:false,error:String(e)};}bar(false);render();}
/* normalize a Kenyan number for WhatsApp (wa.me needs full international, digits only) */
function waNum(ph,mob){let s=(mob||ph||'').replace(/[^0-9]/g,'');if(!s)return '';if(s.startsWith('254'))return s;if(s.startsWith('0'))return '254'+s.slice(1);if(s.length===9&&(s[0]==='7'||s[0]==='1'))return '254'+s;return s;}
function telNum(ph,mob){return (ph||mob||'').replace(/[^0-9+]/g,'');}
function ovdClass(d){return d>=30?'hi':(d>0?'lo':'ok');}
function setField(cid,field,val){(DATA.customers||[]).forEach(c=>{if(c.cid===cid)c[field]=val;});}
let saveT={};
function saveCust(cid,badgeId){
  const c=(DATA.customers||[]).find(x=>x.cid===cid);if(!c)return;
  clearTimeout(saveT[cid]);
  saveT[cid]=setTimeout(async()=>{
    try{const r=await fetch('index.php?portal=grace&save=1',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({customer_id:cid,customer_name:c.customer,status:c.status||'',note:c.note||'',promised_date:c.promised||''})});
      const j=await r.json();if(j.ok){const b=document.getElementById(badgeId);if(b){b.classList.add('show');setTimeout(()=>b.classList.remove('show'),1500);}}}catch(e){}
  },500);
}
function onStatus(cid,v,bid){setField(cid,'status',v);saveCust(cid,bid);}
function onPromised(cid,v,bid){setField(cid,'promised',v);saveCust(cid,bid);}
function onNote(cid,v,bid){setField(cid,'note',v);saveCust(cid,bid);}
function setSort(s){SORT=s;render();}
function filterSearch(v){Q=(v||'').toLowerCase();renderList();}
function render(){
  const app=document.getElementById('app');if(!DATA)return;
  if(DATA.ok===false){app.innerHTML='<div class="card" style="color:var(--bad);padding:14px">Error: '+esc(DATA.error||'failed')+'</div>';return;}
  app.innerHTML=`<div class="sum">
      <div class="card"><div class="lab">Total outstanding</div><div class="val" style="color:var(--bad)">${esc(fmtMap(DATA.totalByCur))}</div></div>
      <div class="card"><div class="lab">Customers</div><div class="val">${DATA.custCount||0}</div></div>
      <div class="card"><div class="lab">Invoices</div><div class="val">${DATA.invCount||0}</div></div>
    </div>
    <div class="ctrls">
      <input id="gSearch" type="text" placeholder="🔍 Search customer or phone…" value="${esc(Q)}" oninput="filterSearch(this.value)">
      <button class="sortbtn" onclick="setSort('owed')" style="${SORT==='owed'?'border-color:var(--orange);color:var(--orange)':''}">By amount owed</button>
      <button class="sortbtn" onclick="setSort('overdue')" style="${SORT==='overdue'?'border-color:var(--orange);color:var(--orange)':''}">By days overdue</button>
    </div>
    <div class="muted" style="margin:0 2px 8px">As of ${DATA.asOf?new Date(DATA.asOf).toLocaleString('en-GB'):'now'}. Your status/notes are your follow-up record — they don't change Zoho.</div>
    <div id="gList"></div>`;
  renderList();
}
function renderList(){
  const box=document.getElementById('gList');if(!box||!DATA)return;
  let list=(DATA.customers||[]).slice();
  if(SORT==='overdue')list.sort((a,b)=>(b.maxOverdue||0)-(a.maxOverdue||0));
  else list.sort((a,b)=>(b.sortTotal||0)-(a.sortTotal||0));
  if(Q)list=list.filter(c=>((c.customer||'')+' '+(c.phone||'')+' '+(c.mobile||'')).toLowerCase().indexOf(Q)>=0);
  if(!list.length){box.innerHTML='<div class="card muted" style="padding:16px">No unpaid invoices match.</div>';return;}
  let idc=0;
  box.innerHTML=list.map(c=>{
    const wid='sv'+(idc++);
    const wa=waNum(c.phone,c.mobile), tel=telNum(c.phone,c.mobile);
    const phoneTxt=c.mobile||c.phone||'';
    const phoneBtns = (tel||wa)
      ? `<a class="calbtn tel" href="tel:${esc(tel)}">📞 ${esc(phoneTxt)}</a>${wa?`<a class="calbtn wa" href="https://wa.me/${esc(wa)}" target="_blank" rel="noopener">WhatsApp</a>`:''}`
      : `<span class="calbtn none">No phone on file</span>`;
    const rows=c.invoices.map(iv=>`<tr>
        <td>${esc(iv.number)}</td><td>${esc(iv.date||'')}</td><td>${esc(iv.due||'—')}</td>
        <td style="text-align:center"><span class="ovd ${ovdClass(iv.overdue)}">${iv.overdue>0?iv.overdue+'d':'due'}</span></td>
        <td class="amt">${esc(fmtC(iv.currency,iv.balance))}</td></tr>`).join('');
    const ov=c.maxOverdue||0;
    const STATUSES=['','Called','Promised','No answer','Paid'];
    return `<div class="card cu">
      <div class="cuhead">
        <div style="flex:1;min-width:0">
          <div class="nm">${esc(c.customer||'(unnamed)')}</div>
          <div class="ph">${phoneBtns}</div>
        </div>
        <div class="rt">
          <div class="owed">${esc(fmtMap(c.totalByCur))}</div>
          <div><span class="ovd ${ovdClass(ov)}">${ov>0?('Oldest '+ov+'d overdue'):'Not overdue'}</span> <span class="muted">· ${c.count} inv${c.count===1?'':'s'}</span></div>
        </div>
      </div>
      <div style="overflow-x:auto"><table>
        <thead><tr><th>Invoice #</th><th>Date</th><th>Due</th><th style="text-align:center">Overdue</th><th class="amt">Balance</th></tr></thead>
        <tbody>${rows}</tbody>
      </table></div>
      <div class="foll">
        <div class="fld"><label>Follow-up</label>
          <select onchange="onStatus('${esc(c.cid)}',this.value,'${wid}')">${STATUSES.map(s=>`<option value="${s}" ${c.status===s?'selected':''}>${s||'—'}</option>`).join('')}</select></div>
        <div class="fld"><label>Promised date</label>
          <input type="date" value="${esc(c.promised||'')}" onchange="onPromised('${esc(c.cid)}',this.value,'${wid}')"></div>
        <textarea placeholder="Notes — what they said, when to call back…" oninput="onNote('${esc(c.cid)}',this.value,'${wid}')">${esc(c.note||'')}</textarea>
        <span id="${wid}" class="saved">saved ✓</span>
      </div>
    </div>`;
  }).join('');
}
load(false);
</script>
<?php endif; ?>
</body></html>
    <?php
    exit;
}

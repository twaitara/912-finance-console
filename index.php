<?php
session_start();
$cfg = require __DIR__ . '/config.php';
require_once __DIR__ . '/settings_store.php';
require_once __DIR__ . '/users_store.php';
$cfg = array_merge($cfg, app_settings_effective($cfg));   // user settings (with defaults) override config

// --- login gate (master password = admin; or a per-user account) ---
if (isset($_POST['app_password'])) {
    $pw = $_POST['app_password'];
    $un = trim($_POST['app_user'] ?? '');
    if (hash_equals($cfg['app_password'], $pw)) {
        $_SESSION['auth'] = true; $_SESSION['user'] = 'admin'; $_SESSION['is_admin'] = 1; $_SESSION['tabs'] = '*';
    } else {
        $row = false;
        try { require_once __DIR__ . '/db.php'; $row = user_authenticate(db(), $un, $pw); } catch (Exception $e) { $row = false; }
        if ($row) {
            $_SESSION['auth'] = true;
            $_SESSION['user'] = $row['username'];
            $_SESSION['email'] = $row['email'] ?? '';
            $_SESSION['is_admin'] = (int)$row['is_admin'] ? 1 : 0;
            $_SESSION['tabs'] = (int)$row['is_admin'] ? '*' : (string)($row['tabs'] ?? '');
        } else {
            $loginError = 'Wrong username or password.';
        }
    }
}
if (isset($_GET['logout'])) { session_destroy(); header('Location: index.php'); exit; }

if (empty($_SESSION['auth'])):
?><!DOCTYPE html>
<html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>912 FINANCE CONSOLE</title>
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'%3E%3Crect width='64' height='64' rx='16' fill='%23F56F00'/%3E%3Ctext x='32' y='45' font-family='Poppins,Arial,sans-serif' font-size='29' font-weight='700' fill='white' text-anchor='middle'%3E912%3C/text%3E%3C/svg%3E">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
  *{box-sizing:border-box}
  body{font-family:Poppins,system-ui,sans-serif;margin:0;min-height:100vh;display:grid;place-items:center;color:#15202B;
       background:radial-gradient(1100px 700px at 18% -10%,#22344A 0%,transparent 55%),
                  radial-gradient(900px 600px at 110% 120%,rgba(245,111,0,.22) 0%,transparent 55%),
                  linear-gradient(135deg,#1B2A3A 0%,#15202B 55%,#0E1822 100%);
       overflow:hidden}
  /* soft floating glow accents */
  body::before,body::after{content:"";position:fixed;border-radius:50%;filter:blur(8px);pointer-events:none;z-index:0}
  body::before{width:260px;height:260px;left:-70px;top:-60px;background:radial-gradient(circle,rgba(245,111,0,.20),transparent 68%)}
  body::after{width:340px;height:340px;right:-110px;bottom:-120px;background:radial-gradient(circle,rgba(35,80,197,.20),transparent 68%)}
  .card{position:relative;z-index:1;background:rgba(255,255,255,.96);padding:34px 30px 30px;border-radius:22px;width:320px;text-align:center;
        box-shadow:0 30px 70px rgba(8,16,24,.55),0 2px 0 rgba(255,255,255,.5) inset;border:1px solid rgba(255,255,255,.5);
        animation:rise .5s cubic-bezier(.22,.61,.36,1) both}
  @keyframes rise{from{opacity:0;transform:translateY(16px) scale(.985)}to{opacity:1;transform:none}}
  .b{width:54px;height:54px;border-radius:16px;background:linear-gradient(140deg,#FF8A1E,#F56F00 65%);color:#fff;display:grid;place-items:center;
     font-weight:800;font-size:18px;margin:0 auto 16px;box-shadow:0 12px 26px rgba(245,111,0,.42),0 0 0 6px rgba(245,111,0,.10)}
  .ttl{font-weight:700;font-size:16px;letter-spacing:.2px}
  .sub{font-size:11px;color:#64748B;margin:4px 0 20px;letter-spacing:.6px;text-transform:uppercase}
  .fld{position:relative;margin-bottom:12px}
  input{width:100%;padding:13px 14px;border:1px solid #E6EAF0;border-radius:12px;font-size:14px;font-family:inherit;background:#FBFCFE;
        transition:border-color .15s,box-shadow .15s,background .15s}
  input:focus{outline:none;border-color:#F56F00;background:#fff;box-shadow:0 0 0 4px rgba(245,111,0,.15)}
  input::placeholder{color:#94A3B8}
  button{width:100%;padding:13px;background:linear-gradient(140deg,#FF8A1E,#F56F00 70%);color:#fff;border:none;border-radius:12px;font-weight:700;font-size:14px;
         font-family:inherit;cursor:pointer;margin-top:4px;box-shadow:0 12px 24px rgba(245,111,0,.34);transition:transform .12s,box-shadow .2s,filter .15s}
  button:hover{filter:brightness(1.04);box-shadow:0 16px 30px rgba(245,111,0,.42)}
  button:active{transform:scale(.98)}
  .err{color:#D64933;background:#FDECEA;font-size:12px;font-weight:600;margin-bottom:12px;padding:9px 11px;border-radius:10px}
  .foot{margin-top:18px;font-size:10.5px;color:#94A3B8;letter-spacing:.4px}
  @media (prefers-reduced-motion:reduce){*{animation:none!important}}
</style></head>
<body><form class="card" method="post" autocomplete="off">
  <div class="b">912</div>
  <div class="ttl">912 FINANCE CONSOLE</div>
  <div class="sub">Live from Zoho Books</div>
  <?php if(!empty($loginError)) echo '<div class="err">'.$loginError.'</div>'; ?>
  <div class="fld"><input type="text" name="app_user" placeholder="Username (blank = admin)"></div>
  <div class="fld"><input type="password" name="app_password" placeholder="Password" autofocus></div>
  <button type="submit">Open console →</button>
  <div class="foot">Secure access · 912 Finance</div>
</form></body></html>
<?php exit; endif; ?>
<!DOCTYPE html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>912 FINANCE CONSOLE</title>
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'%3E%3Crect width='64' height='64' rx='16' fill='%23F56F00'/%3E%3Ctext x='32' y='45' font-family='Poppins,Arial,sans-serif' font-size='29' font-weight='700' fill='white' text-anchor='middle'%3E912%3C/text%3E%3C/svg%3E">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  :root{--orange:#F56F00;--blue:#2350C5;--ink:#15202B;--mute:#64748B;--line:#E6EAF0;--bg:#F7F8FB;--good:#16A34A;--bad:#D64933}
  *{box-sizing:border-box}
  body{font-family:Poppins,system-ui,sans-serif;background:var(--bg);color:var(--ink);margin:0}
  .wrap{max-width:480px;margin:0 auto;min-height:100vh;background:var(--bg)}
  header{background:var(--ink);padding:16px 20px;display:flex;align-items:center;justify-content:space-between}
  .b{width:30px;height:30px;border-radius:8px;background:var(--orange);color:#fff;display:grid;place-items:center;font-weight:700;font-size:13px}
  .fundbar{background:#fff;padding:14px 20px;border-bottom:1px solid var(--line)}
  .meter{height:12px;border-radius:8px;background:#EEF1F6;overflow:hidden}
  .meter>div{height:100%;background:var(--orange);transition:width .4s}
  .tabs{display:flex;flex-wrap:wrap;gap:4px;background:#fff;padding:6px;border-bottom:1px solid var(--line);position:relative}
  .navgroup{position:relative;display:inline-flex}
  .navgroup .grp{display:inline-flex;align-items:center;gap:5px}
  .navgroup .car{display:inline-block;font-size:9px;transition:transform .15s ease}
  .navgroup.open .car{transform:rotate(180deg)}
  .submenu{position:absolute;top:100%;left:0;margin-top:5px;z-index:60;background:#fff;border:1px solid var(--line);border-radius:8px;padding:6px;display:none;flex-direction:column;gap:4px;min-width:172px;box-shadow:0 10px 26px rgba(21,32,43,.16)}
  .navgroup.open .submenu{display:flex}
  .submenu button{width:100%;text-align:left;justify-content:flex-start}
  .tabs button{flex:0 0 auto;border:1px solid #000;background:var(--orange);color:#fff;padding:5px 9px;font-family:inherit;font-size:11px;font-weight:600;cursor:pointer;text-transform:uppercase;letter-spacing:.3px;border-radius:6px;white-space:nowrap}
  h2{text-transform:uppercase;letter-spacing:.3px;font-size:14px;font-weight:600;margin:6px 0 12px;color:var(--ink)}
  .tabs button.active{background:var(--ink);color:#fff;border-color:#000}
  .pane{padding:16px}
  .card{background:#fff;border:1px solid var(--line);border-radius:12px;padding:14px;margin-bottom:10px}
  .grid2{display:grid;grid-template-columns:1fr 1fr;gap:10px}
  .grid3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:6px}
  label{font-size:12px;font-weight:600;display:block;margin-bottom:6px}
  input,select{width:100%;padding:10px 12px;border:1px solid var(--line);border-radius:9px;font-size:13px;font-family:inherit;margin-bottom:12px}
  .btn{background:var(--orange);color:#fff;border:none;padding:11px 16px;border-radius:10px;font-weight:600;font-size:13px;cursor:pointer;width:100%;font-family:inherit}
  .btn.sec{background:#fff;color:var(--ink);border:1px solid var(--line)}
  .lab{font-size:10px;color:var(--mute);text-transform:uppercase;letter-spacing:.3px}
  .val{font-weight:600;margin-top:1px}
  .pill{font-size:10px;font-weight:600;padding:3px 8px;border-radius:20px}
  .muted{color:var(--mute);font-size:11px}
  .row{display:flex;justify-content:space-between;align-items:center}
  /* ===== Dashboard design system ===== */
  .dsh-hero{position:relative;overflow:hidden;background:linear-gradient(135deg,#1B2A3A 0%,#15202B 55%,#0E1822 100%);color:#fff;border-radius:18px;padding:22px;margin-bottom:14px;box-shadow:0 10px 30px rgba(21,32,43,.18)}
  .dsh-hero::after{content:"";position:absolute;right:-60px;top:-60px;width:200px;height:200px;border-radius:50%;background:radial-gradient(circle at center,rgba(245,111,0,.34),transparent 68%)}
  .dsh-hero .ey{font-size:10px;letter-spacing:1.4px;text-transform:uppercase;color:#9AA7B8}
  .dsh-hero .big{font-size:34px;font-weight:800;letter-spacing:-.5px;color:#5BD68A;margin-top:6px;line-height:1}
  .dsh-hero .sub{display:flex;gap:22px;margin-top:16px;position:relative;z-index:1;flex-wrap:wrap}
  .dsh-hero .sub .l{font-size:9.5px;letter-spacing:.6px;text-transform:uppercase;color:#8B99AB}
  .dsh-hero .sub .v{font-weight:700;font-size:15px;margin-top:2px}
  .kpis{display:grid;grid-template-columns:repeat(2,1fr);gap:10px;margin-bottom:8px}
  .kpi{background:#fff;border:1px solid var(--line);border-radius:14px;padding:14px 14px 13px;position:relative;overflow:hidden}
  .kpi::before{content:"";position:absolute;left:0;top:0;bottom:0;width:4px;background:var(--accent,var(--orange));opacity:.9}
  .kpi .l{font-size:9.5px;letter-spacing:.5px;text-transform:uppercase;color:var(--mute);font-weight:600}
  .kpi .n{font-size:27px;font-weight:800;letter-spacing:-.5px;line-height:1.05;margin-top:6px;color:var(--ink)}
  .kpi .h{font-size:10.5px;color:var(--mute);margin-top:3px}
  .sect{display:flex;align-items:center;gap:10px;margin:18px 2px 9px}
  .sect b{font-size:11px;letter-spacing:.8px;text-transform:uppercase;color:var(--ink)}
  .sect .ln{flex:1;height:1px;background:var(--line)}
  .avatar{width:26px;height:26px;border-radius:50%;display:inline-grid;place-items:center;font-size:10px;font-weight:700;color:#fff;flex:0 0 auto}
  .wbar{display:flex;flex-wrap:wrap;gap:8px}
  .wchip{display:inline-flex;align-items:center;gap:7px;background:#F7F9FC;border:1px solid var(--line);border-radius:30px;padding:4px 11px 4px 4px;font-size:11.5px}
  .wchip b{font-weight:700}
  .linktile{flex:1;min-width:150px;display:flex;align-items:center;gap:11px;background:#fff;border:1px solid var(--line);border-radius:14px;padding:13px 14px;cursor:pointer;transition:transform .15s,box-shadow .2s}
  .linktile:hover{transform:translateY(-2px);box-shadow:0 8px 20px rgba(21,32,43,.1)}
  .linktile .ic{width:34px;height:34px;border-radius:10px;display:grid;place-items:center;font-size:16px;flex:0 0 auto}
  .linktile .t{font-weight:700;font-size:12.5px}
  .linktile .s{font-size:10.5px;color:var(--mute)}
  .tool details{border:1px solid var(--line);border-radius:14px;background:#fff;margin-bottom:10px;overflow:hidden}
  .tool summary{list-style:none;cursor:pointer;padding:13px 15px;font-weight:700;font-size:12.5px;display:flex;justify-content:space-between;align-items:center}
  .tool summary::-webkit-details-marker{display:none}
  .tool summary .cv{color:var(--mute);font-size:11px;font-weight:500}
  .tool .body{padding:0 15px 15px}
  .wcgrid{display:grid;grid-template-columns:1fr 1fr;gap:10px;align-items:start}
  .wcgrid>.card{margin-bottom:0}
  @media (max-width:680px){ .wcgrid{grid-template-columns:1fr} }
  .invrow{padding:10px 12px;border-bottom:1px solid var(--line);cursor:pointer;display:flex;justify-content:space-between}
  .invrow:hover{background:#FFF4EB}
  .warn{background:#FDECEA;color:var(--bad);font-size:11.5px;padding:8px 10px;border-radius:8px;margin-bottom:10px}
  .ok{background:#E7F6EC;color:var(--good);font-size:12px;padding:8px 10px;border-radius:8px}
  a.logout{color:#9AA7B8;font-size:11px;text-decoration:none}
  .cardgrid{display:block}
  /* ---- compact report table ---- */
  .rptwrap{overflow-x:auto;-webkit-overflow-scrolling:touch;border:1px solid var(--line);border-radius:10px;background:#fff}
  table.rpt{width:100%;border-collapse:collapse;font-size:10px}
  table.rpt th,table.rpt td{padding:4px 7px;border-bottom:1px solid var(--line);white-space:nowrap;text-align:right}
  table.rpt tbody tr:not(.tot):nth-child(odd){background:#FFFFFF}
  table.rpt tbody tr:not(.tot):nth-child(even){background:#F1F5FB}
  table.rpt tbody tr:not(.tot):hover{background:#FFE7CC;box-shadow:inset 3px 0 0 var(--orange)}
  table.rpt th{font-size:8.5px;text-transform:uppercase;letter-spacing:.2px;color:var(--mute);background:#F1F4F8;position:sticky;top:0}
  table.rpt th.l,table.rpt td.l{text-align:left}
  table.rpt td.vat{color:var(--blue)}
  table.rpt td.cl{font-weight:700;text-transform:uppercase}
  table.rpt tr.tot td{font-weight:700;border-top:2px solid var(--ink);background:#F7F8FB;font-size:10.5px}
  table.rpt td.pos{color:var(--good)} table.rpt td.neg{color:var(--bad)}
  /* ---- Responsive: phone stays as-is; tablet/PC widens into a framed app ---- */
  @media (min-width:820px){
    body{padding:28px 0}
    .wrap{max-width:1100px;min-height:auto;border-radius:18px;overflow:hidden;
          box-shadow:0 12px 44px rgba(20,32,43,.14);border:1px solid var(--line)}
    .pane{padding:22px 26px}
    .cardgrid{display:grid;grid-template-columns:1fr 1fr;gap:12px;align-items:start}
    .cardgrid > .card{margin-bottom:0}
    .tabs button{font-size:11px;padding:6px 11px}
  }
  @media (min-width:1180px){
    .wrap{max-width:1320px}
  }
  @media (min-width:1500px){
    .wrap{max-width:1480px}
  }

  /* ===================== Motion & micro-interactions ===================== */
  /* Section entrance: direct children of the pane cascade in on each render */
  .pane > *{animation:rise .42s cubic-bezier(.22,.61,.36,1) both}
  .pane > *:nth-child(1){animation-delay:.00s}
  .pane > *:nth-child(2){animation-delay:.05s}
  .pane > *:nth-child(3){animation-delay:.10s}
  .pane > *:nth-child(4){animation-delay:.15s}
  .pane > *:nth-child(5){animation-delay:.18s}
  .pane > *:nth-child(6){animation-delay:.21s}
  .pane > *:nth-child(n+7){animation-delay:.24s}
  @keyframes rise{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:none}}

  /* Buttons: ripple, hover lift, press */
  .btn,.tabs button{position:relative;overflow:hidden;transition:transform .12s ease,box-shadow .2s ease,filter .15s ease}
  .btn:hover{filter:brightness(1.05);box-shadow:0 6px 18px rgba(245,111,0,.30)}
  .btn:active{transform:scale(.97)}
  .btn.sec:hover{filter:none;background:#fff;box-shadow:0 4px 14px rgba(21,32,43,.10)}
  .tabs button:hover{filter:brightness(1.07);transform:translateY(-1px)}
  .tabs button:active{transform:scale(.94)}
  .tabs button.active{transform:none}
  .ripple{position:absolute;border-radius:50%;background:rgba(255,255,255,.5);transform:scale(0);animation:ripple .6s ease-out;pointer-events:none}
  .btn.sec .ripple{background:rgba(21,32,43,.14)}
  @keyframes ripple{to{transform:scale(2.6);opacity:0}}

  /* Cards & rows: gentle hover depth */
  .card{transition:box-shadow .22s ease,transform .22s ease}
  .cardgrid > .card{cursor:default}
  .cardgrid > .card:hover{transform:translateY(-3px);box-shadow:0 12px 28px rgba(21,32,43,.12)}
  .invrow{transition:background .15s ease,padding-left .15s ease}
  .invrow:hover{padding-left:16px}

  /* Inputs: focus ring in brand orange */
  input,select,textarea{transition:border-color .15s ease,box-shadow .15s ease}
  input:focus,select:focus,textarea:focus{outline:none;border-color:var(--orange);box-shadow:0 0 0 3px rgba(245,111,0,.16)}

  /* Meter fill shimmer + smooth */
  .meter>div{transition:width .6s cubic-bezier(.22,.61,.36,1)}

  /* Brand mark subtle entrance */
  .b{transition:transform .2s ease}
  .b:hover{transform:rotate(-4deg) scale(1.05)}

  /* Respect users who prefer less motion */
  @media (prefers-reduced-motion: reduce){
    *,*::before,*::after{animation:none!important;transition:none!important}
  }

  /* ============================================================
     ✨ UI REFRESH LAYER — depth, polish & craft.
     Same palette (orange/blue/ink + existing tints), elevated.
     ============================================================ */
  :root{
    --ink-2:#1E2D3D; --ink-3:#27384A;
    --orange-50:#FFF4EB; --blue-50:#EEF2FE;
    --sh-sm:0 1px 2px rgba(21,32,43,.06),0 1px 3px rgba(21,32,43,.05);
    --sh-md:0 4px 10px rgba(21,32,43,.07),0 2px 4px rgba(21,32,43,.05);
    --sh-lg:0 14px 34px rgba(21,32,43,.12);
    --grad-orange:linear-gradient(140deg,#FF8A1E 0%,#F56F00 70%);
  }
  html{scroll-behavior:smooth}
  body{-webkit-font-smoothing:antialiased;text-rendering:optimizeLegibility;
       background:
         radial-gradient(900px 500px at 100% -5%,rgba(35,80,197,.05),transparent 60%),
         radial-gradient(800px 500px at -5% 8%,rgba(245,111,0,.045),transparent 55%),
         var(--bg)}
  ::selection{background:rgba(245,111,0,.22)}
  /* tidy, unobtrusive scrollbars */
  *{scrollbar-width:thin;scrollbar-color:#C8D2DF transparent}
  *::-webkit-scrollbar{width:10px;height:10px}
  *::-webkit-scrollbar-thumb{background:#CBD5E1;border:3px solid var(--bg);border-radius:20px}
  *::-webkit-scrollbar-thumb:hover{background:#9FB0C3}

  /* ---- Framed app shell ---- */
  @media (min-width:820px){
    .wrap{box-shadow:0 30px 80px rgba(14,24,34,.20);border-color:rgba(21,32,43,.06)}
  }

  /* ---- Header: layered dark glass with a warm hairline ---- */
  header{background:
      radial-gradient(600px 200px at 0% -40%,rgba(245,111,0,.16),transparent 60%),
      linear-gradient(100deg,var(--ink-3) 0%,var(--ink) 60%,#0E1822 100%);
    box-shadow:inset 0 -1px 0 rgba(255,255,255,.05),0 1px 0 rgba(245,111,0,.5);
    position:relative}
  header::after{content:"";position:absolute;left:0;right:0;bottom:0;height:2px;
    background:linear-gradient(90deg,var(--orange),rgba(245,111,0,0) 60%)}
  header .b{background:var(--grad-orange);box-shadow:0 6px 16px rgba(245,111,0,.40),0 0 0 4px rgba(245,111,0,.10)}
  /* live status pill with pulsing dot */
  .livepill{display:inline-flex;align-items:center;gap:6px;margin-top:3px;color:#CBD6E3;font-size:10px;font-weight:600;
    letter-spacing:.5px;background:rgba(91,214,138,.10);border:1px solid rgba(91,214,138,.28);
    padding:2px 9px 2px 7px;border-radius:30px}
  .livedot{width:7px;height:7px;border-radius:50%;background:#5BD68A;box-shadow:0 0 0 0 rgba(91,214,138,.6);animation:pulse 2s infinite}
  @keyframes pulse{0%{box-shadow:0 0 0 0 rgba(91,214,138,.55)}70%{box-shadow:0 0 0 7px rgba(91,214,138,0)}100%{box-shadow:0 0 0 0 rgba(91,214,138,0)}}
  a.logout[href*="connect_calendar"]{background:var(--grad-orange)!important;box-shadow:0 6px 16px rgba(245,111,0,.32);transition:transform .12s,box-shadow .2s,filter .15s}
  a.logout[href*="connect_calendar"]:hover{filter:brightness(1.05);box-shadow:0 9px 20px rgba(245,111,0,.42)}
  a.logout[href="?logout=1"]{padding:5px 10px;border-radius:8px;transition:background .15s,color .15s}
  a.logout[href="?logout=1"]:hover{background:rgba(255,255,255,.08);color:#fff}

  /* ---- Fund meter: premium capital bar ---- */
  .fundbar{background:linear-gradient(180deg,#fff,#FCFDFE);box-shadow:var(--sh-sm)}
  .meter{height:14px;background:#EAEEF4;box-shadow:inset 0 1px 3px rgba(21,32,43,.12);padding:0}
  .meter>div{background:var(--grad-orange);border-radius:8px;position:relative;overflow:hidden;
    box-shadow:0 1px 4px rgba(245,111,0,.45)}
  .meter>div::after{content:"";position:absolute;inset:0;border-radius:8px;
    background:linear-gradient(90deg,transparent,rgba(255,255,255,.45),transparent);
    transform:translateX(-100%);animation:shimmer 2.4s ease-in-out infinite}
  @keyframes shimmer{0%{transform:translateX(-100%)}60%,100%{transform:translateX(220%)}}
  #pctOut{font-weight:700;color:var(--ink)}

  /* ---- Navigation: modern segmented pills + refined dropdowns ---- */
  .tabs{gap:6px;padding:9px 10px;background:rgba(255,255,255,.86);backdrop-filter:saturate(140%) blur(8px);
    -webkit-backdrop-filter:saturate(140%) blur(8px);position:sticky;top:0;z-index:50;box-shadow:var(--sh-sm)}
  .tabs button{border:1px solid transparent;background:#F1F4F8;color:var(--mute);text-transform:none;letter-spacing:.2px;
    font-size:11.5px;font-weight:600;padding:7px 13px;border-radius:9px;box-shadow:none}
  .tabs button:hover{background:#E9EEF5;color:var(--ink);filter:none;transform:translateY(-1px);box-shadow:var(--sh-sm)}
  .tabs > button.active,.tabs button.active{background:var(--ink);color:#fff;border-color:transparent;
    box-shadow:0 6px 16px rgba(21,32,43,.26)}
  .navgroup .grp{background:#F1F4F8;color:var(--ink)}
  .navgroup.open .grp{background:var(--ink);color:#fff;box-shadow:0 6px 16px rgba(21,32,43,.22)}
  .navgroup .car{opacity:.7}
  .submenu{border-radius:12px;padding:7px;box-shadow:0 16px 40px rgba(21,32,43,.20);border-color:rgba(21,32,43,.07);
    animation:menuIn .16s cubic-bezier(.22,.61,.36,1) both}
  @keyframes menuIn{from{opacity:0;transform:translateY(-6px) scale(.98)}to{opacity:1;transform:none}}
  .submenu button{background:#fff;color:var(--ink);border:1px solid transparent;padding:8px 11px;border-radius:8px}
  .submenu button:hover{background:var(--orange-50);color:var(--orange);transform:none;box-shadow:none}

  /* ---- Section headings ---- */
  .sect b{position:relative}
  h2{position:relative;padding-left:11px}
  h2::before{content:"";position:absolute;left:0;top:1px;bottom:1px;width:3px;border-radius:3px;background:var(--grad-orange)}

  /* ---- Cards: softer depth, hairline borders ---- */
  .card{border-radius:14px;border-color:rgba(21,32,43,.07);box-shadow:var(--sh-sm)}
  .pane{padding:18px}
  @media (min-width:820px){.pane{padding:24px 28px}}

  /* ---- KPI tiles: glossier, deeper accent rail ---- */
  .kpi{border-radius:16px;box-shadow:var(--sh-md);transition:transform .2s ease,box-shadow .2s ease}
  .kpi:hover{transform:translateY(-3px);box-shadow:var(--sh-lg)}
  .kpi::before{width:5px;border-radius:0 4px 4px 0}
  .kpi::after{content:"";position:absolute;right:-30px;top:-30px;width:90px;height:90px;border-radius:50%;
    background:radial-gradient(circle,var(--accent,var(--orange)),transparent 70%);opacity:.06}

  /* ---- Hero: richer shadow ---- */
  .dsh-hero{box-shadow:0 18px 44px rgba(14,24,34,.26)}

  /* ---- Buttons: gradient primary, refined secondary ---- */
  .btn{background:var(--grad-orange);border-radius:11px;box-shadow:0 6px 16px rgba(245,111,0,.26)}
  .btn:hover{box-shadow:0 10px 22px rgba(245,111,0,.34)}
  .btn.sec{background:#fff;border-color:rgba(21,32,43,.10);box-shadow:var(--sh-sm)}
  .btn.sec:hover{background:#FAFBFD;box-shadow:var(--sh-md)}
  .btn:disabled{opacity:.55;cursor:default;box-shadow:none;filter:none}

  /* ---- Inputs: comfier, brand focus ---- */
  input,select,textarea{border-radius:11px;background:#FBFCFE}
  input:hover,select:hover,textarea:hover{border-color:#CBD5E1}
  input:focus,select:focus,textarea:focus{background:#fff;box-shadow:0 0 0 3px rgba(245,111,0,.16)}

  /* ---- Chips / pills ---- */
  .wchip{border-radius:30px;box-shadow:var(--sh-sm);transition:transform .15s,box-shadow .15s}
  .wchip:hover{transform:translateY(-1px);box-shadow:var(--sh-md)}
  .pill{box-shadow:var(--sh-sm)}

  /* ---- Link tiles ---- */
  .linktile{border-radius:16px;box-shadow:var(--sh-sm)}
  .linktile:hover{box-shadow:var(--sh-lg)}
  .linktile .ic{box-shadow:var(--sh-sm)}

  /* ---- Tool accordions ---- */
  .tool details{border-radius:16px;box-shadow:var(--sh-sm);transition:box-shadow .2s}
  .tool details[open]{box-shadow:var(--sh-md)}
  .tool summary{transition:background .15s}
  .tool summary:hover{background:#FAFBFD}

  /* ---- Report table: crisper header & rows ---- */
  .rptwrap{border-radius:12px;box-shadow:var(--sh-sm)}
  table.rpt th{background:linear-gradient(180deg,#F4F7FB,#EDF1F7);border-bottom:1px solid var(--line)}
  table.rpt tr.tot td{background:#F4F7FB}

  /* ---- Invoice / list rows ---- */
  .invrow{transition:background .15s,padding-left .15s,box-shadow .15s}

  /* ---- Segmented control ---- */
  .seg{box-shadow:var(--sh-sm)}

  /* ---- Pane entrance: a touch smoother ---- */
  @keyframes rise{from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:none}}

  /* ---- Quote builder: Zoho-style item table + totals ---- */
  .qbhead{display:none}
  .qbrow{display:grid;grid-template-columns:1fr 1fr;gap:8px 10px;align-items:end;padding:12px;
    border:1px solid var(--line);border-radius:12px;margin-bottom:8px;position:relative;background:#fff}
  .qbrow .qbc-item{grid-column:1 / -1}
  .qbrow .qbc-amt{grid-column:1 / -1;text-align:right}
  .qbc-lab{display:block;font-size:9.5px;text-transform:uppercase;letter-spacing:.3px;color:var(--mute);margin-bottom:3px;font-weight:600}
  .qbc-del{position:absolute;top:8px;right:8px;width:auto;padding:4px 9px;font-size:12px}
  .qbsplit{display:grid;grid-template-columns:1fr;gap:10px;align-items:start}
  .qbsplit>.card{margin-bottom:0}
  @media (min-width:820px){
    .qbhead{display:grid;grid-template-columns:1fr 80px 100px 122px 110px 34px;gap:12px;
      padding:8px 12px;background:linear-gradient(180deg,#F4F7FB,#EDF1F7);border:1px solid var(--line);
      border-radius:10px;margin-bottom:8px;font-size:9px;text-transform:uppercase;letter-spacing:.4px;color:var(--mute);font-weight:700}
    .qbhead .qbc-qty,.qbhead .qbc-rate,.qbhead .qbc-amt{text-align:right}
    .qbrow{grid-template-columns:1fr 80px 100px 122px 110px 34px;gap:12px;align-items:center;
      border:none;border-bottom:1px solid var(--line);border-radius:0;margin-bottom:0;padding:10px 12px;background:transparent}
    .qbrow:last-of-type{border-bottom:none}
    .qbrow .qbc-item,.qbrow .qbc-amt{grid-column:auto}
    .qbrow .qbc-amt{text-align:right}
    .qbc-lab{display:none}
    .qbc-del{position:static;justify-self:center}
    .qbsplit{grid-template-columns:1.3fr 1fr;gap:12px}
  }
</style></head>
<body>
<div class="wrap">
  <header>
    <div style="display:flex;align-items:center;gap:10px">
      <div class="b">912</div>
      <div><div style="color:#fff;font-weight:600;font-size:15px;letter-spacing:.2px">912 FINANCE CONSOLE</div>
      <div class="livepill"><span class="livedot"></span>LIVE FROM ZOHO BOOKS</div></div>
    </div>
    <div style="display:flex;align-items:center;gap:12px">
      <div style="display:flex;align-items:center;gap:7px;background:rgba(255,255,255,.10);border:1px solid rgba(255,255,255,.18);padding:4px 10px 4px 5px;border-radius:20px">
        <span id="meAvatar" style="width:24px;height:24px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:10px;font-weight:800;color:#fff;background:#F56F00"><?php echo strtoupper(substr(preg_replace('/[^A-Za-z0-9]/','',$_SESSION['user'] ?? 'U'),0,2) ?: 'U'); ?></span>
        <span style="display:flex;flex-direction:column;line-height:1.15">
          <span style="color:#fff;font-size:12px;font-weight:600"><?php echo htmlspecialchars($_SESSION['user'] ?? 'user', ENT_QUOTES); ?></span>
          <span style="color:#9AA7B8;font-size:9.5px;max-width:190px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?php echo (!empty($_SESSION['is_admin']) ? 'Admin' : 'Staff') . (!empty($_SESSION['email']) ? ' · ' . htmlspecialchars($_SESSION['email'], ENT_QUOTES) : ''); ?></span>
        </span>
      </div>
      <a class="logout" href="connect_calendar.php" target="_blank" rel="noopener" style="color:#fff;background:rgba(245,111,0,.9);border:1px solid rgba(255,255,255,.18);padding:5px 11px;border-radius:8px;font-weight:600">📅 Authenticate calendar</a>
      <a class="logout" href="?logout=1">Sign out</a>
    </div>
  </header>

  <div class="fundbar">
    <div class="row" style="margin-bottom:8px"><span class="muted">Fund deployed</span><span class="muted" id="pctOut">0%</span></div>
    <div class="meter"><div id="meterFill" style="width:0%"></div></div>
    <div class="row" style="margin-top:10px">
      <div><div class="lab">Out now</div><div style="font-weight:700;color:var(--orange)" id="exposure">—</div></div>
      <div style="text-align:right"><div class="lab">Available</div><div style="font-weight:700;color:var(--blue)" id="available">—</div></div>
    </div>
  </div>

  <div class="tabs">
    <button class="active" data-tab="dash">Dashboard</button>

    <div class="navgroup">
      <button class="grp">Working Capital <span class="car">▾</span></button>
      <div class="submenu">
        <button data-tab="deploy">Deploy</button>
        <button data-tab="ledger">Ledger</button>
        <button data-tab="loans">Loans</button>
        <button data-tab="growth">Growth</button>
      </div>
    </div>

    <div class="navgroup">
      <button class="grp">Accounts Efficiency <span class="car">▾</span></button>
      <div class="submenu">
        <button data-tab="report">Profit Report</button>
        <button data-tab="etr">ETR Check</button>
        <button data-tab="invrep">Invoices</button>
        <button data-tab="quotes">Quotes</button>
      </div>
    </div>

    <div class="navgroup">
      <button class="grp">Create <span class="car">▾</span></button>
      <div class="submenu">
        <button data-tab="newquote">New Quote</button>
        <button data-tab="myquotes">My Quotes</button>
        <button data-tab="jobcards">Job Cards</button>
      </div>
    </div>

    <div class="navgroup">
      <button class="grp">Tasks <span class="car">▾</span></button>
      <div class="submenu">
        <button data-tab="todo">To-Do</button>
        <button data-ext="tasks_board.php">Task Board</button>
      </div>
    </div>

    <div class="navgroup">
      <button class="grp">Clients <span class="car">▾</span></button>
      <div class="submenu">
        <button data-tab="emails">Emails</button>
        <button data-ext="audrey.php">Audrey Reports</button>
      </div>
    </div>

    <button data-tab="settings">Settings</button>
  </div>

  <div class="pane" id="pane"></div>
</div>

<script>
/* ---- Global progress bar: shows whenever the app is fetching from Zoho/server ---- */
(function(){
  function mountBar(){
    if(document.getElementById('gprog')) return;
    const bar=document.createElement('div');
    bar.id='gprog';
    bar.style.cssText='position:fixed;top:0;left:0;height:3px;width:0;background:#F56F00;z-index:99999;opacity:0;transition:width .2s ease,opacity .3s ease;box-shadow:0 0 8px #F56F00,0 0 4px #F56F00';
    document.body.appendChild(bar);
    return bar;
  }
  let active=0, prog=0, timer=null;
  function bar(){ return document.getElementById('gprog') || mountBar(); }
  function start(){
    active++;
    if(active===1){
      const b=bar(); if(!b) return;
      prog=10; b.style.opacity='1'; b.style.width=prog+'%';
      clearInterval(timer);
      timer=setInterval(()=>{ prog=Math.min(prog+Math.max(0.4,(92-prog)*0.07),92); const e=bar(); if(e) e.style.width=prog+'%'; },200);
    }
  }
  function done(){
    active=Math.max(0,active-1);
    if(active===0){
      clearInterval(timer);
      const b=bar(); if(!b) return;
      b.style.width='100%';
      setTimeout(()=>{ b.style.opacity='0'; setTimeout(()=>{ b.style.width='0'; },300); },250);
    }
  }
  if(document.body) mountBar(); else document.addEventListener('DOMContentLoaded',mountBar);
  const _fetch=window.fetch;
  window.fetch=function(){ start(); return _fetch.apply(this,arguments).then(r=>{done();return r;},e=>{done();throw e;}); };
})();

const CFG = <?php echo json_encode([
  'fund'=>$cfg['fund'],'rate'=>$cfg['annual_rate'],'cur'=>$cfg['currency'],'vat'=>$cfg['vat_rate'] ?? 0.16,
  'usd'=>$cfg['usd_rate'] ?? 128,'growth'=>$cfg['growth_multiple'] ?? 2,
  'inboxDays'=>$cfg['inbox_days'] ?? 14,'sentDays'=>$cfg['sent_hide_days'] ?? 30,
  'biz'=>$cfg['business_name'] ?? 'Nine One Two Holdings',
  'stmtSubject'=>$cfg['statement_subject'] ?? 'Pending invoices and Statement',
  'stmtFooter'=>$cfg['statement_footer'] ?? ''
]); ?>;
const ME = <?php echo json_encode([
  'user'  => $_SESSION['user'] ?? 'admin',
  'email' => $_SESSION['email'] ?? '',
  'admin' => !empty($_SESSION['is_admin']),
  'tabs'  => (($_SESSION['tabs'] ?? '*') === '*') ? '*' : array_values(array_filter(array_map('trim', explode(',', (string)($_SESSION['tabs'] ?? '')))))
]); ?>;
const ALLTABS = <?php echo json_encode(users_all_tabs()); ?>;
const fmt = n => CFG.cur + ' ' + Math.round(n||0).toLocaleString('en-KE');
const fmtn = n => Math.round(n||0).toLocaleString('en-KE');
const fmt1 = n => (n||0).toLocaleString('en-KE',{maximumFractionDigits:1});
const today = () => new Date().toISOString().slice(0,10);
const days = (a,b) => Math.max(0, Math.round((new Date(b)-new Date(a))/86400000));
const dailyRate = CFG.rate/365;

let DEPLOYMENTS = [], INVOICES = [], INV_LOADED = false, EXPENSES = [], EXP_LOADED = false, TAB = 'dash';
let LOANS = [], LOANTOT = {lent:0, outstanding:0, repaid:0}, FUNDS = [], PRIMARY_ID = 0;
let LOANFORM = { type:'person', name:'', amount:'', date:'', expected:'', note:'', fundId:0, msg:'', err:false };
let FUNDFORM = { name:'', balance:'', msg:'', err:false };
const AUDREY_URL = location.href.split('#')[0].split('?')[0].replace(/[^/]*$/,'') + 'audrey.php';
const TASKBOARD_URL = location.href.split('#')[0].split('?')[0].replace(/[^/]*$/,'') + 'tasks_board.php';
function copyBoard(btn){ try{ navigator.clipboard.writeText(TASKBOARD_URL); const t=btn.textContent; btn.textContent='Copied ✓'; setTimeout(()=>btn.textContent=t,1500);}catch(e){} }
let BK = { folder:'', running:false, msg:'', msgErr:false, loaded:false,
           pick:{ open:false, loading:false, current:null, folders:[], roots:[], err:'' } };
function bkBrowse(){
  BK.pick.open=true; BK.pick.loading=true; BK.pick.current=null; BK.pick.err=''; render();
  fetch('api/wd_folders.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'roots'})})
  .then(r=>r.json()).then(j=>{ BK.pick.loading=false; if(j.ok){ BK.pick.roots=j.roots||[]; } else { BK.pick.err=j.error||'Could not list folders.'; } render(); })
  .catch(e=>{ BK.pick.loading=false; BK.pick.err=''+e; render(); });
}
function bkOpenFolder(id){
  BK.pick.loading=true; BK.pick.err=''; render();
  fetch('api/wd_folders.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'list',folder_id:id})})
  .then(r=>r.json()).then(j=>{ BK.pick.loading=false; if(j.ok){ BK.pick.current=j.current; BK.pick.folders=j.folders||[]; } else { BK.pick.err=j.error||'Could not open folder.'; } render(); })
  .catch(e=>{ BK.pick.loading=false; BK.pick.err=''+e; render(); });
}
function bkUp(){
  const p=BK.pick.current&&BK.pick.current.parent_id;
  if(p){ bkOpenFolder(p); } else { bkBrowse(); }
}
function bkUseFolder(){
  if(!BK.pick.current) return;
  const nm=BK.pick.current.name||'';
  BK.folder=BK.pick.current.id; BK.pick.open=false;
  fetch('api/backup.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'set_folder',folder_id:BK.folder})})
  .then(r=>r.json()).then(j=>{ BK.folder=j.folder_id||BK.folder; BK.msg='Backup folder set to “'+nm+'”.'; BK.msgErr=false; render(); })
  .catch(()=>{ render(); });
}
function bkCloseBrowse(){ BK.pick.open=false; render(); }
function loadBackupStatus(){
  if(BK.loaded) return; BK.loaded=true;
  fetch('api/backup.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},
    body:JSON.stringify({action:'status'})}).then(r=>r.json()).then(j=>{ if(j.ok){ BK.folder=j.folder_id||''; if(TAB==='dash') render(); } }).catch(()=>{});
}
function saveBackupFolder(){
  const fid=(document.getElementById('bkFolder')?.value||BK.folder||'').trim();
  fetch('api/backup.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},
    body:JSON.stringify({action:'set_folder',folder_id:fid})}).then(r=>r.json()).then(j=>{
    BK.folder=j.folder_id||''; BK.msg=j.ok?'Backup folder saved.':'Could not save folder.'; BK.msgErr=!j.ok; render();
  }).catch(e=>{ BK.msg='Error: '+e; BK.msgErr=true; render(); });
}
function runBackup(){
  const fid=(document.getElementById('bkFolder')?.value||BK.folder||'').trim();
  BK.folder=fid; BK.running=true; BK.msg=''; render();
  fetch('api/backup.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},
    body:JSON.stringify({action:'run',folder_id:fid})}).then(r=>r.json()).then(j=>{
    BK.running=false;
    if(j.ok && j.uploaded){ BK.msg='✓ Backed up to WorkDrive as '+j.name+' ('+Math.round((j.size||0)/1024)+' KB).'; BK.msgErr=false; }
    else if(j.need_folder){ BK.msg='Set a WorkDrive folder ID first (paste it above and Save).'; BK.msgErr=true; }
    else { BK.msg='WorkDrive upload failed: '+(j.error||'unknown')+(j.hint?(' — '+j.hint):'')+' You can still use “Download backup” below.'; BK.msgErr=true; }
    render();
  }).catch(e=>{ BK.running=false; BK.msg='Error: '+e; BK.msgErr=true; render(); });
}
function copyAudrey(btn){ try{ navigator.clipboard.writeText(AUDREY_URL); const t=btn.textContent; btn.textContent='Copied ✓'; setTimeout(()=>btn.textContent=t,1500); }catch(e){} }
window._form = window._form || {amount:'',purpose:'',cost:''};
window._invQuery = window._invQuery || '';
window._expQuery = window._expQuery || '';

function enrich(d){
  const end = d.status==='Restored' && d.restored_date ? d.restored_date : today();
  const dd = days(d.deployed_date, end);
  const financing = d.amount * dailyRate * dd;
  const costLogged = d.cost !== null && d.cost !== undefined && d.cost !== '';
  const ivTotal = Number(d.invoice_value)||0;
  const hasReal = d.invoice_subtotal !== null && d.invoice_subtotal !== undefined && d.invoice_subtotal !== '';
  let revenueExVat, vat;
  if(hasReal){                                   // actual figures pulled from Zoho
    revenueExVat = Number(d.invoice_subtotal);
    vat = (d.invoice_tax!==null && d.invoice_tax!==undefined && d.invoice_tax!=='') ? Number(d.invoice_tax) : (ivTotal - revenueExVat);
  } else {                                       // fallback for older records: assume configured VAT rate
    vat = ivTotal * CFG.vat/(1+CFG.vat);
    revenueExVat = ivTotal - vat;
  }
  const grossMargin = costLogged ? revenueExVat - Number(d.cost) : null;
  const netProfit = costLogged ? grossMargin - financing : null;   // excludes VAT
  const overdue = d.status!=='Restored' && d.expected_date && today() > d.expected_date;
  return {...d, dd, financing, costLogged, vat, revenueExVat, grossMargin, netProfit, overdue};
}
function rows(){ return DEPLOYMENTS.map(enrich); }
function summary(){
  const r = rows(), open = r.filter(x=>x.status!=='Restored'), restored = r.filter(x=>x.status==='Restored');
  const exposure = open.reduce((s,x)=>s+Number(x.amount),0);
  const loanOut = (LOANTOT&&LOANTOT.outstanding)?Number(LOANTOT.outstanding):0;          // all funds
  const prim = (FUNDS||[]).find(f=>f.is_primary);
  const primOut = prim?Number(prim.outstanding||0):0;                                      // working-capital fund only
  const committed = exposure + primOut;
  return {exposure, loanOut, primOut, committed, available: CFG.fund-committed, open, restored,
    netProfit: restored.reduce((s,x)=>s+(x.costLogged?x.netProfit:0),0),
    totalVat: restored.reduce((s,x)=>s+(x.vat||0),0)};
}
function paintFund(){
  const s = summary(), pct = Math.min(100, CFG.fund? s.committed/CFG.fund*100:0);
  document.getElementById('meterFill').style.width = pct+'%';
  document.getElementById('pctOut').textContent = fmt1(pct)+'% out';
  document.getElementById('exposure').textContent = fmt(s.committed);
  document.getElementById('available').textContent = fmt(s.available);
}

async function api(url, opts){ const r = await fetch(url, opts); return r.json(); }
async function loadDeployments(){ const j = await api('api/deployments.php'); DEPLOYMENTS = j.deployments||[]; paintFund(); render(); }
async function loadLoans(){ const j = await api('api/loans.php'); LOANS = j.loans||[]; LOANTOT = j.totals||{lent:0,outstanding:0,repaid:0}; FUNDS = j.funds||[]; PRIMARY_ID = j.primary_id||0; if(!LOANFORM.fundId) LOANFORM.fundId = PRIMARY_ID; paintFund(); render(); }
async function loadInvoices(){
  const btn = document.getElementById('loadInv'); if(btn){btn.textContent='Loading from Zoho…'; btn.disabled=true;}
  const j = await api('api/invoices.php');
  if(j.ok){ INVOICES = j.invoices; INV_LOADED = true; } else { alert('Zoho error: '+j.error); }
  render();
}

/* ---------- views ---------- */
function tabAllowed(t){ if(t==='users') return !!ME.admin; if(ME.admin || ME.tabs==='*') return true; return (ME.tabs||[]).includes(t); }
function firstAllowedTab(){ if(ME.admin||ME.tabs==='*') return 'dash'; const order=Object.keys(ALLTABS).filter(k=>k!=='audrey'&&k!=='taskboard'); return order.find(t=>tabAllowed(t)) || 'dash'; }
function applyPerms(){
  const fb=document.querySelector('.fundbar'); if(fb) fb.style.display = ME.admin? '' : 'none';
  document.querySelectorAll('.tabs button[data-tab]').forEach(b=>{ b.style.display = tabAllowed(b.dataset.tab)?'':'none'; });
  document.querySelectorAll('.tabs button[data-ext]').forEach(b=>{ const k=b.dataset.ext==='audrey.php'?'audrey':(b.dataset.ext==='tasks_board.php'?'taskboard':''); b.style.display = tabAllowed(k)?'':'none'; });
  document.querySelectorAll('.tabs .navgroup').forEach(g=>{ const any=[...g.querySelectorAll('.submenu button')].some(x=>x.style.display!=='none'); g.style.display = any?'':'none'; });
}

function render(){
  const p = document.getElementById('pane');
  if(!tabAllowed(TAB)) TAB = firstAllowedTab();
  if(TAB==='dash') p.innerHTML = vDash();
  if(TAB==='deploy') p.innerHTML = vDeploy();
  if(TAB==='ledger') p.innerHTML = vLedger();
  if(TAB==='loans') p.innerHTML = vLoans();
  if(TAB==='growth'){ p.innerHTML = vGrowth(); drawChart(); }
  if(TAB==='report') p.innerHTML = vReport();
  if(TAB==='etr') p.innerHTML = vETR();
  if(TAB==='invrep') p.innerHTML = vInvRep();
  if(TAB==='quotes') p.innerHTML = vQuotes();
  if(TAB==='settings') p.innerHTML = vSettings();
  if(TAB==='emails') p.innerHTML = vEmail();
  if(TAB==='todo') p.innerHTML = vTodo();
  if(TAB==='newquote') p.innerHTML = vNewQuote();
  if(TAB==='myquotes') p.innerHTML = vMyQuotes();
  if(TAB==='jobcards') p.innerHTML = vJobCards();
  paintFund();
}

function loadDashTasks(){
  fetch('api/tasks.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'list'})})
  .then(r=>r.json()).then(j=>{ if(j.ok){ TASK.tasks=j.tasks||[]; if(TAB==='dash') render(); } }).catch(()=>{});
}
function dshInitials(name){ const p=String(name||'?').trim().split(/\s+/); return ((p[0]||'?')[0]+(p[1]?p[1][0]:'')).toUpperCase(); }
function dshColor(name){ const pal=['#F56F00','#2350C5','#16A34A','#7C3AED','#DB2777','#0891B2','#CA8A04','#DC2626']; let h=0; const s=String(name||''); for(let i=0;i<s.length;i++)h=(h*31+s.charCodeAt(i))>>>0; return pal[h%pal.length]; }
function dshAvatar(name,sz){ const z=sz||26; return `<span class="avatar" style="background:${dshColor(name)};width:${z}px;height:${z}px;font-size:${Math.round(z*0.38)}px">${dshInitials(name)}</span>`; }
function gotoTodo(){ TAB='todo'; document.querySelectorAll('.tabs button').forEach(x=>x.classList.remove('active')); render(); if(!TASK.loaded) todoLoad(); }
function gotoLoans(){ TAB='loans'; document.querySelectorAll('.tabs button').forEach(x=>x.classList.remove('active')); render(); loadLoans(); }

function dashWorkingCapitalHtml(){
  const s = summary();
  const pct = CFG.fund? Math.min(100, s.committed/CFG.fund*100) : 0;
  const r = rows(), overdue = r.filter(x=>x.overdue);
  const stat = (l,v,c)=>`<div><div class="lab">${l}</div><div style="font-weight:700;font-size:15px${c?';color:'+c:''}">${v}</div></div>`;
  return `<div class="card" style="margin-bottom:10px">
    <div class="row" style="margin-bottom:10px"><b style="font-size:13px">💼 Working-capital fund</b>
      <span class="muted" style="font-size:11px">${fmt1(pct)}% deployed</span></div>
    <div class="meter" style="margin-bottom:12px"><div style="width:${pct}%;height:100%;background:linear-gradient(90deg,var(--orange),#FF9D45);border-radius:inherit"></div></div>
    <div class="grid2" style="grid-template-columns:repeat(2,1fr);gap:10px">
      ${stat('Fund', fmt(CFG.fund))}
      ${stat('Available', fmt(s.available), s.available>=0?'var(--good)':'var(--bad)')}
      ${stat('Out now (committed)', fmt(s.committed), 'var(--orange)')}
      ${stat('Open bridges', s.open.length)}
    </div>
    <div class="muted" style="font-size:11px;margin-top:8px">Committed = bridges ${fmt(s.exposure)} + loans from this fund ${fmt(s.primOut)}.${overdue.length?` <b style="color:var(--bad)">${overdue.length} overdue.</b>`:''}</div>
  </div>`;
}

function dashLoansHtml(){
  const s = summary();
  const openLoans = (LOANS||[]).filter(l=>l.status!=='Repaid').length;
  const fundRows = (FUNDS||[]).map(f=>{
    const bridge = f.is_primary ? s.exposure : 0;
    const avail = Number(f.balance||0) - Number(f.outstanding||0) - bridge;
    return `<div class="row" style="padding:6px 0;border-top:1px solid var(--line);font-size:12px">
      <span>${f.is_primary?'🔵 ':'🟠 '}${(f.name||'').replace(/</g,'&lt;')}</span>
      <span style="color:var(--mute)">out ${fmt(Number(f.outstanding||0))} · avail <b style="color:${avail>=0?'var(--good)':'var(--bad)'}">${fmt(avail)}</b></span>
    </div>`;
  }).join('');
  const stat = (l,v,c)=>`<div><div class="lab">${l}</div><div style="font-weight:700;font-size:15px${c?';color:'+c:''}">${v}</div></div>`;
  return `<div class="card linktile" style="margin-bottom:10px;display:block;cursor:pointer" onclick="gotoLoans()">
    <div class="row" style="margin-bottom:10px"><b style="font-size:13px">💸 Loans</b>
      <span class="muted" style="font-size:11px">${openLoans} open${(FUNDS||[]).length?` · ${FUNDS.length} fund${FUNDS.length===1?'':'s'}`:''} · Open →</span></div>
    <div class="grid2" style="grid-template-columns:repeat(3,1fr);gap:10px">
      ${stat('Lent (total)', fmt(LOANTOT.lent||0))}
      ${stat('Outstanding', fmt(LOANTOT.outstanding||0), 'var(--orange)')}
      ${stat('Repaid', fmt(LOANTOT.repaid||0), 'var(--good)')}
    </div>
    ${fundRows?`<div style="margin-top:10px">${fundRows}</div>`:`<div class="muted" style="font-size:11px;margin-top:8px">No money lent out yet.</div>`}
  </div>`;
}

function dashTeamHtml(){
  const esc=s=>String(s==null?'':s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
  const open=(TASK.tasks||[]).filter(t=>t.status!=='done');
  if(!open.length && !(TASK.tasks||[]).length) return '';
  // workload by person + unassigned
  const load={}; let unassigned=0;
  open.forEach(t=>{ const as=(t.assignees||[]); if(!as.length){ unassigned++; } else as.forEach(a=>{ const n=a.name||a.email; load[n]=(load[n]||0)+1; }); });
  const people=Object.keys(load).sort((a,b)=>load[b]-load[a]);
  const chips = people.map(n=>`<span class="wchip">${dshAvatar(n,22)}<b>${esc(n)}</b> · ${load[n]}</span>`).join('')
    + (unassigned?`<span class="wchip" style="background:#FFF4EB;border-color:#F7C99A;cursor:pointer" onclick="gotoTodo()"><span class="avatar" style="width:22px;height:22px;font-size:9px;background:#F56F00">!</span><b>Unassigned</b> · ${unassigned} — assign</span>`:'');
  // assigned task list (compact)
  const assigned=open.filter(t=>(t.assignees||[]).length);
  const list = assigned.length? assigned.slice(0,8).map(t=>{
    const av=(t.assignees||[]).map(a=>dshAvatar(a.name||a.email,22)).join('');
    return `<div style="display:flex;align-items:center;gap:10px;padding:9px 0;border-bottom:1px solid var(--line)">
      <div style="display:flex;margin-right:2px">${av}</div>
      <div style="flex:1;min-width:0"><div style="font-weight:600;font-size:12.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${esc(t.title)}</div>
        <div class="muted" style="font-size:10.5px">${(t.assignees||[]).map(a=>esc(a.name||a.email)).join(', ')}</div></div>
    </div>`; }).join('') : `<div class="muted" style="font-size:11.5px;padding:6px 0">No tasks assigned to anyone yet${unassigned?` — you have ${unassigned} waiting.`:''}.</div>`;
  return `<div class="sect"><b>Team &amp; tasks</b><span class="ln"></span>
      <button class="btn sec" style="width:auto;padding:5px 11px;font-size:11px" onclick="gotoTodo()">Open To-Do →</button></div>
    <div class="card">
      <div class="wbar" style="margin-bottom:${people.length||unassigned?'12px':'0'}">${chips||'<span class="muted" style="font-size:11.5px">Nobody assigned yet.</span>'}</div>
      ${list}
    </div>`;
}
function vDashLite(){
  const showTeam = tabAllowed('todo');
  const showAudrey = tabAllowed('audrey');
  const showBoard = tabAllowed('taskboard');
  let html = `
  <div class="dsh-hero">
    <div class="ey">Welcome</div>
    <div style="font-size:22px;font-weight:800;letter-spacing:-.3px;margin-top:6px">${String(ME.user||'').replace(/</g,'&lt;')}</div>
    <div class="sub"><div><div class="l">Signed in to</div><div class="v">${(CFG.biz||'912 Finance Console')}</div></div></div>
  </div>
  <div class="muted" style="margin:-4px 2px 14px">Use the menu above to open the tabs you have access to.</div>`;
  if(showTeam) html += dashTeamHtml();
  if(showAudrey || showBoard){
    html += `<div class="sect"><b>Shared links</b><span class="ln"></span></div>
    <div style="display:flex;flex-direction:column;gap:10px;margin-bottom:6px">
      ${showAudrey?`<div class="linktile" onclick="window.open(AUDREY_URL,'_blank')">
        <div class="ic" style="background:#FFF4EB;color:var(--orange)">📊</div>
        <div style="flex:1;min-width:0"><div class="t">Audrey Report</div><div class="s">Unpaid-invoice tracker</div></div>
        <span class="btn sec" style="width:auto;padding:5px 9px;font-size:10.5px">Open →</span></div>`:''}
      ${showBoard?`<div class="linktile" onclick="window.open(TASKBOARD_URL,'_blank')">
        <div class="ic" style="background:#EEF2FE;color:var(--blue)">✅</div>
        <div style="flex:1;min-width:0"><div class="t">Task Board</div><div class="s">Who's doing what</div></div>
        <span class="btn sec" style="width:auto;padding:5px 9px;font-size:10.5px">Open →</span></div>`:''}
    </div>`;
  }
  if(!showTeam && !showAudrey && !showBoard){
    html += `<div class="card muted" style="text-align:center;padding:22px">Open one of your tabs from the menu above to get started.</div>`;
  }
  return html;
}

function vDash(){
  if(!ME.admin) return vDashLite();
  const s = summary(), r = rows(), overdue = r.filter(x=>x.overdue);
  const openTasks=(TASK.tasks||[]).filter(t=>t.status!=='done').length;
  return `
  <div class="dsh-hero">
    <div class="ey">Net profit so far · excl. VAT</div>
    <div class="big">${fmt(s.netProfit)}</div>
    <div class="sub">
      <div><div class="l">VAT element</div><div class="v" style="color:#AEB9C7">${fmt(s.totalVat)}</div></div>
      <div><div class="l">Loaned out</div><div class="v">${fmt(s.loanOut)}</div></div>
      <div><div class="l">Bridges restored</div><div class="v">${s.restored.length}</div></div>
      <div><div class="l">Open bridges</div><div class="v">${s.open.length}</div></div>
    </div>
  </div>
  <div class="muted" style="margin:-4px 2px 12px">VAT is collected for KRA, not earnings — shown but kept out of profit.</div>

  <div class="kpis">
    <div class="kpi" style="--accent:var(--orange)"><div class="l">Open bridges</div><div class="n">${s.open.length}</div><div class="h">Working capital deployed</div></div>
    <div class="kpi" style="--accent:${overdue.length?'var(--bad)':'var(--good)'}"><div class="l">Overdue</div><div class="n" style="color:${overdue.length?'var(--bad)':'var(--ink)'}">${overdue.length}</div><div class="h">${overdue.length?'Needs chasing':'All on track'}</div></div>
    <div class="kpi" style="--accent:var(--blue)"><div class="l">Open tasks</div><div class="n">${openTasks}</div><div class="h">On the to-do list</div></div>
    <div class="kpi" style="--accent:var(--good)"><div class="l">Restored</div><div class="n">${s.restored.length}</div><div class="h">Bridges repaid</div></div>
  </div>

  <div class="sect"><b>Working capital</b><span class="ln"></span>
    <button class="btn sec" style="width:auto;padding:5px 11px;font-size:11px" onclick="gotoLoans()">Manage loans →</button></div>
  <div class="wcgrid">
    ${dashWorkingCapitalHtml()}
    ${dashLoansHtml()}
  </div>

  ${dashTeamHtml()}

  <div class="wcgrid">
   <div>
   <div class="sect"><b>Shared links</b><span class="ln"></span></div>
  <div style="display:flex;flex-direction:column;gap:10px;margin-bottom:6px">
    <div class="linktile" onclick="window.open(AUDREY_URL,'_blank')">
      <div class="ic" style="background:#FFF4EB;color:var(--orange)">📊</div>
      <div style="flex:1;min-width:0"><div class="t">Audrey Report</div><div class="s">Unpaid-invoice tracker · public</div></div>
      <button class="btn sec" style="width:auto;padding:5px 9px;font-size:10.5px" onclick="event.stopPropagation();copyAudrey(this)">Copy</button>
    </div>
    <div class="linktile" onclick="window.open(TASKBOARD_URL,'_blank')">
      <div class="ic" style="background:#EEF2FE;color:var(--blue)">✅</div>
      <div style="flex:1;min-width:0"><div class="t">Task Board</div><div class="s">Who's doing what · public</div></div>
      <button class="btn sec" style="width:auto;padding:5px 9px;font-size:10.5px" onclick="event.stopPropagation();copyBoard(this)">Copy</button>
    </div>
  </div>
  </div>
   <div>
  <div class="sect"><b>Operations</b><span class="ln"></span></div>
  <div class="tool">
    <details>
      <summary>Backup to WorkDrive <span class="cv">${BK.folder?'folder set ✓':'set a folder'}</span></summary>
      <div class="body">
        <div class="muted" style="font-size:11px;margin-bottom:8px">Saves your ledger, Audrey notes, email book and settings as one <b>.sql</b> file — these live only in your database, so this is your safety copy.</div>
        <div class="row" style="gap:8px">
          <input id="bkFolder" type="text" placeholder="WorkDrive folder ID" value="${(BK.folder||'').replace(/"/g,'&quot;')}" style="flex:1;margin-bottom:0" oninput="BK.folder=this.value">
          <button class="btn sec" style="width:auto;padding:9px 12px;font-size:12px" onclick="bkBrowse()">📁 Browse</button>
        </div>
        ${BK.pick.open?`<div class="card" style="margin-top:8px;background:#FAFBFD">
          <div class="row" style="margin-bottom:8px">
            <b style="font-size:12px">${BK.pick.current?('📁 '+(BK.pick.current.name||'Folder')):'Pick a folder'}</b>
            <span>${BK.pick.current?`<button class="btn sec" style="width:auto;padding:4px 9px;font-size:11px" onclick="bkUp()">⬆ Up</button> `:''}<button class="btn sec" style="width:auto;padding:4px 9px;font-size:11px" onclick="bkCloseBrowse()">✕</button></span>
          </div>
          ${BK.pick.loading?`<div class="muted" style="font-size:11px;text-align:center;padding:10px">Loading folders…</div>`
            : BK.pick.err?`<div class="warn" style="font-size:11px">${BK.pick.err}</div>`
            : `${(BK.pick.current?BK.pick.folders:BK.pick.roots).length? (BK.pick.current?BK.pick.folders:BK.pick.roots).map(f=>`
                <div class="invrow" onclick="bkOpenFolder('${f.id}')"><div style="font-size:12px">📁 ${(f.name||'(folder)').replace(/</g,'&lt;')}</div><div class="muted" style="font-size:11px">open ›</div></div>`).join('')
              : `<div class="muted" style="font-size:11px;text-align:center;padding:8px">No sub-folders here.</div>`}`}
          ${BK.pick.current?`<button class="btn" style="width:100%;margin-top:8px;font-size:12px" onclick="bkUseFolder()">✓ Use “${(BK.pick.current.name||'this folder').replace(/"/g,'')}”</button>`:''}
        </div>`:''}
        <div class="row" style="gap:8px;margin-top:8px">
          <button class="btn" style="flex:1" onclick="runBackup()" ${BK.running?'disabled':''}>${BK.running?'Backing up…':'⤴ Back up now'}</button>
          <button class="btn sec" style="width:auto;padding:11px 13px;font-size:12px" onclick="window.open('api/backup.php?download=1','_blank')">⤓ Download</button>
        </div>
        ${BK.msg?`<div class="${BK.msgErr?'warn':'ok'}" style="margin-top:8px;font-size:11px">${BK.msg}</div>`:''}
        <div class="muted" style="font-size:10.5px;margin-top:6px">Remembers the last folder used. To back up elsewhere, change it and hit Back up now.</div>
      </div>
    </details>
    <details>
      <summary>Cache <span class="cv">last pull: ${cacheWhen()}</span></summary>
      <div class="body">
        <div class="muted" style="font-size:11px;margin-bottom:8px">Pre-builds the ETR and Profit reports for ${PRELOAD.year} so they open instantly for 24h. Other years load on demand.</div>
        <div style="font-size:11.5px;margin-bottom:8px">🕒 Last full cache from Zoho: <b>${cacheWhen()}</b></div>
        <button class="btn" onclick="preloadCache()" ${PRELOAD.running?'disabled':''}>${PRELOAD.running?'Pulling…':'⟳ Pull all data into cache'}</button>
        ${PRELOAD.log.length? `<div style="margin-top:8px;font-size:11px;line-height:1.6">${PRELOAD.log.map(l=>`<div>${l}</div>`).join('')}</div>`:''}
      </div>
    </details>
  </div>
  </div>
  </div>

  <div class="sect"><b>Chase list</b><span class="ln"></span>${overdue.length?`<span class="pill" style="background:#FDECEA;color:var(--bad)">${overdue.length} overdue</span>`:''}</div>
  ${overdue.length? overdue.map(x=>`<div class="card" style="border-left:4px solid var(--bad)">
    <div class="row"><b style="font-size:13px">${x.client||x.purpose||''}</b><b style="color:var(--orange)">${fmt(x.amount)}</b></div>
    <div class="muted">${x.invoice_number||''} · due ${x.expected_date||'—'} · ${x.dd} days</div></div>`).join('')
    : `<div class="card muted" style="text-align:center;padding:18px">Nothing overdue — you're all caught up. 🎉</div>`}`;
}

function invItems(){
  const q = (window._invQuery||'').toLowerCase();
  const list = INVOICES.filter(i=>(i.clientName+' '+i.invoiceNumber).toLowerCase().includes(q));
  if(!list.length) return '<div class="muted" style="padding:12px;text-align:center">No match.</div>';
  return list.map(i=>`<div class="invrow" onclick='pickInv(${JSON.stringify(JSON.stringify(i))})'>
     <div><div style="font-size:12.5px;font-weight:600">${i.clientName}</div>
     <div class="muted">${i.invoiceNumber}${i.currency==='USD'?` · <span style="color:#1a7f37;font-weight:700">USD $${fmtn(i.origBalance)}</span>`:''} · due ${i.dueDate}</div></div>
     <b style="color:var(--blue)">${fmt(i.balance)}</b></div>`).join('');
}
function onInvSearch(v){ window._invQuery=v; const b=document.getElementById('invListBox'); if(b) b.innerHTML=invItems(); }
function pickInv(s){ window._picked = JSON.parse(s); render(); }

function expItems(){
  const q = (window._expQuery||'').toLowerCase();
  const list = EXPENSES.filter(e=>(((e.account||'')+' '+(e.desc||'')+' '+(e.ref||'')+' '+(e.vendor||'')).toLowerCase()).includes(q));
  if(!list.length) return '<div class="muted" style="padding:12px;text-align:center">No match.</div>';
  return list.map(e=>`<div class="invrow" onclick='pickExp(${JSON.stringify(JSON.stringify(e))})'>
     <div><div style="font-size:12px;font-weight:600">${e.account||'Expense'}${e.ref?' · '+e.ref:''}</div>
     <div class="muted">${(e.desc||'').slice(0,46)} · ${e.date}</div></div>
     <b style="color:var(--orange)">${fmt(e.amount)}</b></div>`).join('');
}
function onExpSearch(v){ window._expQuery=v; const b=document.getElementById('expListBox'); if(b) b.innerHTML=expItems(); }
function pickExp(s){ const e=JSON.parse(s); window._form.cost=e.amount; window._expenseId=e.expenseId; window._showExp=false; render(); }
async function openExp(){ window._showExp=true; render(); if(!EXP_LOADED){ await loadExpenses(); render(); } }

function vDeploy(){
  const f = window._form, picked = window._picked;
  const invBlock = picked
    ? `<div class="card" style="border:1px solid var(--orange);background:#FFF4EB"><div class="row">
        <div><div style="font-weight:600;font-size:12.5px">${picked.clientName}</div>
        <div class="muted">${picked.invoiceNumber} · ${fmt(picked.balance)}${picked.currency==='USD'?` <span style="color:#1a7f37;font-weight:700">(USD $${fmtn(picked.origBalance)} @${fmtn(picked.fxRate)})</span>`:''} · due ${picked.dueDate}</div></div>
        <a onclick="window._picked=null;render()" style="color:var(--blue);cursor:pointer;font-size:12px">Change</a></div></div>`
    : (INV_LOADED
        ? `<input id="invSearch" placeholder="Search client or invoice…" value="${window._invQuery||''}" oninput="onInvSearch(this.value)">
           <div id="invListBox" style="max-height:200px;overflow:auto;border:1px solid var(--line);border-radius:10px">${invItems()}</div>`
        : `<button class="btn sec" id="loadInv" onclick="loadInvoices()">Load unpaid invoices from Zoho</button>`);

  const costBlock = window._showExp
    ? `<input id="expSearch" placeholder="Search expense (account, INV no, vendor)…" value="${window._expQuery||''}" oninput="onExpSearch(this.value)">
       <div id="expListBox" style="max-height:180px;overflow:auto;border:1px solid var(--line);border-radius:10px">${EXP_LOADED?expItems():'<div class="muted" style="padding:12px;text-align:center">Loading expenses…</div>'}</div>
       <a onclick="window._showExp=false;render()" style="color:var(--blue);cursor:pointer;font-size:12px">Type the cost manually instead</a>`
    : `<input id="cost" type="number" placeholder="Leave blank if unknown" value="${f.cost}" oninput="window._form.cost=this.value">
       <a onclick="openExp()" style="color:var(--blue);cursor:pointer;font-size:12px">Pick a logged expense from Zoho</a>`;

  return `
    <h2>Deploy working capital</h2>
    <label>Amount to deploy (${CFG.cur})</label>
    <input id="amount" type="number" placeholder="e.g. 300000" value="${f.amount}" oninput="window._form.amount=this.value">
    <label>What it's for</label>
    <input id="purpose" placeholder="Supplier, payroll, stock…" value="${f.purpose}" oninput="window._form.purpose=this.value">
    <label>Cost funded — COGS (optional, enter ex-VAT)</label>
    ${costBlock}
    <label style="margin-top:12px">Bridge against invoice</label>
    ${invBlock}
    <button class="btn" style="margin-top:14px" onclick="submitDeploy()">Log deployment</button>
    <div class="muted" style="text-align:center;margin-top:10px">Ticks “Funded by Working Capital” in Zoho automatically.</div>`;
}

async function loadExpenses(){
  const j = await api('api/expenses.php');
  if(j.ok){ EXPENSES = j.expenses; EXP_LOADED = true; } else alert('Zoho error: '+j.error);
}
async function submitDeploy(){
  const amount = +window._form.amount;
  const purpose = window._form.purpose;
  const cost = window._form.cost;
  const inv = window._picked;
  if(!amount || !inv){ alert('Enter an amount and pick an invoice.'); return; }
  const j = await api('api/deployments.php?action=create', {method:'POST',headers:{'Content-Type':'application/json'},
    body: JSON.stringify({amount, purpose, cost, expenseId: window._expenseId||null, invoiceId:inv.invoiceId, invoiceNumber:inv.invoiceNumber,
      clientName:inv.clientName, invoiceValue:inv.balance, expectedDate:inv.dueDate})});
  if(j.ok){
    window._picked=null; window._form={amount:'',purpose:'',cost:''}; window._expenseId=null; window._invQuery=''; window._showExp=false;
    if(j.flagged===false) alert('Logged, but the Zoho flag did not tick — check wc_custom_field in config.');
    TAB='ledger'; await loadDeployments();
  } else alert('Error: '+j.error);
}

function vLedger(){
  const r = rows();
  const head = `<h2>Working capital ledger</h2>`;
  if(!r.length) return head+`<div class="card muted" style="text-align:center">No deployments yet.</div>`;
  return head+'<div class="cardgrid">'+r.map(x=>{
    const done = x.status==='Restored';
    return `<div class="card" style="border-left:3px solid ${done?'var(--good)':x.overdue?'var(--bad)':'var(--orange)'}">
      <div class="row"><b style="font-size:13px">${x.client||x.purpose||''}</b>
        <span class="pill" style="background:${done?'#E7F6EC':x.overdue?'#FDECEA':'#FFF4EB'};color:${done?'var(--good)':x.overdue?'var(--bad)':'var(--orange)'}">${done?'Restored':x.overdue?'Overdue':'Open'}</span></div>
      <div class="muted">${x.invoice_number||''} ${x.purpose?'· '+x.purpose:''} · ${x.dd} days</div>
      <div class="grid3" style="margin-top:10px;font-size:11px">
        <div><div class="lab">Deployed</div><div class="val">${fmt(x.amount)}</div></div>
        <div><div class="lab">Invoice</div><div class="val">${fmt(x.invoice_value)}</div></div>
        <div><div class="lab">VAT (excl.)</div><div class="val" style="color:var(--blue)">${fmt(x.vat)}</div></div>
        <div><div class="lab">Cost</div><div class="val" style="color:${x.costLogged?'var(--ink)':'var(--mute)'}">${x.costLogged?fmt(x.cost):'—'}</div></div>
        <div><div class="lab">Financing</div><div class="val" style="color:var(--orange)">${fmt(x.financing)}</div></div>
        <div><div class="lab">Net profit</div><div class="val" style="color:${!x.costLogged?'var(--mute)':x.netProfit>=0?'var(--good)':'var(--bad)'}">${x.costLogged?fmt(x.netProfit):'pending'}</div></div>
      </div>
      <div style="margin-top:10px;display:flex;gap:8px">
        <button class="btn sec" style="flex:1" onclick="logCost(${x.id}, '${x.cost??''}')">${x.costLogged?'Edit cost':'+ Log cost'}</button>
        ${!done?`<button class="btn sec" style="flex:1" onclick="restore(${x.id}, ${x.invoice_value||0})">Restore</button>`:''}
        <button class="btn sec" style="width:44px" onclick="delDep(${x.id})">✕</button>
      </div></div>`;
  }).join('')+'</div>';
}
async function logCost(id, cur){ const v = prompt('Cost funded for this invoice (KES):', cur); if(v===null) return;
  await api('api/deployments.php?action=cost',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id,cost:+v})}); loadDeployments(); }
async function restore(id, val){ const a = prompt('Cash actually received (KES):', val); if(a===null) return;
  await api('api/deployments.php?action=restore',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id,amount:+a,date:today()})}); loadDeployments(); }
async function delDep(id){ if(!confirm('Delete this deployment?')) return;
  await api('api/deployments.php?action=delete',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id})}); loadDeployments(); }

function vLoans(){
  const s = summary();
  const esc=x=>String(x==null?'':x).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  const F = LOANFORM, FF = FUNDFORM;
  const bridge = s.exposure;
  const fundAvail = f => Number(f.balance||0) - Number(f.outstanding||0) - (f.is_primary?bridge:0);
  const borrowers = [...new Set(LOANS.filter(l=>l.borrower_type==='person').map(l=>l.borrower_name))];
  const open = LOANS.filter(l=>l.status!=='Repaid'), repaid = LOANS.filter(l=>l.status==='Repaid');

  // ---- Funds registry cards ----
  const fundCards = (FUNDS||[]).map(f=>{
    const avail = fundAvail(f);
    return `<div class="card" style="border-left:4px solid ${f.is_primary?'var(--blue)':'var(--orange)'}">
      <div class="row">
        <b style="font-size:14px">${esc(f.name)}</b>
        ${f.is_primary?'<span class="pill" style="background:#EEF2FE;color:var(--blue)">Primary</span>':''}
      </div>
      <div class="grid2" style="grid-template-columns:repeat(3,1fr);margin-top:8px">
        <div><div class="lab">Balance</div><div style="font-weight:700">${fmt(Number(f.balance||0))}</div></div>
        <div><div class="lab">Lent out</div><div style="font-weight:700;color:var(--orange)">${fmt(Number(f.outstanding||0))}</div></div>
        <div style="text-align:right"><div class="lab">Available</div><div style="font-weight:700;color:${avail>=0?'var(--good)':'var(--bad)'}">${fmt(avail)}</div></div>
      </div>
      ${f.is_primary
        ? `<div class="muted" style="font-size:10.5px;margin-top:6px">Balance is the working-capital fund (set in Settings). Available also subtracts open bridges (${fmt(bridge)}).</div>
           <button class="btn sec" style="width:auto;padding:5px 11px;font-size:11px;margin-top:8px" onclick="fundRename(${f.id},'${esc(f.name).replace(/'/g,'')}')">Rename</button>`
        : `<div style="display:flex;gap:6px;margin-top:8px;flex-wrap:wrap">
            <button class="btn sec" style="width:auto;padding:5px 11px;font-size:11px" onclick="fundRename(${f.id},'${esc(f.name).replace(/'/g,'')}')">Rename</button>
            <button class="btn sec" style="width:auto;padding:5px 11px;font-size:11px" onclick="fundSetBalance(${f.id},${Number(f.balance||0)})">Set balance</button>
            <button class="btn sec" style="width:auto;padding:5px 11px;font-size:11px" onclick="fundDelete(${f.id})">Delete</button>
          </div>`}
    </div>`;
  }).join('');

  // ---- Loan card ----
  const card = l => {
    const out = Number(l.outstanding||0), amt = Number(l.amount||0), rep = Number(l.repaid||0);
    const isBiz = l.borrower_type==='business';
    const done = l.status==='Repaid';
    const reps = (l.repayments||[]);
    const hist = reps.length? `<div style="margin-top:8px;border-top:1px solid var(--line);padding-top:8px">
        ${reps.map(r=>`<div class="row" style="font-size:11px;color:var(--mute)"><span>↩ ${esc(r.repaid_date||'')}${r.note?' · '+esc(r.note):''}</span><span>${fmt(Number(r.amount))}</span></div>`).join('')}
      </div>` : '';
    return `<div class="card" style="border-left:4px solid ${done?'var(--good)':(isBiz?'var(--blue)':'var(--orange)')}">
      <div class="row">
        <div><b style="font-size:14px">${isBiz?'🏢 The business':('👤 '+esc(l.borrower_name))}</b>
          <div class="muted" style="font-size:11px;margin-top:2px">💼 ${esc(l.fund_name||'—')} · Lent ${esc(l.loan_date||'')}${l.expected_date?' · due '+esc(l.expected_date):''}${l.note?' · '+esc(l.note):''}</div>
        </div>
        <span class="pill" style="background:${done?'#E7F6EC':'#FFF4EB'};color:${done?'var(--good)':'var(--orange)'}">${done?'Repaid':'Open'}</span>
      </div>
      <div class="grid2" style="margin-top:10px">
        <div><div class="lab">Lent out</div><div style="font-weight:700">${fmt(amt)}</div></div>
        <div style="text-align:right"><div class="lab">Outstanding</div><div style="font-weight:700;color:${out>0?'var(--orange)':'var(--good)'}">${fmt(out)}</div></div>
      </div>
      ${rep>0?`<div class="muted" style="font-size:11px;margin-top:4px">Repaid so far: ${fmt(rep)}</div>`:''}
      ${hist}
      ${!done?`<div style="display:flex;gap:6px;margin-top:10px;align-items:center;flex-wrap:wrap">
        <input id="rpAmt${l.id}" type="number" step="100" placeholder="Repay amount" style="flex:1;min-width:120px;margin:0">
        <input id="rpDate${l.id}" type="date" value="${today()}" style="width:auto;margin:0">
        <button class="btn" style="width:auto;padding:9px 13px;font-size:12px" onclick="loanRepay(${l.id})">Log repayment</button>
      </div>`:''}
      <button class="btn sec" style="width:auto;padding:5px 11px;font-size:11px;margin-top:8px" onclick="loanDelete(${l.id})">Delete</button>
    </div>`;
  };

  const fundOptions = (FUNDS||[]).map(f=>`<option value="${f.id}" ${F.fundId==f.id?'selected':''}>${esc(f.name)} — ${fmt(fundAvail(f))} available</option>`).join('');

  return `
  <h2>Loans from the funds</h2>
  <div class="muted" style="margin:-6px 0 12px;font-size:12px">Lend money out of a chosen fund — to the business itself or to a person — and log repayments. Each fund keeps its own balance; money still owed is subtracted from that fund's <b>available</b>. Principal only, no interest.</div>

  <div class="grid2" style="grid-template-columns:repeat(3,1fr)">
    <div class="card"><div class="lab">Lent out (total)</div><div style="font-weight:700;font-size:18px">${fmt(LOANTOT.lent||0)}</div></div>
    <div class="card"><div class="lab">Outstanding</div><div style="font-weight:700;font-size:18px;color:var(--orange)">${fmt(LOANTOT.outstanding||0)}</div></div>
    <div class="card"><div class="lab">Repaid</div><div style="font-weight:700;font-size:18px;color:var(--good)">${fmt(LOANTOT.repaid||0)}</div></div>
  </div>

  <div class="sect"><b>Funds</b><span class="ln"></span></div>
  ${fundCards}
  <div class="card">
    <div style="font-weight:700;font-size:12.5px;margin-bottom:8px">Add a fund</div>
    <div style="display:flex;gap:6px;flex-wrap:wrap">
      <input type="text" placeholder="Fund name" value="${esc(FF.name)}" oninput="FUNDFORM.name=this.value" style="flex:2;min-width:160px;margin:0">
      <input type="number" step="1000" placeholder="Starting balance" value="${esc(FF.balance)}" oninput="FUNDFORM.balance=this.value" style="flex:1;min-width:120px;margin:0">
      <button class="btn" style="width:auto;padding:9px 14px;font-size:12px" onclick="fundAdd()">Add fund</button>
    </div>
    ${FF.msg?`<div class="${FF.err?'warn':'ok'}" style="margin-top:8px;font-size:11px">${FF.msg}</div>`:''}
  </div>

  <div class="sect"><b>Lend money</b><span class="ln"></span></div>
  <div class="card">
    <label>From fund</label>
    <select onchange="LOANFORM.fundId=this.value">${fundOptions}</select>
    <div class="seg" style="display:inline-flex;border:1px solid var(--line);border-radius:9px;overflow:hidden;margin:10px 0">
      <button onclick="LOANFORM.type='person';render()" style="border:0;padding:8px 14px;font-size:12px;font-weight:600;cursor:pointer;background:${F.type==='person'?'var(--ink)':'#fff'};color:${F.type==='person'?'#fff':'var(--mute)'}">👤 A person</button>
      <button onclick="LOANFORM.type='business';render()" style="border:0;padding:8px 14px;font-size:12px;font-weight:600;cursor:pointer;background:${F.type==='business'?'var(--ink)':'#fff'};color:${F.type==='business'?'#fff':'var(--mute)'}">🏢 The business</button>
    </div>
    ${F.type==='person'
      ? `<label>Person's name</label>
         <input list="borrowerList" type="text" value="${esc(F.name)}" oninput="LOANFORM.name=this.value" placeholder="Who are you lending to?">
         <datalist id="borrowerList">${borrowers.map(b=>`<option value="${esc(b)}">`).join('')}</datalist>`
      : `<label>Reference (optional)</label>
         <input type="text" value="${esc(F.name)}" oninput="LOANFORM.name=this.value" placeholder="e.g. payroll top-up (optional)">`}
    <label>Amount (${CFG.cur})</label>
    <input type="number" step="100" value="${esc(F.amount)}" oninput="LOANFORM.amount=this.value" placeholder="How much?">
    <div class="grid2">
      <div><label>Date lent</label><input type="date" value="${F.date||today()}" oninput="LOANFORM.date=this.value"></div>
      <div><label>Expected back (optional)</label><input type="date" value="${esc(F.expected)}" oninput="LOANFORM.expected=this.value"></div>
    </div>
    <label>Note (optional)</label>
    <input type="text" value="${esc(F.note)}" oninput="LOANFORM.note=this.value" placeholder="What's it for?">
    <button class="btn" style="margin-top:10px" onclick="loanAdd()">Lend from fund</button>
    ${F.msg?`<div class="${F.err?'warn':'ok'}" style="margin-top:10px">${F.msg}</div>`:''}
  </div>

  ${open.length?`<div style="font-weight:600;font-size:13px;margin:14px 0 8px">Outstanding loans (${open.length})</div>${open.map(card).join('')}`:`<div class="card muted" style="text-align:center;padding:18px">No money lent out yet.</div>`}
  ${repaid.length?`<div style="font-weight:600;font-size:13px;margin:16px 0 8px">Fully repaid (${repaid.length})</div>${repaid.map(card).join('')}`:''}
  `;
}

async function loanAdd(){
  const F = LOANFORM;
  const amount = parseFloat(F.amount)||0;
  if(amount<=0){ F.msg='Enter an amount greater than zero.'; F.err=true; render(); return; }
  if(F.type==='person' && !(F.name||'').trim()){ F.msg='Enter the person\'s name.'; F.err=true; render(); return; }
  F.msg='Lending…'; F.err=false; render();
  const j = await api('api/loans.php?action=create',{method:'POST',headers:{'Content-Type':'application/json'},
    body:JSON.stringify({fund_id:F.fundId||PRIMARY_ID, borrower_type:F.type, borrower_name:F.name, amount, loan_date:F.date||today(), expected_date:F.expected, note:F.note})});
  if(j.ok){ LOANS=j.loans||[]; LOANTOT=j.totals||LOANTOT; FUNDS=j.funds||FUNDS; const keepFund=F.fundId; LOANFORM={type:F.type,name:'',amount:'',date:'',expected:'',note:'',fundId:keepFund,msg:'Lent ✓ — fund available updated.',err:false}; paintFund(); render(); }
  else { F.msg='Error: '+(j.error||'failed'); F.err=true; render(); }
}
async function loanRepay(id){
  const amt = parseFloat((document.getElementById('rpAmt'+id)||{}).value)||0;
  const date = (document.getElementById('rpDate'+id)||{}).value || today();
  if(amt<=0){ alert('Enter a repayment amount.'); return; }
  const j = await api('api/loans.php?action=repay',{method:'POST',headers:{'Content-Type':'application/json'},
    body:JSON.stringify({id, amount:amt, repaid_date:date})});
  if(j.ok){ LOANS=j.loans||[]; LOANTOT=j.totals||LOANTOT; FUNDS=j.funds||FUNDS; paintFund(); render(); }
  else alert(j.error||'Could not log repayment.');
}
async function loanDelete(id){
  if(!confirm('Delete this loan and its repayment history?')) return;
  const j = await api('api/loans.php?action=delete',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id})});
  if(j.ok){ LOANS=j.loans||[]; LOANTOT=j.totals||LOANTOT; FUNDS=j.funds||FUNDS; paintFund(); render(); }
  else alert(j.error||'Could not delete.');
}
async function fundAdd(){
  const FF=FUNDFORM; const name=(FF.name||'').trim();
  if(!name){ FF.msg='Enter a fund name.'; FF.err=true; render(); return; }
  const j = await api('api/loans.php?action=fund_add',{method:'POST',headers:{'Content-Type':'application/json'},
    body:JSON.stringify({name, balance:parseFloat(FF.balance)||0})});
  if(j.ok){ FUNDS=j.funds||FUNDS; LOANTOT=j.totals||LOANTOT; FUNDFORM={name:'',balance:'',msg:'Fund added ✓',err:false}; render(); }
  else { FF.msg='Error: '+(j.error||'failed'); FF.err=true; render(); }
}
async function fundRename(id,cur){
  const name = prompt('Rename fund:', cur||''); if(name===null) return;
  const j = await api('api/loans.php?action=fund_update',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id, name})});
  if(j.ok){ FUNDS=j.funds||FUNDS; LOANS=j.loans||LOANS; render(); } else alert(j.error||'Failed.');
}
async function fundSetBalance(id,cur){
  const v = prompt('Set fund balance ('+CFG.cur+'):', cur||0); if(v===null) return;
  const bal = parseFloat(v); if(isNaN(bal)){ alert('Enter a number.'); return; }
  const j = await api('api/loans.php?action=fund_update',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id, balance:bal})});
  if(j.ok){ FUNDS=j.funds||FUNDS; render(); } else alert(j.error||'Failed.');
}
async function fundDelete(id){
  if(!confirm('Delete this fund?')) return;
  const j = await api('api/loans.php?action=fund_delete',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id})});
  if(j.ok){ FUNDS=j.funds||FUNDS; render(); } else alert(j.error||'Could not delete.');
}

let USERS = { list:[], loaded:false, msg:'', err:false, newU:'', newP:'', newE:'', newTabs:[], newAdmin:false, importing:false, importMsg:'', importErr:false, imported:[] };
async function usersImportZoho(){
  USERS.importing=true; USERS.importMsg=''; USERS.imported=[]; render();
  try{
    const j = await api('api/users.php?action=import_zoho',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({})});
    USERS.importing=false;
    if(j.ok){ USERS.list=j.users||[]; USERS.imported=j.created||[]; USERS.importErr=false;
      USERS.importMsg='Imported '+(j.imported||0)+' new '+((j.imported===1)?'user':'users')+(j.skipped?(' · '+j.skipped+' already there'):'')+'.'; }
    else { USERS.importErr=true; USERS.importMsg='Error: '+(j.error||'failed'); }
  }catch(e){ USERS.importing=false; USERS.importErr=true; USERS.importMsg='Error: '+e; }
  render();
}

async function usersLoad(){
  try{ const j = await api('api/users.php?action=list',{credentials:'same-origin'});
    if(j.ok){ USERS.list=j.users||[]; USERS.loaded=true; } else { USERS.msg=j.error||'Failed'; USERS.err=true; }
  }catch(e){ USERS.msg=String(e); USERS.err=true; }
  render();
}
function uTabsBoxes(selected, onToggle){
  const sel = selected||[];
  return Object.keys(ALLTABS).map(k=>{
    const on = sel.includes(k);
    return `<button class="btn${on?'':' sec'}" style="width:auto;flex:0 0 auto;padding:5px 10px;font-size:11px" onclick="${onToggle}('${k}')">${ALLTABS[k]}</button>`;
  }).join('');
}
function vUsers(){
  if(!ME.admin) return `<div class="card muted" style="text-align:center">Admins only.</div>`;
  const esc=s=>String(s==null?'':s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  const N = USERS;
  const importCard = `<div class="card" style="border-left:4px solid var(--blue)">
    <div class="row"><b style="font-size:13px">⤵ Import staff from Zoho</b>
      <button class="btn sec" style="width:auto;padding:5px 11px;font-size:11px" onclick="usersImportZoho()" ${N.importing?'disabled':''}>${N.importing?'Importing…':'Import from Zoho'}</button></div>
    <div class="muted" style="font-size:11px;margin-top:4px">Pulls your Zoho org users into the app as logins (To-Do access by default). Already-imported people are skipped.</div>
    ${N.importMsg?`<div class="${N.importErr?'warn':'ok'}" style="margin-top:8px;font-size:12px">${N.importMsg}</div>`:''}
    ${(N.imported&&N.imported.length)?`<div style="margin-top:8px;border:1px solid var(--line);border-radius:8px;overflow:hidden">
      <div style="background:#F1F4F8;padding:6px 9px;font-size:11px;font-weight:700">New logins — share these passwords, then ask them to change it</div>
      ${N.imported.map(c=>`<div class="row" style="padding:6px 9px;border-top:1px solid var(--line);font-size:12px"><span><b>${esc(c.username)}</b> · ${esc(c.email)}</span><code style="background:#FFF4E8;padding:2px 7px;border-radius:6px">${esc(c.password)}</code></div>`).join('')}
    </div>`:''}
  </div>`;
  const addCard = `<div class="card">
    <div style="font-weight:700;font-size:13px;margin-bottom:10px">Add a user</div>
    <label>Username</label>
    <input type="text" value="${esc(N.newU)}" oninput="USERS.newU=this.value" placeholder="e.g. bashir">
    <label>Password</label>
    <input type="text" value="${esc(N.newP)}" oninput="USERS.newP=this.value" placeholder="Set a password">
    <label>Email <span class="muted" style="font-weight:400">(must match how tasks are assigned to them)</span></label>
    <input type="text" value="${esc(N.newE||'')}" oninput="USERS.newE=this.value" placeholder="e.g. bashir@nineonetwo.co.ke">
    <label style="display:flex;align-items:center;gap:8px;margin:8px 0"><input type="checkbox" style="width:auto" ${N.newAdmin?'checked':''} onchange="USERS.newAdmin=this.checked;render()"> <span>Admin (full access + manage users)</span></label>
    ${N.newAdmin?'':`<label>Allowed tabs <span class="muted" style="font-weight:400">(tap to toggle)</span></label>
    <div style="display:flex;flex-wrap:wrap;gap:6px;margin:4px 0 6px">${uTabsBoxes(N.newTabs,'uNewToggle')}</div>`}
    <button class="btn" style="margin-top:8px" onclick="userCreate()">Create user</button>
    ${N.msg?`<div class="${N.err?'warn':'ok'}" style="margin-top:8px;font-size:12px">${N.msg}</div>`:''}
  </div>`;

  const rows = (N.list||[]).map(u=>{
    const tabs = (u.tabs||'').split(',').filter(Boolean);
    const chips = u.is_admin? '<span class="pill" style="background:#EEF2FE;color:var(--blue)">Full access</span>'
      : (tabs.length? tabs.map(t=>`<span class="pill" style="background:#F1F4F8;color:var(--ink)">${ALLTABS[t]||t}</span>`).join(' ') : '<span class="muted" style="font-size:11px">No tabs</span>');
    const editing = N.editId===u.id;
    return `<div class="card">
      <div class="row"><b style="font-size:14px">${esc(u.username)}${u.is_admin?' 👑':''}</b>
        <span class="muted" style="font-size:11px">#${u.id}</span></div>
      <div class="muted" style="font-size:11px;margin-top:2px">${u.email?('✉ '+esc(u.email)):'<span style="color:var(--bad)">no email — cannot see their tasks</span>'}${u.calendar?' · <span style="color:var(--good)">📅 calendar connected</span>':''}</div>
      <div style="margin-top:6px;display:flex;flex-wrap:wrap;gap:5px">${chips}</div>
      ${!u.is_admin&&editing?`<div style="margin-top:10px"><label>Allowed tabs</label>
        <div style="display:flex;flex-wrap:wrap;gap:6px;margin:4px 0 8px">${uTabsBoxes(N.editTabs,'uEditToggle')}</div>
        <button class="btn" style="width:auto;padding:7px 13px;font-size:12px" onclick="userSaveTabs(${u.id})">Save tabs</button>
        <button class="btn sec" style="width:auto;padding:7px 13px;font-size:12px" onclick="USERS.editId=0;render()">Cancel</button></div>`
      : `<div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:10px">
          ${u.is_admin?'':`<button class="btn sec" style="width:auto;padding:5px 11px;font-size:11px" onclick="userEdit(${u.id})">Edit tabs</button>`}
          <button class="btn sec" style="width:auto;padding:5px 11px;font-size:11px" onclick="userSetEmail(${u.id},'${esc(u.email||'').replace(/'/g,'')}')">Set email</button>
          <button class="btn sec" style="width:auto;padding:5px 11px;font-size:11px" onclick="userPasswd(${u.id},'${esc(u.username).replace(/'/g,'')}')">Reset password</button>
          <button class="btn sec" style="width:auto;padding:5px 11px;font-size:11px" onclick="userDelete(${u.id},'${esc(u.username).replace(/'/g,'')}')">Delete</button>
        </div>`}
    </div>`;
  }).join('');

  return `<div class="sect" style="margin-top:20px"><b>Users &amp; access</b><span class="ln"></span></div>
  <div class="muted" style="margin:-2px 0 12px;font-size:12px">Stored securely in your database (passwords are hashed). Create logins and choose which tabs each person sees. You (master password) are always admin and the only one who sees this section.</div>
  ${importCard}
  ${addCard}
  <div style="font-weight:600;font-size:13px;margin:14px 0 8px">Users (${(N.list||[]).length})</div>
  ${N.loaded? (rows||'<div class="card muted" style="text-align:center">No users yet — add one above.</div>') : '<div class="card muted" style="text-align:center">Loading…</div>'}`;
}

function uNewToggle(k){ const a=USERS.newTabs; const i=a.indexOf(k); if(i>=0)a.splice(i,1); else a.push(k); render(); }
function uEditToggle(k){ const a=USERS.editTabs; const i=a.indexOf(k); if(i>=0)a.splice(i,1); else a.push(k); render(); }
function userEdit(id){ const u=(USERS.list||[]).find(x=>x.id===id); USERS.editId=id; USERS.editTabs=(u&&u.tabs?u.tabs.split(','):[]).filter(Boolean); render(); }
async function userCreate(){
  const N=USERS;
  if(!(N.newU||'').trim()||!(N.newP||'').trim()){ N.msg='Username and password are required.'; N.err=true; render(); return; }
  const j = await api('api/users.php?action=create',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},
    body:JSON.stringify({username:N.newU, password:N.newP, email:N.newE||'', is_admin:N.newAdmin?1:0, tabs:N.newTabs.join(',')})});
  if(j.ok){ USERS.list=j.users||[]; USERS.newU='';USERS.newP='';USERS.newE='';USERS.newTabs=[];USERS.newAdmin=false; USERS.msg='User created ✓'; USERS.err=false; }
  else { USERS.msg='Error: '+(j.error||'failed'); USERS.err=true; }
  render();
}
async function userSetEmail(id,cur){
  const e = prompt('Email used when assigning tasks to this user:', cur||''); if(e===null) return;
  const j = await api('api/users.php?action=update',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({id, email:e})});
  if(j.ok){ USERS.list=j.users||[]; } else alert(j.error||'Failed.');
  render();
}
async function userSaveTabs(id){
  const j = await api('api/users.php?action=update',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},
    body:JSON.stringify({id, tabs:(USERS.editTabs||[]).join(',')})});
  if(j.ok){ USERS.list=j.users||[]; USERS.editId=0; } else alert(j.error||'Failed.');
  render();
}
async function userPasswd(id,name){
  const p = prompt('New password for '+name+':'); if(p===null||!p.trim()) return;
  const j = await api('api/users.php?action=passwd',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({id, password:p})});
  if(j.ok) alert('Password updated.'); else alert(j.error||'Failed.');
}
async function userDelete(id,name){
  if(!confirm('Delete user '+name+'?')) return;
  const j = await api('api/users.php?action=delete',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({id})});
  if(j.ok){ USERS.list=j.users||[]; } else alert(j.error||'Failed.');
  render();
}

function vGrowth(){
  const target = CFG.fund*(CFG.growth||2), s = summary();
  const cur = CFG.fund + s.netProfit, pct = Math.min(100, s.netProfit/CFG.fund*100);
  return `<h2>Working capital growth</h2>
    <div class="card" style="background:var(--ink);color:#fff">
    <div class="muted" style="color:#9AA7B8">Fund value now</div>
    <div style="font-size:24px;font-weight:700">${fmt(cur)} <span style="font-size:13px;color:#5BD68A">+${fmt1(s.netProfit/CFG.fund*100)}%</span></div>
    <div class="muted" style="color:#9AA7B8">Started ${fmt(CFG.fund)} · target ${fmt(target)}</div>
    <div class="meter" style="margin-top:10px;background:rgba(255,255,255,.14)"><div style="width:${pct}%"></div></div>
    <div class="muted" style="color:#9AA7B8;margin-top:6px">${fmt1(pct)}% to doubling</div></div>
    <div class="card"><div style="font-weight:600;font-size:13px;margin-bottom:6px">Fund growth over time</div>
    <svg id="chart" width="100%" height="180" viewBox="0 0 440 180"></svg></div>`;
}
function drawChart(){
  const svg = document.getElementById('chart'); if(!svg) return;
  const restored = rows().filter(x=>x.status==='Restored' && x.restored_date && x.costLogged)
    .sort((a,b)=>new Date(a.restored_date)-new Date(b.restored_date));
  let cum = 0; const pts = [{v:CFG.fund}];
  restored.forEach(x=>{ cum+=x.netProfit; pts.push({v:CFG.fund+cum}); });
  const target = CFG.fund*(CFG.growth||2), max = Math.max(target, ...pts.map(p=>p.v))*1.02, min = CFG.fund*0.98;
  const W=440,H=180,pad=8;
  const x = i => pad + i*(W-2*pad)/Math.max(1,pts.length-1);
  const y = v => H-pad - (v-min)/(max-min)*(H-2*pad);
  const line = pts.map((p,i)=>`${i?'L':'M'}${x(i).toFixed(1)},${y(p.v).toFixed(1)}`).join(' ');
  const ty = y(target);
  svg.innerHTML = `
    <line x1="${pad}" y1="${ty}" x2="${W-pad}" y2="${ty}" stroke="#2350C5" stroke-dasharray="5 4"/>
    <text x="${W-pad}" y="${ty-4}" text-anchor="end" font-size="10" fill="#2350C5">double</text>
    <path d="${line}" fill="none" stroke="#F56F00" stroke-width="2.4"/>
    ${pts.map((p,i)=>`<circle cx="${x(i).toFixed(1)}" cy="${y(p.v).toFixed(1)}" r="2.6" fill="#F56F00"/>`).join('')}
    ${pts.length<2?`<text x="${W/2}" y="${H/2}" text-anchor="middle" font-size="11" fill="#64748B">Restore a bridge to start the curve</text>`:''}`;
}

/* ---------- report (all Zoho invoices vs matched expenses) ---------- */
window.REPORT = window.REPORT || {rows:[], loading:false, loaded:false, error:null, truncated:false};

/* ---- Pull all data into cache (warm the heavy reports once) ---- */
window.PRELOAD = window.PRELOAD || { running:false, log:[], year: Math.max(2026, new Date().getFullYear()) };
window.CACHEMETA = window.CACHEMETA || { last:null };
function cacheWhen(){ if(!CACHEMETA.last) return 'never'; try{ return new Date(CACHEMETA.last).toLocaleString(undefined,{dateStyle:'medium',timeStyle:'short'}); }catch(e){ return CACHEMETA.last; } }
async function loadCacheMeta(){ try{ const r=await fetch('api/cache_meta.php',{credentials:'same-origin'}); const j=await r.json(); if(j&&j.ok){ CACHEMETA.last=j.last||null; render(); } }catch(e){} }

async function preloadCache(){
  if(PRELOAD.running) return;
  const y = PRELOAD.year;
  const curM = new Date().getMonth()+1;
  const tasks = [
    ['ETR — unpaid',          `api/etr_report.php?year=${y}&months=&status=unpaid&refresh=1`],
    ['ETR — paid',            `api/etr_report.php?year=${y}&months=&status=paid&refresh=1`],
    ['ETR — all',             `api/etr_report.php?year=${y}&months=&status=all&refresh=1`],
    ['Profit — this month',   `api/report.php?year=${y}&months=${curM}&refresh=1`],
    ['Profit — full year',    `api/report.php?year=${y}&months=&refresh=1`],
  ];
  PRELOAD.running = true; PRELOAD.log = ['Starting…']; render();
  for(let i=0;i<tasks.length;i++){
    const [name,url] = tasks[i];
    PRELOAD.log.push('⏳ '+name); render();
    try {
      const r = await fetch(url,{credentials:'same-origin'});
      const j = await r.json();
      PRELOAD.log[PRELOAD.log.length-1] = (j && j.ok!==false ? '✓ ' : '⚠ ') + name + (j && j.error ? ' — '+j.error : '');
    } catch(e){
      PRELOAD.log[PRELOAD.log.length-1] = '⚠ '+name+' (failed)';
    }
    render();
  }
  PRELOAD.log.push('Done — these reports now open instantly for 24h.');
  PRELOAD.running = false; render();
  try{ const r=await fetch('api/cache_meta.php',{method:'POST',credentials:'same-origin'}); const j=await r.json(); if(j&&j.ok){ CACHEMETA.last=j.last; render(); } }catch(e){}
}

window._rptMonths = window._rptMonths || null; // null = not yet initialised
window._rptSearch = window._rptSearch || '';
window._rptClients = window._rptClients || []; // selected client names (multi-pick)
window.EXP = window.EXP || { accounts:null, loadingAcc:false, invoice:'', invSearch:'', invInfo:null, liveResults:[], liveLoading:false, liveFor:'', amount:'', date:'', desc:'', acc:'', paid:'', msg:'', err:false, open:false };
function expCombinedResults(){
  const q=String(EXP.invSearch||'').trim().toLowerCase();
  const seen={}, out=[];
  if(q){
    (REPORT.rows||[]).forEach(x=>{ if((String(x.number||'').toLowerCase().includes(q)||String(x.client||'').toLowerCase().includes(q)) && !seen[x.number]){ seen[x.number]=1; out.push({number:x.number,client:x.client,reference:'',total:x.total,id:x.id}); } });
    (EXP.liveResults||[]).forEach(x=>{ if(!seen[x.number]){ seen[x.number]=1; out.push(x); } });
  }
  return out.slice(0,25);
}
function expInvResultsHtml(){
  const esc=s=>String(s==null?'':s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  const q=String(EXP.invSearch||'').trim();
  if(!q) return '<div class="muted" style="font-size:11px;margin:4px 2px 0">Start typing an invoice number…</div>';
  const res=expCombinedResults();
  window._expResults=res;
  const loading = EXP.liveLoading && EXP.liveFor!==q ? '<div class="muted" style="font-size:11px;margin:4px 2px">Searching Zoho…</div>' : '';
  if(!res.length) return (EXP.liveLoading?'<div class="muted" style="font-size:11px;margin:4px 2px 0">Searching Zoho…</div>':`<div class="muted" style="font-size:11px;margin:4px 2px 0">No match yet. <a onclick="expPickTyped()" style="color:var(--blue);cursor:pointer">Use "${esc(q)}" anyway</a></div>`);
  return `<div style="border:1px solid var(--line);border-radius:10px;overflow:hidden;margin-top:6px">${
    res.map((x,i)=>`<div class="invrow" style="cursor:pointer" onclick="expPickIdx(${i})">
      <div style="font-size:12.5px"><b>${esc(x.number)}</b> · ${esc(x.client||'')}</div>
      <div class="muted" style="font-size:11px">${x.total?fmt(x.total):''} ›</div></div>`).join('')
  }</div>${loading}`;
}
let _expTimer=null;
function expFilterInvoices(val){
  EXP.invSearch=val;
  const el=document.getElementById('expInvResults'); if(el) el.innerHTML=expInvResultsHtml();
  clearTimeout(_expTimer);
  const q=String(val||'').trim();
  if(q.length<2){ EXP.liveResults=[]; EXP.liveFor=''; return; }
  _expTimer=setTimeout(()=>expLiveSearch(q), 350);
}
function expLiveSearch(q){
  EXP.liveLoading=true; const el=document.getElementById('expInvResults'); if(el) el.innerHTML=expInvResultsHtml();
  fetch('api/invoice_search.php?q='+encodeURIComponent(q),{credentials:'same-origin'}).then(r=>r.json()).then(j=>{
    EXP.liveLoading=false; EXP.liveFor=q; EXP.liveResults=(j&&j.ok)?(j.invoices||[]):[];
    if(String(EXP.invSearch||'').trim()===q){ const e=document.getElementById('expInvResults'); if(e) e.innerHTML=expInvResultsHtml(); }
  }).catch(()=>{ EXP.liveLoading=false; const e=document.getElementById('expInvResults'); if(e) e.innerHTML=expInvResultsHtml(); });
}
function expMatches(){ return expCombinedResults(); }
function expPickIdx(i){
  const r=(window._expResults||[])[i]; if(!r) return;
  EXP.invoice=r.number; EXP.invSearch=''; EXP.liveResults=[]; EXP.liveFor='';
  EXP.invInfo={customer:r.client||'',reference:r.reference||'',subject:'',loading:!!r.id};
  render();
  if(r.id){
    fetch('api/invoice_meta.php?id='+encodeURIComponent(r.id),{credentials:'same-origin'}).then(x=>x.json()).then(j=>{
      if(j&&j.ok) EXP.invInfo={customer:j.customer||r.client||'',reference:j.reference||r.reference||'',subject:j.subject||'',loading:false};
      else EXP.invInfo={customer:r.client||'',reference:r.reference||'',subject:'',loading:false};
      render();
    }).catch(()=>{ EXP.invInfo={customer:r.client||'',reference:r.reference||'',subject:'',loading:false}; render(); });
  }
}
function expPickInvoice(num){ EXP.invoice=num; EXP.invSearch=''; EXP.invInfo={customer:'',reference:'',subject:'',loading:false}; render(); }
function expPickTyped(){ const q=String(EXP.invSearch||'').trim(); if(q){ EXP.invoice=q; EXP.invSearch=''; EXP.invInfo={customer:'',reference:'',subject:'',loading:false}; render(); } }
function expPickFirst(){ const r=expCombinedResults(); if(r.length){ window._expResults=r; expPickIdx(0); } else { expPickTyped(); } }
function expLoadAccounts(){
  if(EXP.accounts || EXP.loadingAcc) return;
  EXP.loadingAcc=true;
  fetch('api/accounts.php',{credentials:'same-origin'}).then(r=>r.json()).then(j=>{
    EXP.loadingAcc=false;
    if(j.ok){ EXP.accounts={expense:j.expense||[],paid:j.paid||[]}; const d=j.defaults||{}; if(!EXP.acc&&d.account_id)EXP.acc=d.account_id; if(!EXP.paid&&d.paid_through_account_id)EXP.paid=d.paid_through_account_id; }
    else { EXP.msg='Accounts: '+(j.error||'failed'); EXP.err=true; }
    if(TAB==='report') render();
  }).catch(e=>{ EXP.loadingAcc=false; EXP.msg='Accounts: '+e; EXP.err=true; if(TAB==='report') render(); });
}
async function rowPushCost(num){
  const el=document.getElementById('rc_'+num); const st=document.getElementById('rcS_'+num);
  const amt=parseFloat(el?el.value:0)||0;
  if(amt<=0){ if(st){st.style.color='var(--bad)';st.textContent='enter cost';} return; }
  if(!EXP.accounts){ expLoadAccounts(); }
  if(!EXP.acc){ if(st){st.style.color='var(--bad)';st.textContent='set account ↓';} EXP.open=true; expLoadAccounts(); EXP.msg='Pick an expense account once in this card — it is then remembered for the row Push buttons.'; EXP.err=true; render(); return; }
  if(st){ st.style.color='var(--mute)'; st.textContent='pushing…'; }
  try{
    const r=await fetch('api/expense_add.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},
      body:JSON.stringify({invoice_number:num, amount:amt, account_id:EXP.acc, paid_through_account_id:EXP.paid, date:today(), description:'Cost for '+num})});
    const j=await r.json();
    if(j.ok){ if(st){st.style.color='var(--good)';st.textContent='pushed ✓ refreshing…';} loadReport(true); }
    else { if(st){st.style.color='var(--bad)';st.textContent=(j.error||'failed').slice(0,40);} }
  }catch(e){ if(st){st.style.color='var(--bad)';st.textContent='error';} }
}
async function expAdd(){
  if(!EXP.invoice){ EXP.msg='Pick an invoice.'; EXP.err=true; render(); return; }
  const amt=parseFloat(EXP.amount)||0; if(amt<=0){ EXP.msg='Enter an amount greater than zero.'; EXP.err=true; render(); return; }
  if(!EXP.acc){ EXP.msg='Choose an expense account.'; EXP.err=true; render(); return; }
  EXP.msg='Saving to Zoho…'; EXP.err=false; render();
  try{
    const r=await fetch('api/expense_add.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},
      body:JSON.stringify({invoice_number:EXP.invoice, amount:amt, account_id:EXP.acc, paid_through_account_id:EXP.paid, date:EXP.date||today(), description:EXP.desc})});
    const j=await r.json();
    if(j.ok){ EXP.msg='Cost added to '+EXP.invoice+' ✓ — refreshing report…'; EXP.err=false; EXP.amount=''; EXP.desc=''; render(); loadReport(true); }
    else { EXP.msg='Error: '+(j.error||'failed'); EXP.err=true; render(); }
  }catch(e){ EXP.msg='Error: '+e; EXP.err=true; render(); }
}

async function loadReport(force){
  REPORT.loading = true; REPORT.error = null; render();
  const months = (window._rptMonths||[]).join(','), y = window._rptYear||'';
  try {
    const j = await api('api/report.php?months='+encodeURIComponent(months)+'&year='+encodeURIComponent(y)+(force?'&refresh=1':''));
    if(j.ok){ REPORT.rows = j.rows; REPORT.truncated = j.truncated; REPORT.generatedAt = j.generatedAt; REPORT.cached = j.cached; REPORT.fx = j.fx; REPORT.loaded = true; }
    else { REPORT.error = j.error || 'Failed'; }
  } catch(e){ REPORT.error = String(e); }
  REPORT.loading = false; render();
}

function reportFiltered(){
  const sf = window._rptStatus||'';
  const q = (window._rptSearch||'').trim().toLowerCase();
  const sel = window._rptClients||[];
  let list = REPORT.rows||[];
  if(sf==='paid') list = list.filter(x=>x.status==='paid');
  else if(sf==='unpaid') list = list.filter(x=>x.status!=='paid');
  if(sel.length) list = list.filter(x=> sel.includes(x.client));      // multi-client pick
  else if(q) list = list.filter(x=>(x.client||'').toLowerCase().includes(q)); // else free text
  return list;
}

function rptClientList(){
  const set = {}; (REPORT.rows||[]).forEach(x=>{ if(x.client) set[x.client]=1; });
  return Object.keys(set).sort((a,b)=>a.localeCompare(b));
}
function rptToggleClientIdx(i){
  const c = (window._rptShownClients||[])[i]; if(c==null) return;
  window._rptClients = window._rptClients || [];
  const j = window._rptClients.indexOf(c);
  if(j>=0) window._rptClients.splice(j,1); else window._rptClients.push(c);
  render();
}
function rptClearClients(){ window._rptClients=[]; render(); }
function rptSelectAllShown(){ window._rptClients = (window._rptShownClients||[]).slice(); render(); }

function rptToggleMonth(m){
  window._rptMonths = window._rptMonths || [];
  const i = window._rptMonths.indexOf(m);
  if(i>=0) window._rptMonths.splice(i,1); else window._rptMonths.push(m);
  window._rptMonths.sort((a,b)=>a-b);
  render();
}
function rptAllMonths(){ window._rptMonths=[]; render(); }
function rptSearch(v){ window._rptSearch=v; const el=document.getElementById('rptBody'); if(el) el.innerHTML=rptBodyHtml(); }
function vReport(){
  const now = new Date();
  if(window._rptYear===undefined) window._rptYear = String(now.getFullYear());
  if(window._rptMonths===null) window._rptMonths = [now.getMonth()+1]; // default: current month

  const thisYear = now.getFullYear(); const years=[]; for(let y=thisYear; y>=thisYear-4; y--) years.push(String(y));
  const yy = window._rptYear||'';
  const yearOpts = ['<option value="">All years</option>'].concat(years.map(y=>`<option value="${y}" ${y===yy?'selected':''}>${y}</option>`)).join('');
  const statuses=[['','All statuses'],['paid','Paid only'],['unpaid','Unpaid only']];
  const ss = window._rptStatus||'';
  const statusOpts = statuses.map(s=>`<option value="${s[0]}" ${s[0]===ss?'selected':''}>${s[1]}</option>`).join('');

  const mShort=['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
  const monthChips =
    `<button class="btn${(window._rptMonths||[]).length===0?'':' sec'}" style="width:auto;flex:0 0 auto;padding:6px 11px;font-size:11px" onclick="rptAllMonths()">All</button>`
    + mShort.map((nm,idx)=>{ const m=idx+1; const on=(window._rptMonths||[]).includes(m);
        return `<button class="btn${on?'':' sec'}" style="width:auto;flex:0 0 auto;padding:6px 10px;font-size:11px" onclick="rptToggleMonth(${m})">${nm}</button>`;
      }).join('');

  const filters = `
    <h2 style="margin:6px 0 12px">Profit Per Invoice Report</h2>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <select onchange="window._rptYear=this.value" style="flex:1 1 110px;margin-bottom:10px">${yearOpts}</select>
      <select onchange="window._rptStatus=this.value;render()" style="flex:1 1 130px;margin-bottom:10px">${statusOpts}</select>
    </div>
    <label>Months <span class="muted" style="font-weight:400">(tap to combine, then Load)</span></label>
    <div style="display:flex;flex-wrap:wrap;gap:6px;margin:4px 0 10px">${monthChips}</div>
    <div class="grid2" style="gap:8px;margin-bottom:8px">
      <button class="btn" onclick="loadReport()">Load report</button>
      <button class="btn sec" onclick="loadReport(true)">Refresh from Zoho</button>
    </div>
    <input type="text" placeholder="Search clients to pick (or filter)…" value="${window._rptSearch||''}" oninput="rptSearch(this.value)" style="margin-bottom:10px">
    <div class="muted" style="font-size:11px;margin:-4px 2px 8px">Tip: tap client names below to report on several at once. With none picked, the search filters the table by client.</div>`;

  return filters + `<div id="rptBody">${rptBodyHtml()}</div>`;
}

function rptBodyHtml(){
  if(REPORT.loading) return `<div class="card muted" style="text-align:center">Pulling invoices and expenses from Zoho…</div>`;
  if(REPORT.error)   return `<div class="card" style="color:var(--bad)">Error: ${REPORT.error}</div>`;
  if(!REPORT.loaded) return `<div class="card muted" style="text-align:center">Pick your months and press Load report.</div>`;

  const list = reportFiltered();
  const t = list.reduce((a,x)=>{ a.rev+=x.revenueExVat||0; a.vat+=x.vat||0; a.cost+=x.cost||0; a.profit+=x.profit||0; a.total+=x.total||0; return a; }, {rev:0,vat:0,cost:0,profit:0,total:0});
  const q = (window._rptSearch||'').trim();

  // ---- multi-client picker ----
  const clients = rptClientList();
  const qx = q.toLowerCase();
  const shown = qx ? clients.filter(c=>c.toLowerCase().includes(qx)) : clients;
  window._rptShownClients = shown;
  const sel = window._rptClients||[];
  const escc = s=>String(s==null?'':s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
  const picker = clients.length? `
    <div class="row" style="margin:8px 2px 4px">
      <span class="muted" style="font-size:11px">Clients · ${sel.length?('<b>'+sel.length+' selected</b>'):'all'}</span>
      <span>${shown.length?`<a onclick="rptSelectAllShown()" style="color:var(--blue);cursor:pointer;font-size:11px">select shown</a>`:''}${sel.length?` · <a onclick="rptClearClients()" style="color:var(--blue);cursor:pointer;font-size:11px">clear</a>`:''}</span>
    </div>
    <div style="display:flex;flex-wrap:wrap;gap:6px;max-height:140px;overflow:auto;border:1px solid var(--line);border-radius:10px;padding:8px;margin-bottom:10px">
      ${shown.map((c,i)=>{const on=sel.includes(c);return `<button class="btn${on?'':' sec'}" style="width:auto;flex:0 0 auto;padding:5px 10px;font-size:11px" onclick="rptToggleClientIdx(${i})">${escc(c)}</button>`;}).join('') || '<span class="muted" style="font-size:11px">No clients match your search.</span>'}
    </div>` : '';

  // ---- admin: add a manual cost to an invoice (writes a Zoho expense) ----
  let costForm = '';
  if(ME.admin){
    if(!EXP.accounts && !EXP.loadingAcc) expLoadAccounts(); // so row "Push" has an expense account ready
    const invOpts = (REPORT.rows||[]).slice().sort((a,b)=>(a.client||'').localeCompare(b.client||''))
      .map(x=>`<option value="${escc(x.number)}" ${EXP.invoice===x.number?'selected':''}>${escc(x.number)} · ${escc(x.client||'')}</option>`).join('');
    const accSel = EXP.accounts
      ? `<label>Expense account</label>
         <select onchange="EXP.acc=this.value"><option value="">Choose…</option>${EXP.accounts.expense.map(a=>`<option value="${a.id}" ${EXP.acc===a.id?'selected':''}>${escc(a.name)}</option>`).join('')}</select>
         <label>Paid through (optional)</label>
         <select onchange="EXP.paid=this.value"><option value="">—</option>${EXP.accounts.paid.map(a=>`<option value="${a.id}" ${EXP.paid===a.id?'selected':''}>${escc(a.name)}</option>`).join('')}</select>`
      : `<div class="muted" style="font-size:11px">${EXP.loadingAcc?'Loading accounts…':'Accounts not loaded.'} <a onclick="expLoadAccounts()" style="color:var(--blue);cursor:pointer">load accounts</a></div>`;
    costForm = `<div class="card" style="border-left:4px solid var(--orange)">
      <div class="row" style="margin-bottom:8px"><b style="font-size:13px">➕ Add a cost to an invoice</b>
        <button class="btn sec" style="width:auto;padding:4px 10px;font-size:11px" onclick="EXP.open=!EXP.open;if(EXP.open)expLoadAccounts();render()">${EXP.open?'Hide':'Open'}</button></div>
      ${EXP.open?`
      <div class="muted" style="font-size:11px;margin-bottom:8px">Writes a real expense to Zoho Books against the invoice number, so it counts as that invoice's cost and profit recalculates.</div>
      <label>Invoice (search by number)</label>
      ${EXP.invoice?(function(){const i=EXP.invInfo||{};const subj=i.reference||i.subject||'';return `<div style="background:#F4F6FA;border:1px solid var(--line);border-radius:10px;padding:9px 11px;margin-bottom:6px">
        <div class="row"><span style="font-size:12.5px"><b>${escc(EXP.invoice)}</b></span><button class="btn sec" style="width:auto;padding:4px 10px;font-size:11px" onclick="EXP.invoice='';EXP.invSearch='';EXP.invInfo=null;render()">change</button></div>
        <div style="font-size:12px;margin-top:3px">${i.customer?('👤 '+escc(i.customer)):(i.loading?'<span class="muted">loading…</span>':'')}</div>
        ${subj?`<div class="muted" style="font-size:11px;margin-top:2px">📝 ${escc(subj)}</div>`:''}
      </div>`;})():`
      <input type="text" id="expInvSearch" value="${escc(EXP.invSearch||'')}" oninput="expFilterInvoices(this.value)" onkeydown="if(event.key==='Enter'){event.preventDefault();expPickFirst();}" placeholder="Type an invoice number…" autocomplete="off">
      <div id="expInvResults">${expInvResultsHtml()}</div>`}
      <label>Cost amount (${CFG.cur})</label>
      <input type="number" step="100" value="${escc(EXP.amount)}" oninput="EXP.amount=this.value" placeholder="e.g. 12000">
      ${accSel}
      <label>Date</label>
      <input type="date" value="${EXP.date||today()}" oninput="EXP.date=this.value">
      <label>Description (optional)</label>
      <input type="text" value="${escc(EXP.desc)}" oninput="EXP.desc=this.value" placeholder="What was this cost for?">
      <button class="btn" style="margin-top:10px" onclick="expAdd()">Add cost to Zoho</button>
      ${EXP.msg?`<div class="${EXP.err?'warn':'ok'}" style="margin-top:8px;font-size:12px">${EXP.msg}</div>`:''}
      `:''}
    </div>`;
  }

  return costForm + picker + `
    <div class="row" style="margin:0 2px 6px">
      <span class="muted">${list.length} invoice${list.length===1?'':'s'} · ${CFG.cur}</span>
      <span class="muted" style="font-size:10px">Data as of ${REPORT.generatedAt? new Date(REPORT.generatedAt).toLocaleString('en-KE') : 'now'}${REPORT.cached?' (cached)':''}</span>
    </div>
    ${REPORT.truncated? `<div class="muted" style="margin:-2px 2px 10px">Showing first 150 invoices for VAT accuracy — narrow to fewer months for the full set.</div>`:''}
    ${REPORT.fx? `<div class="muted" style="margin:-2px 2px 10px;font-size:11px">USD invoices converted to KES at <b>${fmtn(REPORT.fx.rate)}</b>/USD${REPORT.fx.src&&REPORT.fx.src!=='fallback'?(' · '+REPORT.fx.src):' · fallback rate'}${REPORT.fx.asOf?(' · as of '+new Date(REPORT.fx.asOf).toLocaleDateString('en-KE')):''}</div>`:''}
    ${list.length? `
    <div class="rptwrap">
      <table class="rpt">
        <thead><tr>
          <th class="l">Client Name</th><th class="l">Invoice Number</th><th class="l">Date</th>
          <th>Amount Minus VAT</th><th>Exact VAT Amount</th><th>Amount Plus VAT</th>
          <th>Total Cost</th><th>Total Profit</th>
        </tr></thead>
        <tbody>
          ${list.map(x=>{const usd=x.currency==='USD';const g='#1a7f37';return `<tr${usd?' style="background:#eafbf0"':''}>
            <td class="l cl">${x.client||''}</td>
            <td class="l"${usd?` style="color:${g};font-weight:700"`:''}>${usd?'$ ':''}${x.invoiceNumber||''}</td>
            <td class="l">${x.date||''}</td>
            <td>${fmtn(x.revenueExVat)}${usd?`<div style="font-size:9px;color:${g};font-weight:700">$${fmtn(x.origRevenueExVat)}</div>`:''}</td>
            <td class="vat">${fmtn(x.vat)}${usd?`<div style="font-size:9px;color:${g};font-weight:700">$${fmtn(x.origVat)}</div>`:''}</td>
            <td>${fmtn(x.total)}${usd?`<div style="font-size:9px;color:${g};font-weight:700">$${fmtn(x.origTotal)}</div>`:''}</td>
            <td>${ME.admin
              ? `<div style="display:flex;gap:3px;align-items:center;justify-content:flex-end">
                   <input id="rc_${x.invoiceNumber}" type="number" step="100" value="${x.hasCost?x.cost:''}" placeholder="cost" style="width:64px;padding:3px 5px;font-size:10px;text-align:right;border:1px solid #CBD5E1;border-radius:6px">
                   <button onclick="rowPushCost('${String(x.invoiceNumber).replace(/'/g,'')}')" title="Push cost to Zoho" style="border:1px solid var(--orange);background:var(--orange);color:#fff;border-radius:6px;padding:3px 7px;font-size:9px;font-weight:700;cursor:pointer">Push</button>
                 </div><div id="rcS_${x.invoiceNumber}" style="font-size:8px;text-align:right;margin-top:1px"></div>`
              : (x.hasCost?fmtn(x.cost):'—')}</td>
            <td class="${x.profit>=0?'pos':'neg'}">${fmtn(x.profit)}</td>
          </tr>`;}).join('')}
          <tr class="tot">
            <td class="l">TOTAL</td><td></td><td></td>
            <td>${fmtn(t.rev)}</td><td>${fmtn(t.vat)}</td><td>${fmtn(t.total)}</td>
            <td>${fmtn(t.cost)}</td><td>${fmtn(t.profit)}</td>
          </tr>
        </tbody>
      </table>
    </div>`
    : `<div class="card muted" style="text-align:center">${q?('No client matches "'+q+'".'):'No KES invoices in this period.'}</div>`}

    <a onclick="exportReportCSV()" style="color:var(--blue);cursor:pointer;font-size:12px;display:block;text-align:center;margin-top:10px">Export this report as CSV</a>`;
}

function csvCell(v){ return '"'+String(v==null?'':v).replace(/"/g,'""')+'"'; }
function exportReportCSV(){
  const list = reportFiltered();
  const head = ['Invoice','Client','Date','Status','Currency','FX Rate','USD Total','USD Revenue ExVAT','USD VAT','Invoice Total (KES)','Revenue ExVAT (KES)','VAT (KES)','Cost (KES)','Profit (KES)'];
  const lines = [head.join(',')];
  list.forEach(x=>{
    const usd = x.currency==='USD';
    lines.push([
      csvCell(x.invoiceNumber), csvCell(x.client), csvCell(x.date), csvCell(x.status),
      csvCell(x.currency||'KES'), usd?(x.fxRate||''):'',
      usd?Math.round(x.origTotal||0):'', usd?Math.round(x.origRevenueExVat||0):'', usd?Math.round(x.origVat||0):'',
      Math.round(x.total||0), Math.round(x.revenueExVat||0), Math.round(x.vat||0),
      Math.round(x.cost||0), Math.round(x.profit||0)
    ].join(','));
  });
  const tag = (window._rptYear||'all') + ((window._rptMonths||[]).length?('-'+window._rptMonths.join('_')):'');
  const blob = new Blob([lines.join('\n')], {type:'text/csv'});
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob); a.download = 'profit_report_'+tag+'.csv'; a.click();
}


/* ---------- tabs ---------- */
/* ================= ETR Check tab ================= */
let ETR = { year:2026, months:[], status:'unpaid', view:'missing', search:'', loaded:false, loading:false, data:null };
let DUP = { loaded:false, loading:false, data:null, msg:'', preview:{} };
function etrDupsLoad(force){
  DUP.loading=true; const el=document.getElementById('etrDupBody'); if(el) el.innerHTML=etrDupBodyHtml();
  fetch('api/etr_duplicates.php'+(force?'?refresh=1':''),{credentials:'same-origin'}).then(r=>r.json()).then(j=>{
    DUP.loading=false; DUP.loaded=true; DUP.data=j; DUP.msg=(j&&j.ok)?'':(j.error||'Failed.'); render();
  }).catch(e=>{ DUP.loading=false; DUP.loaded=true; DUP.msg=''+e; render(); });
}
function etrDupPreview(id){ DUP.preview[id]=!DUP.preview[id]; const el=document.getElementById('etrDupBody'); if(el) el.innerHTML=etrDupBodyHtml(); }
function etrDupBodyHtml(){
  const esc=s=>String(s==null?'':s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  if(DUP.loading) return '<div class="muted" style="margin-top:8px;font-size:12px">Scanning the WorkDrive folder… this can take a moment.</div>';
  if(!DUP.loaded) return '<div class="muted" style="margin-top:8px;font-size:12px">Scans every file in the scanned folder and flags any invoice number that appears on more than one file.</div>';
  if(DUP.data && DUP.data.ok===false) return `<div class="warn" style="margin-top:8px">${esc(DUP.msg||'Error')}</div>`;
  const dups=(DUP.data&&DUP.data.duplicates)||[];
  const meta=`<div class="muted" style="font-size:11px;margin:6px 0">${DUP.data.filesScanned} files scanned · ${dups.length} duplicate number${dups.length===1?'':'s'}${DUP.data.cached?' · cached (Rescan to refresh)':''}</div>`;
  if(!dups.length) return meta+'<div class="ok">No duplicate invoice numbers found. 🎉</div>';
  const blocks=dups.map(g=>{
    const files=g.files.map(f=>{
      const ext=(String(f.name).split('.').pop()||'').toLowerCase();
      const isImg=['jpg','jpeg','png','gif','webp','bmp'].includes(ext);
      const isPdf=ext==='pdf';
      const pid=String(f.id||f.name).replace(/'/g,'');
      const proxy='api/etr_file.php?id='+encodeURIComponent(f.id||'');
      const prevBtn = f.id ? `<button class="btn sec" style="width:auto;padding:3px 8px;font-size:10px" onclick="etrDupPreview('${pid}')">${DUP.preview[pid]?'Hide':'Preview'}</button>` : '';
      const openBtn = f.id ? `<a class="btn sec" style="width:auto;padding:3px 8px;font-size:10px;text-decoration:none" href="${proxy}" target="_blank" rel="noopener">Open ↗</a>`
                           : (f.link?`<a class="btn sec" style="width:auto;padding:3px 8px;font-size:10px;text-decoration:none" href="${esc(f.link)}" target="_blank" rel="noopener">Open ↗</a>`:'');
      let prev='';
      if(DUP.preview[pid] && f.id){
        if(isImg) prev=`<div style="margin-top:6px"><img src="${proxy}" style="max-width:100%;border:1px solid var(--line);border-radius:8px" alt="preview"></div>`;
        else if(isPdf) prev=`<div style="margin-top:6px"><iframe src="${proxy}" style="width:100%;height:480px;border:1px solid var(--line);border-radius:8px"></iframe></div>`;
        else prev=`<div class="muted" style="font-size:11px;margin-top:6px">No inline preview for .${esc(ext)} files — use Open ↗.</div>`;
      }
      return `<div style="padding:7px 0;border-top:1px solid var(--line)">
        <div class="row" style="gap:8px"><span style="font-size:12px;word-break:break-all;flex:1">${esc(f.name)}</span>
          <span style="display:flex;gap:6px;flex:0 0 auto">${prevBtn}${openBtn}</span></div>${prev}</div>`;
    }).join('');
    return `<div class="card" style="border-left:4px solid var(--bad);margin-top:10px">
      <b style="font-size:13px;color:var(--bad)">Invoice ${esc(g.number)} — ${g.count} files</b>${files}</div>`;
  }).join('');
  return meta+blocks;
}

function etrYears(){
  const now = new Date().getFullYear(); let o='';
  for(let y=2026; y<=Math.max(now,2026); y++) o+=`<option value="${y}"${y===ETR.year?' selected':''}>${y}</option>`;
  return o;
}

function vETR(){
  ETR.months = ETR.months || [];
  const mShort=['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
  const monthChips =
    `<button class="btn${ETR.months.length===0?'':' sec'}" style="width:auto;flex:0 0 auto;padding:6px 11px;font-size:11px" onclick="etrAllMonths()">All</button>`
    + mShort.map((nm,idx)=>{ const m=idx+1; const on=ETR.months.includes(m);
        return `<button class="btn${on?'':' sec'}" style="width:auto;flex:0 0 auto;padding:6px 10px;font-size:11px" onclick="etrToggleMonth(${m})">${nm}</button>`;
      }).join('');
  const qs=`year=${ETR.year}&months=${ETR.months.join(',')}&status=${ETR.status}`;
  const viewLabel = ETR.view==='matched' ? 'has file' : (ETR.view==='excluded' ? 'no-ETR' : 'missing');

  let banner='';
  if(ETR.loading){ banner=`<div class="card muted">Scanning WorkDrive folder…</div>`; }
  else if(ETR.data && ETR.data.ok===false){ banner=`<div class="warn">${ETR.data.error||'Error'}</div>`; }
  else if(ETR.data){
    const d=ETR.data;
    const line=`<b>${d.missingCount}</b> with no file &nbsp;·&nbsp; <b>${d.matchedCount}</b> with a file &nbsp;·&nbsp; <b>${d.excludedCount||0}</b> marked no-ETR &nbsp;·&nbsp; ${d.filesScanned} files scanned`;
    const cls = (ETR.view==='missing') ? (d.missingCount>0?'warn':'ok') : 'ok';
    banner = `<div class="${cls}">${line}</div>`
           + `<div class="muted" style="margin:-4px 0 10px">Data as of ${new Date(d.generatedAt).toLocaleString()}${d.cached?' (cached)':''}</div>`;
  }

  // table is rendered (and live-filtered by client) via etrBodyHtml()

  return `
  <div class="card">
    <div class="grid2">
      <div><label>Year</label><select onchange="ETR.year=parseInt(this.value)">${etrYears()}</select></div>
      <div><label>Status</label><select onchange="ETR.status=this.value">
        <option value="unpaid"${ETR.status==='unpaid'?' selected':''}>Unpaid</option>
        <option value="paid"${ETR.status==='paid'?' selected':''}>Paid</option>
        <option value="all"${ETR.status==='all'?' selected':''}>All</option>
      </select></div>
    </div>
    <label>Months <span class="muted" style="font-weight:400">(tap to combine)</span></label>
    <div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:12px">${monthChips}</div>
    <div class="grid3" style="gap:8px;margin-bottom:10px">
      <button class="btn${ETR.view==='missing'?'':' sec'}" onclick="etrView('missing')">Missing file</button>
      <button class="btn${ETR.view==='matched'?'':' sec'}" onclick="etrView('matched')">Has file</button>
      <button class="btn${ETR.view==='excluded'?'':' sec'}" onclick="etrView('excluded')">No-ETR</button>
    </div>
    <div class="grid2" style="gap:8px">
      <button class="btn" onclick="etrLoad()">Check</button>
      <button class="btn sec" onclick="etrLoad(true)">Refresh from Zoho</button>
    </div>
    <input type="text" placeholder="Search client name…" value="${ETR.search||''}" oninput="etrFilter(this.value)" style="margin-top:8px">
    <a class="btn sec" style="display:block;text-align:center;margin-top:4px;text-decoration:none" href="api/etr_report.php?${qs}&view=${ETR.view}&format=csv">Export CSV (${viewLabel})</a>
  </div>
  ${banner}
  <div id="etrBody">${etrBodyHtml()}</div>

  <div class="card" style="margin-top:14px">
    <div class="row"><b style="font-size:13px">🔁 Duplicate invoice files in the scanned folder</b>
      <button class="btn sec" style="width:auto;padding:5px 11px;font-size:11px" onclick="etrDupsLoad(${DUP.loaded?'true':'false'})">${DUP.loading?'Scanning…':(DUP.loaded?'Rescan':'Scan for duplicates')}</button></div>
    <div id="etrDupBody">${etrDupBodyHtml()}</div>
  </div>`;
}

function etrBodyHtml(){
  if(!(ETR.data && ETR.data.ok!==false)) return '';
  const q=(ETR.search||'').trim().toLowerCase();
  const filt = arr => q ? (arr||[]).filter(r=>(r.client||'').toLowerCase().includes(q)) : (arr||[]);
  const noHit = `<div class="card muted" style="text-align:center">No client matches "${ETR.search}".</div>`;

  if(ETR.view==='matched'){
    const list=filt(ETR.data.matched);
    if(!list.length) return ETR.loading?'':(q?noHit:`<div class="warn">No invoices in this period have a matching file.</div>`);
    const rows=list.map(r=>`<tr>
      <td class="l cl">${r.client||''}</td>
      <td class="l" style="font-weight:700">${r.invoice_number||''}</td>
      <td class="l" style="white-space:normal;min-width:150px">${r.file||''}</td>
      <td class="l">${r.date||''}</td>
      <td class="l">${r.status||''}</td>
      <td>${fmt(r.total)}</td>
      <td>${fmt(r.balance)}</td></tr>`).join('');
    return `<div class="rptwrap"><table class="rpt" style="font-size:9px"><thead><tr>
      <th class="l">Client</th><th class="l">Invoice #</th><th class="l">Matching File</th>
      <th class="l">Raised</th><th class="l">Status</th><th>Total</th><th>Balance</th></tr></thead>
      <tbody>${rows}</tbody></table></div>`;
  }
  if(ETR.view==='excluded'){
    const list=filt(ETR.data.excluded);
    if(!list.length) return ETR.loading?'':(q?noHit:`<div class="ok">Nothing marked as no-ETR yet.</div>`);
    const rows=list.map(r=>`<tr>
      <td class="l cl">${r.client||''}</td>
      <td class="l">${r.invoice_number||''}</td>
      <td class="l">${r.date||''}</td>
      <td class="l">${r.status||''}</td>
      <td>${fmt(r.total)}</td>
      <td class="l"><a onclick="etrUnmark('${(r.invoice_number||'').replace(/'/g,'')}')" style="color:var(--blue);cursor:pointer">↶ undo</a></td></tr>`).join('');
    return `<div class="rptwrap"><table class="rpt" style="font-size:9px"><thead><tr>
      <th class="l">Client</th><th class="l">Invoice #</th><th class="l">Raised</th>
      <th class="l">Status</th><th>Total</th><th class="l">Action</th></tr></thead>
      <tbody>${rows}</tbody></table></div>`;
  }
  const list=filt(ETR.data.missing);
  if(!list.length) return ETR.loading?'':(q?noHit:`<div class="ok">Every KES invoice in this period has a file.</div>`);
  const rows=list.map(r=>`<tr>
    <td class="l cl">${r.client||''}</td>
    <td class="l">${r.invoice_number||''}</td>
    <td class="l">${r.date||''}</td>
    <td class="l">${r.status||''}</td>
    <td>${fmt(r.total)}</td>
    <td>${fmt(r.balance)}</td>
    <td class="l"><a onclick="etrMark('${(r.invoice_number||'').replace(/'/g,'')}','${(r.client||'').replace(/'/g,'')}')" style="color:var(--bad);cursor:pointer;white-space:nowrap">✕ no ETR</a></td></tr>`).join('');
  return `<div class="rptwrap"><table class="rpt" style="font-size:9px"><thead><tr>
    <th class="l">Client</th><th class="l">Invoice #</th><th class="l">Raised</th>
    <th class="l">Status</th><th>Total</th><th>Balance</th><th class="l">Mark</th></tr></thead>
    <tbody>${rows}</tbody></table></div>`;
}

function etrFilter(v){
  ETR.search=v;
  const el=document.getElementById('etrBody');
  if(el) el.innerHTML=etrBodyHtml();
}

function etrView(v){ ETR.view=v; render(); }

function etrMark(num, client){
  if(!num) return;
  fetch('api/etr_exclude.php?action=add',{method:'POST',credentials:'same-origin',
    headers:{'Content-Type':'application/json'},
    body:JSON.stringify({invoice_number:num, client:client||''})})
   .then(r=>r.json()).then(j=>{
     if(!j.ok){ alert(j.error||'Could not save'); return; }
     const d=ETR.data; const i=(d.missing||[]).findIndex(x=>x.invoice_number===num);
     if(i>=0){ const row=d.missing.splice(i,1)[0]; (d.excluded=d.excluded||[]).push(row);
       d.missingCount=d.missing.length; d.excludedCount=d.excluded.length; render(); }
   }).catch(e=>alert('Failed: '+e));
}

function etrUnmark(num){
  if(!num) return;
  fetch('api/etr_exclude.php?action=remove',{method:'POST',credentials:'same-origin',
    headers:{'Content-Type':'application/json'},
    body:JSON.stringify({invoice_number:num})})
   .then(r=>r.json()).then(j=>{
     if(!j.ok){ alert(j.error||'Could not remove'); return; }
     const d=ETR.data; const i=(d.excluded||[]).findIndex(x=>x.invoice_number===num);
     if(i>=0){ const row=d.excluded.splice(i,1)[0]; (d.missing=d.missing||[]).push(row);
       d.missing.sort((a,b)=>(parseInt((a.invoice_number||'').replace(/\D/g,''))||0)-(parseInt((b.invoice_number||'').replace(/\D/g,''))||0));
       d.missingCount=d.missing.length; d.excludedCount=d.excluded.length; render(); }
   }).catch(e=>alert('Failed: '+e));
}

function etrToggleMonth(m){
  ETR.months = ETR.months || [];
  const i = ETR.months.indexOf(m);
  if(i>=0) ETR.months.splice(i,1); else ETR.months.push(m);
  ETR.months.sort((a,b)=>a-b);
  render();
}
function etrAllMonths(){ ETR.months=[]; render(); }

function etrLoad(refresh){
  ETR.months = ETR.months || [];
  ETR.loading=true; if(refresh) ETR.data=null; render();
  const url=`api/etr_report.php?year=${ETR.year}&months=${ETR.months.join(',')}&status=${ETR.status}${refresh?'&refresh=1':''}`;
  fetch(url,{credentials:'same-origin'})
    .then(r=>r.json())
    .then(j=>{ ETR.loading=false; ETR.loaded=true; ETR.data=j; render(); })
    .catch(e=>{ ETR.loading=false; ETR.loaded=true; ETR.data={ok:false,error:'Request failed: '+e}; render(); });
}
/* ================= end ETR Check ================= */

/* ================= Invoices (draft / approved-not-sent) ================= */
let INVR = { year:String(Math.max(2026,new Date().getFullYear())), months:[], view:'draft', search:'', loaded:false, loading:false, data:null };

function invrYears(){
  const now=new Date().getFullYear(); let o='';
  for(let y=now;y>=2026;y--) o+=`<option value="${y}"${String(y)===INVR.year?' selected':''}>${y}</option>`;
  return o || `<option value="2026" selected>2026</option>`;
}
function invrToggleMonth(m){ const i=INVR.months.indexOf(m); if(i>=0) INVR.months.splice(i,1); else INVR.months.push(m); INVR.months.sort((a,b)=>a-b); render(); }
function invrAllMonths(){ INVR.months=[]; render(); }
function invrView(v){ INVR.view=v; render(); }
function invrSearch(v){ INVR.search=v; const el=document.getElementById('invrBody'); if(el) el.innerHTML=invrBodyHtml(); }

function vInvRep(){
  const mShort=['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
  const chips = `<button class="btn${INVR.months.length===0?'':' sec'}" style="width:auto;flex:0 0 auto;padding:6px 11px;font-size:11px" onclick="invrAllMonths()">All</button>`
    + mShort.map((nm,idx)=>{const m=idx+1;const on=INVR.months.includes(m);return `<button class="btn${on?'':' sec'}" style="width:auto;flex:0 0 auto;padding:6px 10px;font-size:11px" onclick="invrToggleMonth(${m})">${nm}</button>`;}).join('');
  const qs=`year=${INVR.year}&months=${INVR.months.join(',')}`;

  let banner='';
  if(INVR.loading) banner=`<div class="card muted">Loading invoices from Zoho…</div>`;
  else if(INVR.data && INVR.data.ok===false) banner=`<div class="warn">${INVR.data.error||'Error'}</div>`;
  else if(INVR.data) banner=`<div class="card"><b>${INVR.data.draftCount}</b> draft &nbsp;·&nbsp; <b>${INVR.data.approvedCount}</b> approved / not sent`
    + `<div class="muted" style="font-size:11px;margin-top:4px">Data as of ${new Date(INVR.data.generatedAt).toLocaleString()}${INVR.data.cached?' (cached)':''}</div></div>`;

  return `
  <h2 style="margin:6px 0 12px">Invoice Report — not yet sent</h2>
  <div class="card">
    <div class="grid2">
      <div><label>Year</label><select onchange="INVR.year=this.value">${invrYears()}</select></div>
      <div style="display:flex;align-items:flex-end"><button class="btn sec" style="margin-bottom:0" onclick="invrLoad(true)">Refresh from Zoho</button></div>
    </div>
    <label>Months <span class="muted" style="font-weight:400">(tap to combine, empty = whole year)</span></label>
    <div style="display:flex;flex-wrap:wrap;gap:6px;margin:4px 0 10px">${chips}</div>
    <div class="grid2" style="gap:8px;margin-bottom:8px">
      <button class="btn${INVR.view==='draft'?'':' sec'}" onclick="invrView('draft')">Draft</button>
      <button class="btn${INVR.view==='approved'?'':' sec'}" onclick="invrView('approved')">Approved · not sent</button>
    </div>
    <button class="btn" onclick="invrLoad()">Load</button>
    <input type="text" placeholder="Search client name…" value="${INVR.search||''}" oninput="invrSearch(this.value)" style="margin-top:8px">
    <a class="btn sec" style="display:block;text-align:center;margin-top:6px;text-decoration:none" href="api/invoice_status_report.php?${qs}&view=${INVR.view}&format=csv">Export CSV (${INVR.view})</a>
  </div>
  ${banner}
  <div id="invrBody">${invrBodyHtml()}</div>`;
}

function invrBodyHtml(){
  if(!(INVR.data && INVR.data.ok!==false)) return '';
  const q=(INVR.search||'').trim().toLowerCase();
  const all = INVR.view==='approved' ? (INVR.data.approved||[]) : (INVR.data.draft||[]);
  const list = q ? all.filter(r=>(r.client||'').toLowerCase().includes(q)) : all;
  if(!list.length) return INVR.loading?'' : `<div class="card muted" style="text-align:center">${q?('No client matches "'+q+'".'):(INVR.view==='approved'?'No approved-but-unsent invoices in this period.':'No draft invoices in this period.')}</div>`;
  const rows=list.map(r=>`<tr>
    <td class="l cl">${r.client||''}</td>
    <td class="l">${r.invoice_number||''}</td>
    <td class="l">${r.date||''}</td>
    <td class="l">${r.status||''}</td>
    <td class="l">${r.currency||''}</td>
    <td>${fmtn(r.total)}</td></tr>`).join('');
  return `<div class="rptwrap"><table class="rpt" style="font-size:10px"><thead><tr>
    <th class="l">Client</th><th class="l">Invoice #</th><th class="l">Raised</th>
    <th class="l">Status</th><th class="l">Cur</th><th>Total</th></tr></thead>
    <tbody>${rows}</tbody></table></div>`;
}

function invrLoad(refresh){
  INVR.loading=true; if(refresh) INVR.data=null; render();
  const url=`api/invoice_status_report.php?year=${INVR.year}&months=${INVR.months.join(',')}${refresh?'&refresh=1':''}`;
  fetch(url,{credentials:'same-origin'})
    .then(r=>r.json())
    .then(j=>{ INVR.loading=false; INVR.loaded=true; INVR.data=j; render(); })
    .catch(e=>{ INVR.loading=false; INVR.loaded=true; INVR.data={ok:false,error:'Request failed: '+e}; render(); });
}
/* ================= end Invoices ================= */

/* ================= Quotes (approved-not-sent / pending / draft estimates) ================= */
let QUOT = { year:String(Math.max(2026,new Date().getFullYear())), months:[], view:'approved', search:'', loaded:false, loading:false, data:null };

function quotYears(){
  const now=new Date().getFullYear(); let o='';
  for(let y=now;y>=2026;y--) o+=`<option value="${y}"${String(y)===QUOT.year?' selected':''}>${y}</option>`;
  return o || `<option value="2026" selected>2026</option>`;
}
function quotToggleMonth(m){ const i=QUOT.months.indexOf(m); if(i>=0) QUOT.months.splice(i,1); else QUOT.months.push(m); QUOT.months.sort((a,b)=>a-b); render(); }
function quotAllMonths(){ QUOT.months=[]; render(); }
function quotView(v){ QUOT.view=v; render(); }
function quotSearch(v){ QUOT.search=v; const el=document.getElementById('quotBody'); if(el) el.innerHTML=quotBodyHtml(); }

function vQuotes(){
  const mShort=['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
  const chips = `<button class="btn${QUOT.months.length===0?'':' sec'}" style="width:auto;flex:0 0 auto;padding:6px 11px;font-size:11px" onclick="quotAllMonths()">All</button>`
    + mShort.map((nm,idx)=>{const m=idx+1;const on=QUOT.months.includes(m);return `<button class="btn${on?'':' sec'}" style="width:auto;flex:0 0 auto;padding:6px 10px;font-size:11px" onclick="quotToggleMonth(${m})">${nm}</button>`;}).join('');
  const qs=`year=${QUOT.year}&months=${QUOT.months.join(',')}`;

  let banner='';
  if(QUOT.loading) banner=`<div class="card muted">Loading quotes from Zoho…</div>`;
  else if(QUOT.data && QUOT.data.ok===false) banner=`<div class="warn">${QUOT.data.error||'Error'}</div>`;
  else if(QUOT.data) banner=`<div class="card"><b>${QUOT.data.approvedCount}</b> approved / not sent &nbsp;·&nbsp; <b>${QUOT.data.pendingCount}</b> pending approval &nbsp;·&nbsp; <b>${QUOT.data.draftCount}</b> draft`
    + `<div class="muted" style="font-size:11px;margin-top:4px">Data as of ${new Date(QUOT.data.generatedAt).toLocaleString()}${QUOT.data.cached?' (cached)':''}</div></div>`;

  return `
  <h2>Quotes — not yet sent</h2>
  <div class="card">
    <div class="grid2">
      <div><label>Year</label><select onchange="QUOT.year=this.value">${quotYears()}</select></div>
      <div style="display:flex;align-items:flex-end"><button class="btn sec" style="margin-bottom:0" onclick="quotLoad(true)">Refresh from Zoho</button></div>
    </div>
    <label>Months <span class="muted" style="font-weight:400">(tap to combine, empty = whole year)</span></label>
    <div style="display:flex;flex-wrap:wrap;gap:6px;margin:4px 0 10px">${chips}</div>
    <div class="grid3" style="gap:8px;margin-bottom:8px">
      <button class="btn${QUOT.view==='approved'?'':' sec'}" onclick="quotView('approved')">Approved</button>
      <button class="btn${QUOT.view==='pending'?'':' sec'}" onclick="quotView('pending')">Pending</button>
      <button class="btn${QUOT.view==='draft'?'':' sec'}" onclick="quotView('draft')">Draft</button>
    </div>
    <button class="btn" onclick="quotLoad()">Load</button>
    <input type="text" placeholder="Search client name…" value="${QUOT.search||''}" oninput="quotSearch(this.value)" style="margin-top:8px">
    <a class="btn sec" style="display:block;text-align:center;margin-top:6px;text-decoration:none" href="api/quote_status_report.php?${qs}&view=${QUOT.view}&format=csv">Export CSV (${QUOT.view})</a>
  </div>
  ${banner}
  <div id="quotBody">${quotBodyHtml()}</div>`;
}

function quotBodyHtml(){
  if(!(QUOT.data && QUOT.data.ok!==false)) return '';
  const q=(QUOT.search||'').trim().toLowerCase();
  const all = QUOT.data[QUOT.view]||[];
  const list = q ? all.filter(r=>(r.client||'').toLowerCase().includes(q)) : all;
  const label = QUOT.view==='approved'?'approved-but-unsent':(QUOT.view==='pending'?'pending-approval':'draft');
  if(!list.length) return QUOT.loading?'' : `<div class="card muted" style="text-align:center">${q?('No client matches "'+q+'".'):('No '+label+' quotes in this period.')}</div>`;
  const rows=list.map(r=>`<tr>
    <td class="l cl">${r.client||''}</td>
    <td class="l">${r.number||''}</td>
    <td class="l">${r.date||''}</td>
    <td class="l">${r.status||''}</td>
    <td class="l">${r.currency||''}</td>
    <td>${fmtn(r.total)}</td></tr>`).join('');
  return `<div class="rptwrap"><table class="rpt" style="font-size:10px"><thead><tr>
    <th class="l">Client</th><th class="l">Quote #</th><th class="l">Raised</th>
    <th class="l">Status</th><th class="l">Cur</th><th>Total</th></tr></thead>
    <tbody>${rows}</tbody></table></div>`;
}

function quotLoad(refresh){
  QUOT.loading=true; if(refresh) QUOT.data=null; render();
  const url=`api/quote_status_report.php?year=${QUOT.year}&months=${QUOT.months.join(',')}${refresh?'&refresh=1':''}`;
  fetch(url,{credentials:'same-origin'})
    .then(r=>r.json())
    .then(j=>{ QUOT.loading=false; QUOT.loaded=true; QUOT.data=j; render(); })
    .catch(e=>{ QUOT.loading=false; QUOT.loaded=true; QUOT.data={ok:false,error:'Request failed: '+e}; render(); });
}
/* ================= end Quotes ================= */

/* ================= Settings ================= */
function vSettings(){
  const fund = CFG.fund, ratePct = +(CFG.rate*100).toFixed(2), vatPct = +((CFG.vat||0)*100).toFixed(2);
  const esc = s=>String(s==null?'':s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  const sec = (t)=>`<div style="font-weight:700;font-size:12.5px;letter-spacing:.3px;margin:2px 0 10px">${t}</div>`;
  return `
  <h2>Settings</h2>
  <div class="muted" style="margin:-6px 0 14px;font-size:12px">Everything here saves to <b>data/settings.json</b> and applies across the app instantly. <b>config.php is never touched.</b></div>

  <div class="card" style="border-left:4px solid var(--blue)">
    ${sec('📅 My Zoho Calendar')}
    <div class="muted" style="font-size:12px;margin-bottom:8px">Connect your own Zoho Calendar once. After that, tasks assigned to you (and to anyone else who connects) are written <b>straight into their calendar</b> — no email step. People who haven't connected still get an emailed invite.</div>
    <a class="btn" style="display:inline-block;width:auto;padding:9px 16px;text-decoration:none" href="connect_calendar.php" target="_blank" rel="noopener">Open calendar connect</a>
    ${ME.admin?`<div class="muted" style="font-size:11px;margin-top:8px">Each employee logs in and opens this once. As admin you can see who's connected in <b>Users</b> (a 📅 shows next to connected people).</div>`:''}
  </div>

  <div class="card">
    ${sec('💰 Money &amp; financing')}
    <label>Working capital fund (${CFG.cur})</label>
    <input id="setFund" type="number" step="1000" value="${fund}">
    <div class="muted" style="font-size:11px;margin:-4px 0 10px">Base fund. Drives the Dashboard "available", the Growth target, and capital-deployed figures.</div>

    <label>Bank interest — annual rate (%)</label>
    <input id="setRate" type="number" step="0.1" value="${ratePct}">
    <div class="muted" style="font-size:11px;margin:-4px 0 10px">Financing cost on each bridge = amount × (rate ÷ 365) × days.</div>

    <label>VAT rate — fallback (%)</label>
    <input id="setVat" type="number" step="0.1" value="${vatPct}">
    <div class="muted" style="font-size:11px;margin:-4px 0 10px">Only for old records without VAT data; live invoices use their real VAT from Zoho.</div>

    <label>USD → KES fallback rate</label>
    <input id="setUsd" type="number" step="0.5" value="${CFG.usd}">
    <div class="muted" style="font-size:11px;margin:-4px 0 0">Used when live FX lookup fails and an invoice carries no Zoho exchange rate.</div>
  </div>

  <div class="card">
    ${sec('📈 Growth')}
    <label>Growth target multiple (×)</label>
    <input id="setGrowth" type="number" step="0.5" value="${CFG.growth}">
    <div class="muted" style="font-size:11px;margin:-4px 0 0">The Growth tab aims your fund at this multiple (e.g. 2 = double the fund).</div>
  </div>

  <div class="card">
    ${sec('✉️ Statements &amp; email')}
    <label>Statement email subject</label>
    <input id="setStmtSubj" type="text" value="${esc(CFG.stmtSubject)}">
    <div class="muted" style="font-size:11px;margin:-4px 0 10px">Default subject when drafting client statements.</div>

    <label>Statement footer (fine print)</label>
    <textarea id="setStmtFoot" style="min-height:70px;font-size:12px">${esc(CFG.stmtFooter)}</textarea>
    <div class="muted" style="font-size:11px;margin:-4px 0 0">Italic line at the bottom of every emailed statement.</div>
  </div>

  <div class="card">
    ${sec('✅ Tasks &amp; inbox')}
    <label>Inbox lookback (days)</label>
    <input id="setInbox" type="number" step="1" value="${CFG.inboxDays}">
    <div class="muted" style="font-size:11px;margin:-4px 0 10px">How far back the To-Do email import scans your inbox.</div>

    <label>"Sent" hide window (days)</label>
    <input id="setSent" type="number" step="1" value="${CFG.sentDays}">
    <div class="muted" style="font-size:11px;margin:-4px 0 0">After you mark a client statement sent, they stay hidden from the Emails list this long.</div>
  </div>

  <div class="card">
    ${sec('🏢 Business')}
    <label>Business name</label>
    <input id="setBiz" type="text" value="${esc(CFG.biz)}">
    <div class="muted" style="font-size:11px;margin:-4px 0 0">Shown on statements and reports.</div>
  </div>

  <button class="btn" onclick="saveSettings()">Save all settings</button>
  ${window._setMsg?`<div class="${window._setMsgErr?'warn':'ok'}" style="margin-top:10px">${window._setMsg}</div>`:''}
  ${ME.admin? vUsers() : ''}`;
}

function saveSettings(){
  const g = id => document.getElementById(id);
  const payload = {
    fund:              parseFloat(g('setFund').value)||0,
    annual_rate:       (parseFloat(g('setRate').value)||0)/100,
    vat_rate:          (parseFloat(g('setVat').value)||0)/100,
    usd_rate:          parseFloat(g('setUsd').value)||128,
    growth_multiple:   parseFloat(g('setGrowth').value)||2,
    inbox_days:        parseInt(g('setInbox').value)||14,
    sent_hide_days:    parseInt(g('setSent').value)||30,
    statement_subject: g('setStmtSubj').value||'',
    statement_footer:  g('setStmtFoot').value||'',
    business_name:     g('setBiz').value||''
  };
  window._setMsg='Saving…'; window._setMsgErr=false; render();
  fetch('api/settings.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},
    body:JSON.stringify(payload)})
    .then(r=>r.json())
    .then(j=>{
      if(j.ok){
        CFG.fund=j.fund; CFG.rate=j.annual_rate; CFG.vat=j.vat_rate; CFG.usd=j.usd_rate;
        CFG.growth=j.growth_multiple; CFG.inboxDays=j.inbox_days; CFG.sentDays=j.sent_hide_days;
        CFG.stmtSubject=j.statement_subject; CFG.stmtFooter=j.statement_footer; CFG.biz=j.business_name;
        window._setMsg='Saved ✓ — applied across the app.'; window._setMsgErr=false;
      } else { window._setMsg='Error: '+(j.error||'failed'); window._setMsgErr=true; }
      render();
    })
    .catch(e=>{ window._setMsg='Error: '+e; window._setMsgErr=true; render(); });
}
/* ================= end Settings ================= */

/* ================= Emails (client statements -> Zoho Mail draft) ================= */
let EMAIL = { clients:[], loaded:false, loadingClients:false, clientId:'', data:null, loading:false,
              picked:{}, intro:'', subject:'', from:'', to:'', to_email:'', toEdited:false, book:[], newEmail:'',
              clientSearch:'', clientPicked:false, msg:'', msgErr:false, saving:false,
              bulkSel:{}, bulkProg:{}, bulkRunning:false, bulkDone:0, bulkTotal:0, bulkMsg:'', bulkErr:false };

function emailLoadClients(){
  EMAIL.loadingClients=true; render();
  fetch('api/email_clients.php',{credentials:'same-origin'}).then(r=>r.json()).then(j=>{
    EMAIL.loadingClients=false; EMAIL.loaded=true;
    EMAIL.clients = j.ok ? j.clients : [];
    if(!j.ok) EMAIL.msg='Could not load clients: '+(j.error||'');
    render();
  }).catch(e=>{ EMAIL.loadingClients=false; EMAIL.loaded=true; EMAIL.msg='Error: '+e; render(); });
}

function emailPickClient(id){
  EMAIL.clientId=id; EMAIL.data=null; EMAIL.picked={}; EMAIL.msg=''; EMAIL.from=''; EMAIL.to=''; EMAIL.to_email=''; EMAIL.toEdited=false;
  if(!id){ render(); return; }
  emailFetchStatement(id);
}
function emailRebuild(){ if(EMAIL.clientId) emailFetchStatement(EMAIL.clientId); }
function emailFetchStatement(id){
  EMAIL.loading=true; render();
  fetch('api/client_account.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},
    body:JSON.stringify({ customer_id:id, from:EMAIL.from||'', to:EMAIL.to||'' })})
  .then(r=>r.json()).then(j=>{
    EMAIL.loading=false;
    if(j.ok){
      EMAIL.data=j;
      EMAIL.from=j.period.from; EMAIL.to=j.period.to;
      if(!EMAIL.toEdited) EMAIL.to_email = j.client.email||'';
      EMAIL.subject=CFG.stmtSubject||'Pending invoices and Statement';
      EMAIL.intro='Dear '+(j.client.name||'Client')+',\n\nPlease find the unpaid invoices attached to this email, together with your statement of account below, for payment.';
      EMAIL.picked={}; (j.invoices||[]).forEach(iv=>{ EMAIL.picked[iv.number]= iv.unpaid===true; });
      emailLoadBook(id);
    } else { EMAIL.msg='Error: '+(j.error||'failed'); EMAIL.msgErr=true; }
    render();
  }).catch(e=>{ EMAIL.loading=false; EMAIL.msg='Error: '+e; EMAIL.msgErr=true; render(); });
}

function emailLoadBook(id){
  fetch('api/email_book.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},
    body:JSON.stringify({ customer_id:id })})
  .then(r=>r.json()).then(j=>{
    EMAIL.book = (j.ok && j.emails) ? j.emails : [];
    if(!EMAIL.toEdited && !EMAIL.to_email && EMAIL.book.length) EMAIL.to_email = EMAIL.book[0];
    render();
  }).catch(()=>{});
}
function emailAddEmail(){
  const em = (EMAIL.newEmail||'').trim() || (EMAIL.to_email||'').trim();
  if(!em){ EMAIL.msg='Type an email to save first.'; EMAIL.msgErr=true; render(); return; }
  fetch('api/email_book.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},
    body:JSON.stringify({ customer_id:EMAIL.clientId, add:em })})
  .then(r=>r.json()).then(j=>{
    if(j.ok){ EMAIL.book=j.emails||[]; EMAIL.newEmail=''; if(!EMAIL.to_email){ EMAIL.to_email=em; EMAIL.toEdited=true; } EMAIL.msg=''; }
    else { EMAIL.msg=j.error||'Could not save email.'; EMAIL.msgErr=true; }
    render();
  }).catch(e=>{ EMAIL.msg='Error: '+e; EMAIL.msgErr=true; render(); });
}
function emailRemoveEmail(em){
  fetch('api/email_book.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},
    body:JSON.stringify({ customer_id:EMAIL.clientId, remove:em })})
  .then(r=>r.json()).then(j=>{ if(j.ok){ EMAIL.book=j.emails||[]; render(); } }).catch(()=>{});
}
function emailUseEmail(em){ EMAIL.to_email=em; EMAIL.toEdited=true; render(); }

function emailToggle(num){ EMAIL.picked[num]=!EMAIL.picked[num]; const el=document.getElementById('emInvBody'); if(el) el.innerHTML=emInvHtml(); }
function emailField(k,v){ EMAIL[k]=v; }

function emInvHtml(){
  const d=EMAIL.data; if(!d) return '';
  const rows=(d.invoices||[]).map(iv=>{
    const on=!!EMAIL.picked[iv.number];
    const st = iv.unpaid ? `<span style="color:var(--bad);font-weight:600">${iv.status}</span>` : `<span style="color:var(--good)">paid</span>`;
    return `<tr>
      <td class="l"><input type="checkbox" ${on?'checked':''} onclick="emailToggle('${String(iv.number).replace(/'/g,"")}')"></td>
      <td class="l">${iv.number}${iv.desc?`<div class="muted" style="font-size:9.5px">${String(iv.desc).replace(/</g,'&lt;')}</div>`:''}</td>
      <td class="l">${iv.date||''}</td>
      <td class="l">${st}</td>
      <td>${fmt(iv.balance)}${iv.foreign?`<div style="color:var(--good);font-size:9px">$${fmtn(iv.origBalance)} USD</div>`:''}</td></tr>`;
  }).join('');
  return `<div class="rptwrap"><table class="rpt" style="font-size:10.5px"><thead><tr>
    <th class="l">Add</th><th class="l">Invoice</th><th class="l">Date</th><th class="l">Status</th><th>Balance</th>
    </tr></thead><tbody>${rows}</tbody></table></div>`;
}

function emailClientSearch(v){ EMAIL.clientSearch=v; EMAIL.clientPicked=false; const el=document.getElementById('emClientList'); if(el) el.innerHTML=emClientListHtml(); }
function emailSelectClient(id){
  const c=(EMAIL.clients||[]).find(x=>x.id===id);
  EMAIL.clientSearch=c?c.name:''; EMAIL.clientPicked=true;
  emailPickClient(id);
}
function emClientListHtml(){
  const q=(EMAIL.clientSearch||'').trim().toLowerCase();
  if(EMAIL.clientPicked || !q) return '';
  const matches=(EMAIL.clients||[]).filter(c=>
    (c.name||'').toLowerCase().includes(q) || (c.email||'').toLowerCase().includes(q)).slice(0,10);
  if(!matches.length) return `<div class="muted" style="padding:8px 4px">No client matches “${q}”.</div>`;
  return `<div style="border:1px solid var(--line);border-radius:10px;overflow:hidden;margin-bottom:12px">`
    + matches.map(c=>`<div class="invrow" onclick="emailSelectClient('${c.id}')">
        <div><div style="font-size:12.5px;font-weight:600">${c.name||'(no name)'}${c.unpaid?` <span style="color:#fff;background:var(--bad);font-size:9px;font-weight:700;padding:1px 6px;border-radius:10px;vertical-align:middle">UNPAID</span>`:''}</div>
        <div class="muted">${c.email||'no email on file'}</div></div></div>`).join('')
    + `</div>`;
}

function vEmail(){
  if(EMAIL.loadingClients) return `<h2>Email a client</h2><div class="card muted">Loading clients from Zoho Books…</div>`;
  const opts = ['<option value="">— choose a client —</option>']
    .concat(EMAIL.clients.map(c=>`<option value="${c.id}" ${c.id===EMAIL.clientId?'selected':''}>${(c.name||'(no name)')}${c.email?'':'  (no email)'}</option>`)).join('');

  let body = '';
  if(EMAIL.loading) body = `<div class="card muted">Building statement…</div>`;
  else if(EMAIL.data){
    const d=EMAIL.data;
    const curSent = !!((EMAIL.clients||[]).find(x=>x.id===EMAIL.clientId)||{}).sent;
    body = `
    <div class="card">
      <div class="row"><b style="font-size:13px">${d.client.name||''}</b><span class="muted">${d.count} invoice${d.count===1?'':'s'} in period</span></div>
      <div class="grid3" style="margin-top:8px">
        <div><div class="lab">Billed</div><div class="val">${fmt(d.billed)}</div></div>
        <div><div class="lab">Total due</div><div class="val">${fmt(d.totalDue)}</div></div>
        <div><div class="lab">Overdue</div><div class="val" style="color:${d.overdueDue>0?'var(--bad)':'var(--ink)'}">${fmt(d.overdueDue)}</div></div>
      </div>
    </div>
    <label>Statement period <span class="muted" style="font-weight:400">(smart default: from your last two paid invoices → latest unpaid)</span></label>
    <div class="grid2" style="gap:8px">
      <input type="date" value="${EMAIL.from||''}" onchange="EMAIL.from=this.value">
      <input type="date" value="${EMAIL.to||''}" onchange="EMAIL.to=this.value">
    </div>
    <button class="btn sec" onclick="emailRebuild()">Rebuild statement</button>
    <label style="margin-top:12px">Send to</label>
    <div style="display:flex;gap:8px">
      <input type="email" list="emBookList" style="flex:1" value="${EMAIL.to_email||''}" oninput="EMAIL.to_email=this.value;EMAIL.toEdited=true" placeholder="client@email.com">
      <button class="btn sec" style="flex:0 0 auto;width:auto;padding:10px 14px" onclick="emailAddEmail()">＋ Save</button>
    </div>
    <datalist id="emBookList">${(EMAIL.book||[]).map(e=>`<option value="${e.replace(/"/g,'&quot;')}">`).join('')}</datalist>
    ${(EMAIL.book||[]).length?`<div style="margin:-2px 0 12px;display:flex;flex-wrap:wrap;gap:6px">
      ${EMAIL.book.map(e=>`<span style="display:inline-flex;align-items:center;gap:6px;background:#F1F4F8;border:1px solid var(--line);border-radius:20px;padding:4px 10px;font-size:11.5px">
        <span style="cursor:pointer;color:var(--blue)" onclick="emailUseEmail('${e.replace(/'/g,"")}')">${e}</span>
        <span style="cursor:pointer;color:var(--bad);font-weight:700" title="Remove" onclick="emailRemoveEmail('${e.replace(/'/g,"")}')">×</span></span>`).join('')}
    </div>`:`<div class="muted" style="margin:-4px 0 12px;font-size:11px">Tip: type an email and tap “Save” to remember it for this client. Saved emails autocomplete next time.</div>`}
    <label>Subject</label>
    <input type="text" value="${(EMAIL.subject||'').replace(/"/g,'&quot;')}" oninput="emailField('subject',this.value)">
    <label>Message</label>
    <textarea rows="4" oninput="emailField('intro',this.value)" style="width:100%;box-sizing:border-box;padding:10px 12px;border:1px solid var(--line);border-radius:9px;font-size:13px;font-family:inherit;margin-bottom:12px">${EMAIL.intro||''}</textarea>
    <label>Invoices in the statement (ticked = included)</label>
    <div id="emInvBody">${emInvHtml()}</div>
    <div style="display:flex;gap:8px;margin-top:12px;flex-wrap:wrap">
      <button class="btn" style="flex:1;min-width:160px" onclick="emailDraft()" ${EMAIL.saving?'disabled':''}>${EMAIL.saving?'Saving…':'Save to Zoho Drafts'}</button>
      <button class="btn sec" style="flex:0 0 auto" onclick="emailDownloadPdf()">⤓ Download statement (PDF)</button>
      ${curSent
        ? `<button class="btn sec" style="flex:0 0 auto;border-color:#D64933;color:#D64933" onclick="emailUnmarkSent('${EMAIL.clientId}')">✓ Sent — undo</button>`
        : `<button class="btn sec" style="flex:0 0 auto" title="Strike this client red for 2 weeks and drop to the bottom of the list" onclick="emailMarkSent('${EMAIL.clientId}','${(d.client.name||'').replace(/'/g,'')}')">Mark as sent ✓</button>`}
    </div>
    ${EMAIL.msg?`<div class="${EMAIL.msgErr?'warn':'ok'}" style="margin-top:10px">${EMAIL.msg}</div>`:''}
    <div class="muted" style="font-size:11px;margin-top:8px">Creates a draft in your Zoho Mail — it does not send. Review and send it from Zoho Mail.</div>`;
  } else if(EMAIL.clientId){
    body = `<div class="warn">${EMAIL.msg||'No statement.'}</div>`;
  } else {
    body = `<div class="card muted" style="text-align:center">Pick a client to build their statement.</div>`;
  }

  return `
  <h2>Email a client</h2>
  ${EMAIL.msg && !EMAIL.data && !EMAIL.clientId ? `<div class="warn">${EMAIL.msg}</div>`:''}
  ${emUnpaidTableHtml()}
  <label>Search a client</label>
  <input id="emClientSearch" type="text" autocomplete="off" placeholder="Search client name or email…" value="${(EMAIL.clientSearch||'').replace(/"/g,'&quot;')}" oninput="emailClientSearch(this.value)">
  <div id="emClientList">${emClientListHtml()}</div>
  ${body}`;
}

function emailBulkToggle(id){ EMAIL.bulkSel[id]=!EMAIL.bulkSel[id]; render(); }
function emailBulkAll(on){
  (EMAIL.clients||[]).filter(c=>c.unpaid).forEach(c=>{ EMAIL.bulkSel[c.id]=on; });
  render();
}
async function emailBulkRun(){
  const list=(EMAIL.clients||[]).filter(c=>c.unpaid && EMAIL.bulkSel[c.id]);
  if(!list.length || EMAIL.bulkRunning) return;
  EMAIL.bulkRunning=true; EMAIL.bulkProg={}; EMAIL.bulkDone=0; EMAIL.bulkTotal=list.length; EMAIL.bulkMsg=''; render();
  let ok=0, skip=0, fail=0;
  for(const c of list){
    EMAIL.bulkProg[c.id]='busy'; render();
    try{
      const bk = await fetch('api/email_book.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},
        body:JSON.stringify({ customer_id:c.id })}).then(r=>r.json());
      const emails = (bk.ok && bk.emails && bk.emails.length) ? bk.emails.join(', ') : '';
      if(!emails){ EMAIL.bulkProg[c.id]='skip'; skip++; EMAIL.bulkDone++; render(); continue; }
      const j = await fetch('api/client_account.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},
        body:JSON.stringify({ customer_id:c.id, from:'', to:'' })}).then(r=>r.json());
      if(!j.ok){ EMAIL.bulkProg[c.id]='fail'; fail++; EMAIL.bulkDone++; render(); continue; }
      const unpaid=(j.invoices||[]).filter(iv=>iv.unpaid===true);
      if(!unpaid.length){ EMAIL.bulkProg[c.id]='skip'; skip++; EMAIL.bulkDone++; render(); continue; }
      const name = j.client.name||'Client';
      const intro = 'Dear '+name+',\n\nPlease find the unpaid invoices attached to this email, together with your statement of account below, for payment.';
      const html = emStatementHtmlFrom(unpaid, intro, name);
      const dr = await fetch('api/email_draft.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},
        body:JSON.stringify({ to:emails, subject:(CFG.stmtSubject||'Pending invoices and Statement'), html:html })}).then(r=>r.json());
      if(dr.ok){ EMAIL.bulkProg[c.id]='ok'; ok++; } else { EMAIL.bulkProg[c.id]='fail'; fail++; }
    }catch(e){ EMAIL.bulkProg[c.id]='fail'; fail++; }
    EMAIL.bulkDone++; render();
  }
  EMAIL.bulkRunning=false;
  EMAIL.bulkErr = (ok===0);
  EMAIL.bulkMsg = `Done — ${ok} drafted${skip?`, ${skip} skipped (no email)`:''}${fail?`, ${fail} failed`:''}. Open Zoho Mail → Drafts to review and send.`;
  render();
}

function emailMarkSent(id,name){
  const c=(EMAIL.clients||[]).find(x=>x.id===id);
  if(c){ c.sent=true; c.sent_at=new Date().toISOString(); render(); }
  fetch('api/email_sent.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},
    body:JSON.stringify({action:'mark',customer_id:id})}).then(r=>r.json()).then(j=>{
    if(!j.ok && c){ c.sent=false; render(); } }).catch(()=>{ if(c){ c.sent=false; render(); } });
}
function emailUnmarkSent(id){
  const c=(EMAIL.clients||[]).find(x=>x.id===id);
  if(c){ c.sent=false; render(); }
  fetch('api/email_sent.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},
    body:JSON.stringify({action:'unmark',customer_id:id})}).then(r=>r.json()).then(j=>{
    if(!j.ok && c){ c.sent=true; render(); } }).catch(()=>{ if(c){ c.sent=true; render(); } });
}
function emUnpaidTableHtml(){
  const list=(EMAIL.clients||[]).filter(c=>c.unpaid).slice()
    .sort((a,b)=>{ const sa=a.sent?1:0, sb=b.sent?1:0; if(sa!==sb) return sa-sb; return (b.unpaidTotal||0)-(a.unpaidTotal||0); });
  if(!list.length) return '';
  let tot=0, cnt=0;
  const allSel = list.length && list.every(c=>EMAIL.bulkSel[c.id]);
  const rows=list.map(c=>{ tot+=(c.unpaidTotal||0); cnt+=(c.unpaidCount||0);
    const on=!!EMAIL.bulkSel[c.id];
    const prog=EMAIL.bulkProg[c.id];
    const sent=!!c.sent;
    const ss = sent ? 'text-decoration:line-through;text-decoration-color:#D64933;text-decoration-thickness:2px;color:#98A2B0' : '';
    let tag;
    if(prog==='ok') tag=`<span style="color:var(--good);font-weight:700">✓ drafted</span>`;
    else if(prog==='skip') tag=`<span style="color:var(--mute)">skipped (no email)</span>`;
    else if(prog==='fail') tag=`<span style="color:var(--bad);font-weight:700">✗ failed</span>`;
    else if(prog==='busy') tag=`<span style="color:var(--blue)">…</span>`;
    else if(sent) tag=`<span style="color:#D64933;font-weight:700">SENT</span> <a onclick="event.stopPropagation();emailUnmarkSent('${c.id}')" style="color:var(--blue);cursor:pointer;font-size:10px">undo</a>`;
    else tag=`<span style="color:var(--orange);font-weight:600;cursor:pointer" onclick="emailSelectClient('${c.id}')">Build →</span>
                 &nbsp;<button class="btn sec" style="width:auto;padding:3px 8px;font-size:10px" title="Mark sent — strikes the row in red for 2 weeks and drops it to the bottom" onclick="event.stopPropagation();emailMarkSent('${c.id}','${(c.name||'').replace(/'/g,"")}')">Sent ✓</button>`;
    return `<tr>
      <td class="l" onclick="event.stopPropagation()"><input type="checkbox" ${on?'checked':''} onclick="emailBulkToggle('${c.id}')"></td>
      <td class="l" style="cursor:pointer;${ss}" onclick="emailSelectClient('${c.id}')">${c.name||'(no name)'}</td>
      <td style="cursor:pointer;${ss}" onclick="emailSelectClient('${c.id}')">${c.unpaidCount||0}</td>
      <td style="cursor:pointer;${ss}" onclick="emailSelectClient('${c.id}')">${fmt(c.unpaidTotal||0)}</td>
      <td class="l">${tag}</td></tr>`; }).join('');
  const nSel = list.filter(c=>EMAIL.bulkSel[c.id]).length;
  return `<label>Clients with unpaid invoices (${list.length})</label>
    <div class="rptwrap" style="margin-bottom:10px"><table class="rpt" style="font-size:11px">
      <thead><tr>
        <th class="l"><input type="checkbox" ${allSel?'checked':''} onclick="emailBulkAll(this.checked)" title="Select all"></th>
        <th class="l">Client</th><th>Unpaid</th><th>Amount due</th><th class="l"></th></tr></thead>
      <tbody>${rows}</tbody>
      <tfoot><tr class="tot"><td></td><td class="l">Total (${list.length} clients)</td><td>${cnt}</td><td>${fmt(tot)}</td><td></td></tr></tfoot>
    </table></div>
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:16px">
      <button class="btn" style="width:auto;padding:9px 16px" onclick="emailBulkRun()" ${EMAIL.bulkRunning||!nSel?'disabled':''}>
        ${EMAIL.bulkRunning?`Drafting ${EMAIL.bulkDone}/${EMAIL.bulkTotal}…`:`Build &amp; draft selected (${nSel})`}</button>
      ${nSel?`<span class="muted" style="font-size:11px">Drafts go to each client’s saved email(s). Clients with no saved email are skipped.</span>`:''}
      ${EMAIL.bulkMsg?`<span class="${EMAIL.bulkErr?'warn':'ok'}" style="font-size:11px;padding:4px 8px;border-radius:6px">${EMAIL.bulkMsg}</span>`:''}
    </div>`;
}

function emStatementHtmlFrom(picked, intro, clientName){
  const esc=s=>String(s==null?'':s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
  const introHtml = esc(intro).replace(/\n/g,'<br>');
  let due=0;
  const rows=(picked||[]).map((iv,i)=>{ due+=iv.balance; const stat = iv.unpaid? esc(iv.status||'unpaid') : 'paid';
    const stColor = iv.unpaid ? '#D64933' : '#16A34A';
    const zebra = i%2 ? '#FAFBFD' : '#FFFFFF';
    return `<tr style="background:${zebra}">
    <td style="padding:9px 12px;border-bottom:1px solid #ECEFF3;color:#15202B">${esc(iv.number)}${iv.desc?`<div style="color:#94A3B8;font-size:10.5px;margin-top:2px">${esc(iv.desc)}</div>`:''}</td>
    <td style="padding:9px 12px;border-bottom:1px solid #ECEFF3;color:#475569">${esc(iv.date)}</td>
    <td style="padding:9px 12px;border-bottom:1px solid #ECEFF3"><span style="color:${stColor};font-weight:600;text-transform:capitalize">${stat}</span></td>
    <td style="padding:9px 12px;border-bottom:1px solid #ECEFF3;text-align:right;color:#475569">KES ${fmtn(iv.total)}${iv.foreign?`<div style="color:#16A34A;font-size:11px">$${fmtn(iv.origTotal)} USD</div>`:''}</td>
    <td style="padding:9px 12px;border-bottom:1px solid #ECEFF3;text-align:right;color:#15202B;font-weight:600">KES ${fmtn(iv.balance)}${iv.foreign?`<div style="color:#16A34A;font-size:11px;font-weight:600">$${fmtn(iv.origBalance)} USD</div>`:''}</td></tr>`; }).join('');
  return `<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#15202B;max-width:680px">
    <div style="background:#15202B;border-radius:10px 10px 0 0;padding:18px 22px;color:#fff">
      <table width="100%" style="border-collapse:collapse"><tr>
        <td style="vertical-align:middle"><span style="display:inline-block;background:#F56F00;color:#fff;font-weight:700;font-size:15px;padding:7px 10px;border-radius:8px;letter-spacing:.5px">912</span></td>
        <td style="vertical-align:middle;text-align:right">
          <div style="font-size:16px;font-weight:700;letter-spacing:.5px">STATEMENT OF ACCOUNT</div>
          <div style="font-size:12px;color:#AEB9C7">${esc(clientName||'')}</div>
        </td></tr></table>
    </div>
    <div style="border:1px solid #E6EAF0;border-top:0;border-radius:0 0 10px 10px;padding:22px">
      <p style="margin:0 0 16px;line-height:1.55">${introHtml}</p>
      <table style="border-collapse:collapse;width:100%;font-size:13px;margin:0 0 16px;border:1px solid #E6EAF0;border-radius:8px;overflow:hidden">
        <thead><tr style="background:#F56F00;color:#fff">
          <th style="padding:10px 12px;text-align:left;font-size:11px;letter-spacing:.4px;text-transform:uppercase">Invoice</th>
          <th style="padding:10px 12px;text-align:left;font-size:11px;letter-spacing:.4px;text-transform:uppercase">Date</th>
          <th style="padding:10px 12px;text-align:left;font-size:11px;letter-spacing:.4px;text-transform:uppercase">Status</th>
          <th style="padding:10px 12px;text-align:right;font-size:11px;letter-spacing:.4px;text-transform:uppercase">Amount</th>
          <th style="padding:10px 12px;text-align:right;font-size:11px;letter-spacing:.4px;text-transform:uppercase">Balance</th></tr></thead>
        <tbody>${rows}</tbody>
        <tfoot><tr style="background:#15202B;color:#fff">
          <td colspan="4" style="padding:11px 12px;text-align:right;font-weight:700;letter-spacing:.3px">TOTAL OUTSTANDING</td>
          <td style="padding:11px 12px;text-align:right;font-weight:700;color:#F8B26A">${fmtn(due)} ${esc(CFG.cur)}</td></tr></tfoot>
      </table>
      <p style="margin:0;color:#64748B;font-size:12px;line-height:1.5">For any queries on this statement, please reply to this email.</p>
      <p style="margin:14px 0 0;padding-top:12px;border-top:1px solid #EEF1F5;color:#AEB9C7;font-size:10px;line-height:1.5;font-style:italic">${esc(CFG.stmtFooter||"Prepared and reconciled by the 912 Finance Console — Nine One Two Holdings' intelligent finance engine, delivering precise, automated account statements in real time.")}</p>
    </div>
  </div>`;
}

function emailBuildHtml(){
  const d=EMAIL.data; if(!d) return '';
  const picked=(d.invoices||[]).filter(iv=>EMAIL.picked[iv.number]);
  return emStatementHtmlFrom(picked, EMAIL.intro, d.client.name);
}

function emailDownloadPdf(){
  if(!EMAIL.data){ return; }
  const name = (EMAIL.data.client && EMAIL.data.client.name) || 'client';
  const inner = emailBuildHtml();
  const w = window.open('', '_blank');
  if(!w){ alert('Please allow pop-ups for this site to download the PDF.'); return; }
  w.document.write('<!doctype html><html><head><meta charset="utf-8"><title>Statement - '+name.replace(/[<>&]/g,'')+'</title>'
    + '<style>@page{margin:14mm} body{font-family:Arial,Helvetica,sans-serif;margin:0;padding:18px;color:#15202B}</style>'
    + '</head><body>' + inner
    + '<scr'+'ipt>window.onload=function(){setTimeout(function(){window.print();},250);};</scr'+'ipt>'
    + '</body></html>');
  w.document.close();
}

function emailDraft(){
  if(!EMAIL.data) return;
  const picked=Object.keys(EMAIL.picked).filter(k=>EMAIL.picked[k]);
  if(!EMAIL.to_email){ EMAIL.msg='Add a recipient email first.'; EMAIL.msgErr=true; render(); return; }
  if(!picked.length){ EMAIL.msg='Tick at least one invoice.'; EMAIL.msgErr=true; render(); return; }
  EMAIL.saving=true; EMAIL.msg=''; render();
  fetch('api/email_draft.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},
    body:JSON.stringify({ to:EMAIL.to_email, subject:EMAIL.subject, html:emailBuildHtml() })})
    .then(r=>r.json()).then(j=>{
      EMAIL.saving=false;
      if(j.ok){ EMAIL.msg='Draft saved to Zoho Mail'+(j.from?(' ('+j.from+')'):'')+'. Open Zoho Mail → Drafts to review and send.'; EMAIL.msgErr=false; }
      else { EMAIL.msg='Could not save draft: '+(j.error||'failed'); EMAIL.msgErr=true; }
      render();
    }).catch(e=>{ EMAIL.saving=false; EMAIL.msg='Error: '+e; EMAIL.msgErr=true; render(); });
}
/* ================= end Emails ================= */

/* ================= To-Do ================= */
let TASK = { loaded:false, loading:false, tasks:[], users:[], inbox:[], inboxState:'', inboxMsg:'',
             newTitle:'', newSubject:'', newNotes:'', newAssignees:[], msg:'', msgErr:false, showDone:true, busyId:0,
             blocked:[], showBlocked:false,
             calId:0, calDate:'', calTime:'09:00', calDur:60, calBusy:false, calMsg:'', calErr:false,
             newMode:'task', newCalDate:'', newCalTime:'09:00', newCalDur:60 };
function todoCalToggle(id){ TASK.calId = (TASK.calId===id?0:id); TASK.calMsg=''; render(); }
function todoCalSend(id){
  const t=(TASK.tasks||[]).find(x=>x.id===id); if(!t) return;
  const recipients=(t.assignees||[]).filter(a=>a.ticked && a.email).map(a=>({name:a.name||a.email,email:a.email}));
  if(!recipients.length){ TASK.calMsg='Tick at least one assignee who has an email.'; TASK.calErr=true; render(); return; }
  TASK.calBusy=true; TASK.calMsg=''; render();
  fetch('api/task_calendar.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},
    body:JSON.stringify({id, date:TASK.calDate||today(), time:TASK.calTime||'09:00', durationMins:parseInt(TASK.calDur)||60, recipients})})
  .then(r=>r.json()).then(j=>{
    TASK.calBusy=false;
    if(j.ok){ TASK.calErr=false; const parts=[]; if(j.direct)parts.push(j.direct+' added directly'); if(j.sent)parts.push(j.sent+' emailed an invite'); TASK.calMsg=(parts.join(' · ')||'Done')+' for '+j.when+(j.fails&&j.fails.length?(' · failed: '+j.fails.join(', ')):'')+'.'; }
    else { TASK.calErr=true; TASK.calMsg='Error: '+(j.error||'failed'); }
    render();
  }).catch(e=>{ TASK.calBusy=false; TASK.calErr=true; TASK.calMsg='Error: '+e; render(); });
}

function todoLoad(){
  TASK.loading=true; render();
  Promise.all([
    fetch('api/tasks.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'list'})}).then(r=>r.json()),
    fetch('api/task_users.php',{credentials:'same-origin'}).then(r=>r.json())
  ]).then(([tj,uj])=>{
    TASK.loading=false; TASK.loaded=true;
    TASK.tasks = tj.ok ? tj.tasks : [];
    TASK.users = uj.ok ? uj.users : [];
    render();
  }).catch(e=>{ TASK.loading=false; TASK.loaded=true; TASK.msg='Error: '+e; TASK.msgErr=true; render(); });
}
function todoRefreshTasks(j){ if(j&&j.ok){ TASK.tasks=j.tasks||[]; } }
function todoLoadInbox(){
  TASK.inboxState='loading'; render(); todoLoadBlocked();
  fetch('api/mail_inbox.php',{credentials:'same-origin'}).then(r=>r.json()).then(j=>{
    if(j.ok){ TASK.inbox=j.messages||[]; TASK.inboxState='ok'; TASK.inboxMsg=''; }
    else if(j.need_scope){ TASK.inboxState='scope'; TASK.inboxMsg=j.error||'Add the Mail READ scope.'; }
    else { TASK.inboxState='err'; TASK.inboxMsg=j.error||'Could not read inbox.'; }
    render();
  }).catch(e=>{ TASK.inboxState='err'; TASK.inboxMsg=''+e; render(); });
}
function todoLoadBlocked(){
  fetch('api/task_block.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'list'})})
  .then(r=>r.json()).then(j=>{ if(j.ok){ TASK.blocked=j.blocked||[]; render(); } }).catch(()=>{});
}
function todoBlockSender(email,label){
  if(!email){ return; }
  if(!confirm('Never show emails from '+(label||email)+' as task candidates?')) return;
  fetch('api/task_block.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'add',email})})
  .then(r=>r.json()).then(j=>{ if(j.ok){ TASK.blocked=j.blocked||[]; TASK.inbox=(TASK.inbox||[]).filter(m=>String(m.fromEmail).toLowerCase()!==String(email).toLowerCase()); render(); } }).catch(()=>{});
}
function todoUnblock(email){
  fetch('api/task_block.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'remove',email})})
  .then(r=>r.json()).then(j=>{ if(j.ok){ TASK.blocked=j.blocked||[]; render(); } }).catch(()=>{});
}

function todoUserByName(name){ return (TASK.users||[]).find(u=>u.name===name); }
function todoUsersExcept(emails){ const ex=new Set((emails||[]).map(e=>String(e).toLowerCase())); return (TASK.users||[]).filter(u=>!ex.has(String(u.email).toLowerCase())); }

function todoNewAddAssignee(name){
  const u=todoUserByName(name); if(!u) return;
  if(!TASK.newAssignees.some(a=>a.email.toLowerCase()===u.email.toLowerCase())) TASK.newAssignees.push({name:u.name,email:u.email});
  render();
}
function todoNewRemoveAssignee(email){ TASK.newAssignees=TASK.newAssignees.filter(a=>a.email.toLowerCase()!==String(email).toLowerCase()); render(); }

function todoAdd(){
  const mode = TASK.newMode || 'task';
  const title=(TASK.newTitle||'').trim(); if(!title){ TASK.msg='Type a task title first.'; TASK.msgErr=true; render(); return; }
  const assignees = (TASK.newAssignees||[]).slice();
  const wantsCal = (mode==='both'||mode==='cal');
  if(wantsCal){
    const haveEmail = assignees.filter(a=>a.email).length;
    if(!haveEmail){ TASK.msg='Add at least one assignee with an email before sending a calendar invite.'; TASK.msgErr=true; render(); return; }
  }
  fetch('api/tasks.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},
    body:JSON.stringify({action:'add',title,subject:TASK.newSubject||'',notes:TASK.newNotes||'',assignees})})
  .then(r=>r.json()).then(async j=>{
    if(!j.ok){ TASK.msg=j.error||'Failed.'; TASK.msgErr=true; render(); return; }
    todoRefreshTasks(j);
    const newId = j.id;
    if(wantsCal && newId){
      try{
        const recipients = assignees.filter(a=>a.email).map(a=>({name:a.name||a.email,email:a.email}));
        const cj = await fetch('api/task_calendar.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},
          body:JSON.stringify({id:newId, date:TASK.newCalDate||today(), time:TASK.newCalTime||'09:00', durationMins:parseInt(TASK.newCalDur)||60, recipients})}).then(r=>r.json());
        if(mode==='cal' && newId){ // calendar-only: archive the task so it doesn't sit as an open to-do
          await fetch('api/tasks.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'toggle',id:newId,status:'done'})}).then(r=>r.json()).then(todoRefreshTasks);
        }
        TASK.msg = cj.ok ? ((cj.direct?(cj.direct+' added to calendar directly'):'')+((cj.direct&&cj.sent)?' · ':'')+(cj.sent?(cj.sent+' emailed an invite'):'')+(mode==='cal'?' (calendar only)':' · task added')+'.') : ('Task added, but calendar step failed: '+(cj.error||'error'));
        TASK.msgErr = !cj.ok;
      }catch(e){ TASK.msg='Task added, but invite error: '+e; TASK.msgErr=true; }
    } else {
      TASK.msg='Task added.'; TASK.msgErr=false;
    }
    TASK.newTitle='';TASK.newSubject='';TASK.newNotes='';TASK.newAssignees=[];TASK.newMode='task';
    render();
  })
  .catch(e=>{ TASK.msg='Error: '+e;TASK.msgErr=true; render(); });
}
function todoAddFromEmail(idx){
  const m=TASK.inbox[idx]; if(!m) return;
  fetch('api/tasks.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},
    body:JSON.stringify({action:'add',title:m.subject,notes:(m.from?('From: '+m.from+'\n'):'')+(m.summary||''),source:'email',source_ref:m.id})})
  .then(r=>r.json()).then(j=>{ if(j.ok){ todoRefreshTasks(j); TASK.msg='Added “'+m.subject+'” to your list.';TASK.msgErr=false; } else { TASK.msg=j.error||'Failed.';TASK.msgErr=true; } render(); })
  .catch(e=>{ TASK.msg='Error: '+e;TASK.msgErr=true; render(); });
}
function todoToggle(id,done){
  fetch('api/tasks.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'toggle',id,status:done?'done':'open'})})
  .then(r=>r.json()).then(j=>{ todoRefreshTasks(j); render(); });
}
function todoField(id,field,val){
  fetch('api/tasks.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'update',id,[field]:val})})
  .then(r=>r.json()).then(j=>{ todoRefreshTasks(j); }); // silent to keep focus
}
function todoTaskAddAssignee(id,name){
  const t=(TASK.tasks||[]).find(x=>x.id===id); const u=todoUserByName(name); if(!t||!u) return;
  const set=(t.assignees||[]).map(a=>({name:a.name,email:a.email}));
  if(!set.some(a=>a.email.toLowerCase()===u.email.toLowerCase())) set.push({name:u.name,email:u.email});
  fetch('api/tasks.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'set_assignees',id,assignees:set})})
  .then(r=>r.json()).then(j=>{ todoRefreshTasks(j); render(); });
}
function todoTaskRemoveAssignee(id,email){
  const t=(TASK.tasks||[]).find(x=>x.id===id); if(!t) return;
  const set=(t.assignees||[]).filter(a=>a.email.toLowerCase()!==String(email).toLowerCase()).map(a=>({name:a.name,email:a.email}));
  fetch('api/tasks.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'set_assignees',id,assignees:set})})
  .then(r=>r.json()).then(j=>{ todoRefreshTasks(j); render(); });
}
function todoToggleAssignee(id,email,ticked){
  fetch('api/tasks.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'toggle_assignee',id,email,ticked:ticked?1:0})})
  .then(r=>r.json()).then(j=>{ todoRefreshTasks(j); render(); });
}
function todoDelete(id){
  fetch('api/tasks.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'delete',id})})
  .then(r=>r.json()).then(j=>{ todoRefreshTasks(j); render(); });
}
function todoSend(id){
  TASK.busyId=id; render();
  fetch('api/tasks.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'send',id})})
  .then(r=>r.json()).then(j=>{ TASK.busyId=0; if(j.ok){ todoRefreshTasks(j); TASK.msg='Sent to '+j.to+'.';TASK.msgErr=false; } else { TASK.msg=j.error||'Send failed.';TASK.msgErr=true; } render(); })
  .catch(e=>{ TASK.busyId=0; TASK.msg='Error: '+e;TASK.msgErr=true; render(); });
}

function todoAssigneeChips(t){
  const esc=s=>String(s==null?'':s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
  const as=t.assignees||[];
  const chips=as.map(a=>`<span style="display:inline-flex;align-items:center;gap:6px;background:#F1F4F8;border:1px solid var(--line);border-radius:20px;padding:3px 9px;font-size:11px">
      <input type="checkbox" ${a.ticked?'checked':''} title="Tick to include in Send" onclick="todoToggleAssignee(${t.id},'${String(a.email).replace(/'/g,'')}',this.checked)">
      <span>${esc(a.name||a.email)}</span>
      <span style="cursor:pointer;color:var(--bad);font-weight:700" onclick="todoTaskRemoveAssignee(${t.id},'${String(a.email).replace(/'/g,'')}')">×</span>
    </span>`).join(' ');
  const left=todoUsersExcept(as.map(a=>a.email));
  const addSel = left.length? `<select style="width:auto;min-width:140px;font-size:11px" onchange="if(this.value){todoTaskAddAssignee(${t.id},this.value);this.value='';}">
      <option value="">+ add user…</option>${left.map(u=>`<option value="${esc(u.name)}">${esc(u.name)}</option>`).join('')}</select>`:'';
  return `<div style="display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin-top:8px">${chips||'<span class="muted" style="font-size:11px">No one assigned yet.</span>'} ${addSel}</div>`;
}

function todoTaskCard(t){
  const esc=s=>String(s==null?'':s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  const done=t.status==='done';
  const tickedCount=(t.assignees||[]).filter(a=>a.ticked).length;
  const sent = t.sent_at ? `<span class="muted" style="font-size:10px">sent ✓</span>` : '';
  return `<div class="card" style="border-left:3px solid ${done?'var(--good)':'var(--orange)'};${done?'opacity:.7':''}">
    <div class="row" style="align-items:flex-start;gap:8px">
      <label style="display:flex;align-items:center;gap:7px;flex:1">
        <input type="checkbox" ${done?'checked':''} onclick="todoToggle(${t.id},this.checked)">
        <span style="font-weight:600;font-size:13px;${done?'text-decoration:line-through':''}">${esc(t.title)}</span>
      </label>
      <button class="btn sec" style="width:auto;padding:3px 8px;font-size:11px" onclick="todoDelete(${t.id})">✕</button>
    </div>
    <input type="text" placeholder="Subject / short label…" style="width:100%;margin-top:8px;font-size:12px;font-weight:600" value="${esc(t.subject||'')}" onchange="todoField(${t.id},'subject',this.value)">
    <textarea placeholder="Notes…" style="width:100%;margin-top:8px;min-height:46px;font-size:12px" onchange="todoField(${t.id},'notes',this.value)">${esc(t.notes||'')}</textarea>
    <div class="muted" style="font-size:10.5px;margin-top:8px">Assignees (tick who to send to):</div>
    ${todoAssigneeChips(t)}
    <div class="row" style="gap:8px;margin-top:8px;flex-wrap:wrap;align-items:center">
      <button class="btn" style="width:auto;padding:6px 12px;font-size:12px" onclick="todoSend(${t.id})" ${(tickedCount===0||TASK.busyId===t.id)?'disabled':''}>${TASK.busyId===t.id?'Sending…':'✉ Send to ticked ('+tickedCount+')'}</button>
      <button class="btn sec" style="width:auto;padding:6px 12px;font-size:12px" onclick="todoCalToggle(${t.id})">📅 Calendar</button>
      ${sent}
    </div>
    ${TASK.calId===t.id?`<div class="card" style="background:#F7F9FC;margin-top:8px">
      <div class="muted" style="font-size:10.5px;margin-bottom:6px">Emails a calendar invite to the ticked assignees — they tap once to add it to their calendar.</div>
      <div class="grid2" style="gap:8px">
        <div><label>Date</label><input type="date" value="${esc(TASK.calDate||today())}" onchange="TASK.calDate=this.value"></div>
        <div><label>Time</label><input type="time" value="${esc(TASK.calTime||'09:00')}" onchange="TASK.calTime=this.value"></div>
      </div>
      <label>Duration (minutes)</label>
      <input type="number" step="15" min="5" value="${esc(TASK.calDur||60)}" onchange="TASK.calDur=this.value">
      <button class="btn" style="margin-top:8px;width:auto;padding:6px 12px;font-size:12px" onclick="todoCalSend(${t.id})" ${(tickedCount===0||TASK.calBusy)?'disabled':''}>${TASK.calBusy?'Sending…':'Send calendar invite to ticked ('+tickedCount+')'}</button>
      ${(TASK.calMsg&&TASK.calId===t.id)?`<div class="${TASK.calErr?'warn':'ok'}" style="margin-top:8px;font-size:12px">${TASK.calMsg}</div>`:''}
    </div>`:''}
  </div>`;
}

function todoInboxHtml(){
  if(TASK.inboxState==='loading') return `<div class="card muted" style="text-align:center">Reading your inbox…</div>`;
  if(TASK.inboxState==='scope') return `<div class="warn" style="font-size:12px">${TASK.inboxMsg}</div>`;
  if(TASK.inboxState==='err') return `<div class="warn" style="font-size:12px">Could not read inbox${TASK.inboxMsg?(': '+TASK.inboxMsg):''}.</div>`;
  if(TASK.inboxState==='ok'){
    const esc=s=>String(s==null?'':s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    const blockMgr = (TASK.blocked&&TASK.blocked.length)?`<div style="margin:6px 2px 10px">
      <span class="muted" style="font-size:11px;cursor:pointer" onclick="TASK.showBlocked=!TASK.showBlocked;render()">🚫 Blocked senders (${TASK.blocked.length}) ${TASK.showBlocked?'▲':'▼'}</span>
      ${TASK.showBlocked?`<div style="display:flex;flex-wrap:wrap;gap:6px;margin-top:6px">${TASK.blocked.map(e=>`<span style="display:inline-flex;align-items:center;gap:6px;background:#FDECEA;border:1px solid #f0c3bc;border-radius:20px;padding:3px 9px;font-size:11px">${esc(e)} <span style="cursor:pointer;color:var(--blue)" title="Unblock" onclick="todoUnblock('${String(e).replace(/'/g,'')}')">undo</span></span>`).join('')}</div>`:''}
    </div>`:'';
    const list = !TASK.inbox.length ? `<div class="card muted" style="text-align:center">No emails in the last 14 days (after filtering blocked senders).</div>`
      : TASK.inbox.map((m,i)=>`<div class="card" style="padding:10px 12px">
      <div class="row" style="gap:8px;align-items:flex-start">
        <div style="flex:1;min-width:0">
          <div style="font-weight:600;font-size:12.5px">${esc(m.subject)}</div>
          <div class="muted" style="font-size:10.5px">${esc(m.from)}${m.received?(' · '+esc(m.received)):''}</div>
          ${m.summary?`<div class="muted" style="font-size:11px;margin-top:3px">${esc(m.summary).slice(0,140)}</div>`:''}
        </div>
        <div style="display:flex;flex-direction:column;gap:5px">
          <button class="btn sec" style="width:auto;padding:5px 10px;font-size:11px;white-space:nowrap" onclick="todoAddFromEmail(${i})">+ Task</button>
          <button class="btn sec" style="width:auto;padding:4px 8px;font-size:10px;white-space:nowrap" title="Never show this sender as a task" onclick="todoBlockSender('${String(m.fromEmail).replace(/'/g,'')}','${esc(m.from).replace(/'/g,'')}')">🚫 never</button>
        </div>
      </div></div>`).join('');
    return blockMgr + list;
  }
  return `<button class="btn" style="width:auto;padding:8px 14px;font-size:12px" onclick="todoLoadInbox()">⤓ Load recent emails (14 days)</button>
    <div class="muted" style="font-size:11px;margin-top:6px">Pulls inbox messages from the last 14 days so you can turn any into a task. Blocked senders are skipped.</div>`;
}

function isMyTask(t){
  if(ME.admin) return true;
  const me=String(ME.user||'').toLowerCase(), em=String(ME.email||'').toLowerCase();
  return (t.assignees||[]).some(a=>{
    const an=String(a.name||'').toLowerCase(), ae=String(a.email||'').toLowerCase();
    return (em && ae===em) || (me && (an===me || ae===me));
  });
}
function vTodo(){
  if(TASK.loading && !TASK.loaded) return `<h2>To-Do</h2><div class="card muted" style="text-align:center">Loading…</div>`;
  const mine = (TASK.tasks||[]).filter(isMyTask);
  const open=mine.filter(t=>t.status!=='done');
  const done=mine.filter(t=>t.status==='done');
  const esc=s=>String(s==null?'':s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  const left=todoUsersExcept(TASK.newAssignees.map(a=>a.email));
  const newChips=TASK.newAssignees.map(a=>`<span style="display:inline-flex;align-items:center;gap:6px;background:#F1F4F8;border:1px solid var(--line);border-radius:20px;padding:3px 9px;font-size:11px">${esc(a.name||a.email)} <span style="cursor:pointer;color:var(--bad);font-weight:700" onclick="todoNewRemoveAssignee('${String(a.email).replace(/'/g,'')}')">×</span></span>`).join(' ');
  const newAddSel = left.length?`<select style="width:auto;min-width:160px" onchange="if(this.value){todoNewAddAssignee(this.value);this.value='';}"><option value="">+ assign user…</option>${left.map(u=>`<option value="${esc(u.name)}">${esc(u.name)}</option>`).join('')}</select>`:'';

  // Non-admins: only their own tasks, no add-task / email-import.
  if(!ME.admin){
    return `<h2>My to-do</h2>
    ${TASK.msg?`<div class="${TASK.msgErr?'warn':'ok'}" style="margin-bottom:10px">${TASK.msg}</div>`:''}
    <div class="muted" style="margin:-4px 2px 12px;font-size:12px">Tasks assigned to you. Mark them done and add notes as you go.</div>
    <div style="font-weight:600;font-size:13px;margin:6px 2px 8px">Open (${open.length})</div>
    ${open.length? open.map(todoTaskCard).join('') : `<div class="card muted" style="text-align:center">Nothing assigned to you right now. 🎉</div>`}
    ${done.length?`<div class="row" style="margin:14px 2px 8px">
        <span style="font-weight:600;font-size:13px">Done (${done.length})</span>
        <button class="btn sec" style="width:auto;padding:4px 10px;font-size:11px" onclick="TASK.showDone=!TASK.showDone;render()">${TASK.showDone?'Hide':'Show'}</button>
      </div>${TASK.showDone? done.map(todoTaskCard).join('') : ''}`:''}`;
  }

  return `<h2>To-Do</h2>
  ${TASK.msg?`<div class="${TASK.msgErr?'warn':'ok'}" style="margin-bottom:10px">${TASK.msg}</div>`:''}

  <div class="card">
    <b style="font-size:13px">Add a task</b>
    <input type="text" placeholder="What needs doing?" style="width:100%;margin-top:8px" value="${esc(TASK.newTitle)}" oninput="TASK.newTitle=this.value">
    <input type="text" placeholder="Subject / short label (e.g. Site survey)" style="width:100%;margin-top:8px" value="${esc(TASK.newSubject)}" oninput="TASK.newSubject=this.value">
    <textarea placeholder="Notes (optional)…" style="width:100%;margin-top:8px;min-height:44px;font-size:12px" oninput="TASK.newNotes=this.value">${esc(TASK.newNotes)}</textarea>
    <div style="display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin-top:8px">${newChips} ${newAddSel}</div>
    <div style="margin-top:10px"><div class="muted" style="font-size:11px;margin-bottom:4px">When I add this:</div>
      <div style="display:flex;gap:6px;flex-wrap:wrap">
        ${[['task','Task only'],['both','Task + Calendar'],['cal','Calendar only']].map(([v,l])=>`<button class="btn${ (TASK.newMode||'task')===v?'':' sec'}" style="width:auto;padding:5px 11px;font-size:11px" onclick="TASK.newMode='${v}';render()">${l}</button>`).join('')}
      </div></div>
    ${(TASK.newMode==='both'||TASK.newMode==='cal')?`<div class="card" style="background:#F7F9FC;margin-top:8px">
      <div class="grid2" style="gap:8px">
        <div><label>Date</label><input type="date" value="${esc(TASK.newCalDate||today())}" onchange="TASK.newCalDate=this.value"></div>
        <div><label>Time</label><input type="time" value="${esc(TASK.newCalTime||'09:00')}" onchange="TASK.newCalTime=this.value"></div>
      </div>
      <label>Duration (minutes)</label>
      <input type="number" step="15" min="5" value="${esc(TASK.newCalDur||60)}" onchange="TASK.newCalDur=this.value">
      <div class="muted" style="font-size:10.5px;margin-top:6px">A calendar invite is emailed to the assignees above (they need an email in Users).</div>
    </div>`:''}
    <button class="btn" style="width:auto;padding:9px 16px;margin-top:8px" onclick="todoAdd()">${TASK.newMode==='cal'?'Send calendar invite':(TASK.newMode==='both'?'+ Add task & invite':'+ Add task')}</button>
  </div>

  <div style="font-weight:600;font-size:13px;margin:10px 2px 8px">From your email</div>
  ${todoInboxHtml()}

  <div style="font-weight:600;font-size:13px;margin:14px 2px 8px">Open tasks (${open.length})</div>
  ${open.length? open.map(todoTaskCard).join('') : `<div class="card muted" style="text-align:center">Nothing open. 🎉</div>`}

  ${done.length?`<div class="row" style="margin:14px 2px 8px">
      <span style="font-weight:600;font-size:13px">Done (${done.length})</span>
      <button class="btn sec" style="width:auto;padding:4px 10px;font-size:11px" onclick="TASK.showDone=!TASK.showDone;render()">${TASK.showDone?'Hide':'Show'}</button>
    </div>${TASK.showDone? done.map(todoTaskCard).join('') : ''}`:''}`;
}
/* ================= end To-Do ================= */

function closeNavGroups(){ document.querySelectorAll('.navgroup.open').forEach(g=>g.classList.remove('open')); }

function navActivate(b){
  document.querySelectorAll('.tabs button').forEach(x=>x.classList.remove('active'));
  b.classList.add('active');
  const grp=b.closest('.navgroup');
  if(grp){ const gb=grp.querySelector('.grp'); if(gb) gb.classList.add('active'); }
  TAB=b.dataset.tab;
  closeNavGroups();
  if(TAB==='report' && !REPORT.loaded && !REPORT.loading){ render(); loadReport(); return; }
  if(TAB==='etr' && !ETR.loaded && !ETR.loading){ render(); etrLoad(); return; }
  if(TAB==='invrep' && !INVR.loaded && !INVR.loading){ render(); invrLoad(); return; }
  if(TAB==='quotes' && !QUOT.loaded && !QUOT.loading){ render(); quotLoad(); return; }
  if(TAB==='emails' && !EMAIL.loaded && !EMAIL.loadingClients){ render(); emailLoadClients(); return; }
  if(TAB==='todo' && !TASK.loaded && !TASK.loading){ render(); todoLoad(); return; }
  if(TAB==='loans'){ render(); loadLoans(); return; }
  if(TAB==='newquote'){ render(); if(ME.admin && !QB.assignLoaded) qbAssignLoad(); return; }
  if(TAB==='myquotes'){ render(); mqLoad(); return; }
  if(TAB==='settings'){ render(); if(ME.admin && !USERS.loaded) usersLoad(); return; }
  render();
}

/* ===================== Quotes → Zoho (Create group) ===================== */
const EDITABLE_STATUSES = ['local_draft','draft','pending_approval'];
function mqEditable(q){ return EDITABLE_STATUSES.includes(q.status); }
function qbBlank(){ return { id:0, zohoId:'', status:'local_draft', customerId:'', customerName:'', currency:'KES',
           reference:'', subject:'', quoteDate:today(), expiryDate:'',
           items:[{name:'',description:'',qty:1,rate:0,tax:'vat'}], notes:'Looking forward for your business.', terms:'',
           discVal:0, discType:'percent',
           msg:'', err:false, busy:false }; }
let QB = Object.assign(qbBlank(), { assignOpen:false, assignLoaded:false, assignments:[], users:[], aCust:null, aUsers:[] });
let MQ = { quotes:[], loaded:false, loading:false, syncing:false, msg:'', err:false, busyId:0, month:'all', page:1, open:{} };
const MQ_PER_PAGE = 50;
const MONTHS_SHORT = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
function mqMonthKey(q){ return String(q.created_at||q.quote_date||'').slice(0,7); }   /* YYYY-MM */
function mqMonthLabel(k){ if(!k||k.length<7) return k||'—'; const m=parseInt(k.slice(5,7),10); return (MONTHS_SHORT[m-1]||k.slice(5,7))+' '+k.slice(0,4); }
const qesc = s => String(s==null?'':s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
let _qbSearchT=null, _qbAssignT=null;

function qbLineAmt(it){ return (parseFloat(it.qty)||0)*(parseFloat(it.rate)||0); }
function qbSub(){ return QB.items.reduce((s,it)=>s+qbLineAmt(it),0); }
function qbDiscAmt(){ const sub=qbSub(); const v=Math.max(0,parseFloat(QB.discVal)||0);
  return QB.discType==='amount' ? Math.min(v,sub) : sub*v/100; }
function qbTax(){ const sub=qbSub(); if(sub<=0) return 0;
  const taxedBase=QB.items.reduce((s,it)=>s+((it.tax==='none')?0:qbLineAmt(it)),0);
  const taxedAfter=taxedBase - qbDiscAmt()*(taxedBase/sub);
  return Math.max(0,taxedAfter)*(CFG.vat||0.16); }
function qbTotal(){ return qbSub()-qbDiscAmt()+qbTax(); }

function quoteBadge(status){
  const map={
    local_draft:['Draft (local)','#64748B','#F1F4F8'],
    draft:['Draft in Zoho','#64748B','#F1F4F8'],
    pending_approval:['Pending approval','#9A6700','#FFF4D6'],
    approved:['Approved ✓','#0F7A34','#E7F6EC'],
    declined:['Declined','#D64933','#FDECEA'],
    rejected:['Declined','#D64933','#FDECEA'],
    sent:['Sent','#2350C5','#EEF2FE'],
    accepted:['Accepted ✓','#0F7A34','#E7F6EC'],
    invoiced:['Invoiced','#2350C5','#EEF2FE'],
    expired:['Expired','#64748B','#F1F4F8']
  };
  const m=map[status]||[status||'—','#64748B','#F1F4F8'];
  return `<span class="pill" style="color:${m[1]};background:${m[2]}">${m[0]}</span>`;
}

/* ---- New Quote (builder) — laid out like the Zoho estimate form ---- */
function vNewQuote(){
  const cur=QB.currency||'KES';
  const taxOpt=t=>`<option value="vat" ${t!=='none'?'selected':''}>VAT (${Math.round((CFG.vat||0.16)*100)}%)</option><option value="none" ${t==='none'?'selected':''}>No tax</option>`;
  const rows=QB.items.map((it,i)=>`
    <div class="qbrow">
      <div class="qbc-item">
        <input type="text" placeholder="Item name" value="${qesc(it.name)}" oninput="qbItem(${i},'name',this.value)" style="margin-bottom:4px;font-weight:600">
        <input type="text" placeholder="Add a description to your item" value="${qesc(it.description)}" oninput="qbItem(${i},'description',this.value)" style="margin-bottom:0;font-size:11px;color:var(--mute)">
      </div>
      <div class="qbc-qty"><span class="qbc-lab">Qty</span><input type="number" step="0.01" min="0" value="${qesc(it.qty)}" oninput="qbItem(${i},'qty',this.value)" style="margin-bottom:0;text-align:right"></div>
      <div class="qbc-rate"><span class="qbc-lab">Rate</span><input type="number" step="0.01" min="0" value="${qesc(it.rate)}" oninput="qbItem(${i},'rate',this.value)" style="margin-bottom:0;text-align:right"></div>
      <div class="qbc-tax"><span class="qbc-lab">Tax</span><select onchange="qbItem(${i},'tax',this.value)" style="margin-bottom:0">${taxOpt(it.tax)}</select></div>
      <div class="qbc-amt"><span class="qbc-lab">Amount</span><div id="qbAmt${i}" style="font-weight:700;font-size:13px">${fmtn(qbLineAmt(it))}</div></div>
      <button class="qbc-del btn sec" onclick="qbDelRow(${i})" title="Remove">✕</button>
    </div>`).join('');

  const custBlock = QB.customerId
    ? `<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
         <div class="wchip" style="background:#EEF2FE;border-color:#C7D5F5"><b>${qesc(QB.customerName)}</b>
           <span style="cursor:pointer;color:var(--bad);font-weight:700;margin-left:6px" onclick="qbClearCust()">✕</span></div>
         <span class="pill" style="background:#E7F6EC;color:#0F7A34">⦿ ${qesc(cur)}</span></div>`
    : `<input type="text" id="qbCustSearch" placeholder="Search customer name (from Zoho)…" oninput="qbSearch(this.value)" autocomplete="off" style="margin-bottom:0">
       <div id="qbCustResults"></div>`;

  return `
  ${ME.admin?qbAssignPanel():''}
  <h2>${QB.id?'Edit quote':'New quote'}</h2>

  <div class="card" style="background:#FAFBFD">
    <label><span style="color:var(--bad)">*</span> Customer name</label>
    ${custBlock}
    <div class="grid2" style="margin-top:14px">
      <div><label>Quote#</label><input type="text" value="Auto — assigned by Zoho" disabled style="margin-bottom:0;color:var(--mute)"></div>
      <div><label>Reference#</label><input type="text" value="${qesc(QB.reference)}" oninput="QB.reference=this.value" style="margin-bottom:0"></div>
      <div><label>Quote date</label><input type="date" value="${qesc(QB.quoteDate)}" oninput="QB.quoteDate=this.value" style="margin-bottom:0"></div>
      <div><label>Expiry date</label><input type="date" value="${qesc(QB.expiryDate)}" oninput="QB.expiryDate=this.value" style="margin-bottom:0"></div>
    </div>
    <label style="margin-top:14px"><span style="color:var(--bad)">*</span> Subject (required)</label>
    <input type="text" placeholder="Let your customer know what this quote is for" value="${qesc(QB.subject)}" oninput="QB.subject=this.value" style="margin-bottom:0">
  </div>

  <div class="card">
    <div class="qbhead">
      <div class="qbc-item">Item details</div><div class="qbc-qty">Qty</div><div class="qbc-rate">Rate</div>
      <div class="qbc-tax">Tax</div><div class="qbc-amt">Amount</div><div class="qbc-del"></div>
    </div>
    ${rows}
    <button class="btn sec" style="width:auto;padding:7px 12px;font-size:12px;margin-top:10px" onclick="qbAddRow()">⊕ Add new row</button>
  </div>

  <div class="qbsplit">
    <div class="card">
      <label>Customer notes</label>
      <textarea oninput="QB.notes=this.value" style="min-height:60px;font-size:12px;margin-bottom:0">${qesc(QB.notes)}</textarea>
      <label style="margin-top:12px">Terms &amp; conditions</label>
      <textarea placeholder="Enter the terms and conditions of your business to be displayed in your transaction" oninput="QB.terms=this.value" style="min-height:60px;font-size:12px;margin-bottom:0">${qesc(QB.terms)}</textarea>
    </div>
    <div class="card" id="qbTotals" style="background:#FAFBFD">${qbTotalsHtml()}</div>
  </div>

  ${QB.msg?`<div class="${QB.err?'warn':'ok'}" style="margin-bottom:10px">${qesc(QB.msg)}</div>`:''}
  ${QB.zohoId
    ? `<div class="warn" style="background:#FFF4D6;color:#9A6700;margin-bottom:10px;font-size:11.5px">Editing a quote that's awaiting approval in Zoho. Saving updates it in Zoho and re-submits it for approval.</div>
       <button class="btn" style="width:100%" onclick="qbSaveUpdate()" ${QB.busy?'disabled':''}>${QB.busy?'Working…':'Save changes & re-submit to Zoho →'}</button>`
    : `<div class="row" style="gap:8px">
        <button class="btn sec" style="flex:1" onclick="qbSave()" ${QB.busy?'disabled':''}>Save draft</button>
        <button class="btn" style="flex:1" onclick="qbSaveAndPush()" ${QB.busy?'disabled':''}>${QB.busy?'Working…':'Save & push to Zoho →'}</button>
      </div>
      <div class="muted" style="font-size:11px;margin-top:8px">Pushing creates a Zoho estimate and submits it for approval. You'll see Approved/Declined under <b>My Quotes</b>.</div>`}`;
}
function qbTotalsHtml(){
  const cur=QB.currency||'KES';
  return `<div class="row"><b style="color:var(--blue)">Sub total</b><b>${cur} ${fmtn(qbSub())}</b></div>
    <div class="row" style="margin-top:10px;align-items:center"><span class="muted">Discount</span>
      <span style="display:inline-flex;gap:6px;align-items:center">
        <input type="number" step="0.01" min="0" value="${qesc(QB.discVal)}" oninput="qbDisc('discVal',this.value)" style="width:74px;margin-bottom:0;text-align:right">
        <select onchange="qbDisc('discType',this.value)" style="width:auto;margin-bottom:0">
          <option value="percent" ${QB.discType!=='amount'?'selected':''}>%</option>
          <option value="amount" ${QB.discType==='amount'?'selected':''}>${qesc(cur)}</option>
        </select></span>
      <b style="color:var(--bad)">− ${fmtn(qbDiscAmt())}</b></div>
    <div class="row" style="margin-top:10px"><span style="color:var(--blue)">VAT (${Math.round((CFG.vat||0.16)*100)}%)</span><b>${fmtn(qbTax())}</b></div>
    <div class="row" style="margin-top:10px;padding-top:10px;border-top:1px solid var(--line)"><b>Total ( ${qesc(cur)} )</b><b style="color:var(--orange);font-size:17px">${fmtn(qbTotal())}</b></div>`;
}
function qbItem(i,field,val){ if(!QB.items[i])return; QB.items[i][field]=val;
  const a=document.getElementById('qbAmt'+i); if(a) a.textContent=fmtn(qbLineAmt(QB.items[i]));
  const t=document.getElementById('qbTotals'); if(t) t.innerHTML=qbTotalsHtml(); }
function qbDisc(field,val){ QB[field]=val; const t=document.getElementById('qbTotals'); if(t) t.innerHTML=qbTotalsHtml(); }
function qbAddRow(){ QB.items.push({name:'',description:'',qty:1,rate:0,tax:'vat'}); render(); }
function qbDelRow(i){ QB.items.splice(i,1); if(!QB.items.length) QB.items.push({name:'',description:'',qty:1,rate:0,tax:'vat'}); render(); }
function qbClearCust(){ QB.customerId=''; QB.customerName=''; render(); }
function qbSearch(v){ clearTimeout(_qbSearchT); const box=document.getElementById('qbCustResults');
  if((v||'').trim().length<2){ if(box) box.innerHTML=''; return; }
  _qbSearchT=setTimeout(()=>{ fetch('api/customers.php?q='+encodeURIComponent(v),{credentials:'same-origin'})
    .then(r=>r.json()).then(j=>{ const b=document.getElementById('qbCustResults'); if(!b)return;
      if(!j.ok){ b.innerHTML=`<div class="warn" style="margin-top:8px;font-size:11px">${qesc(j.error||'Search failed')}</div>`; return; }
      if(!(j.customers||[]).length){ b.innerHTML=`<div class="muted" style="font-size:11px;padding:8px">No customers match${ME.admin?'':' that are assigned to you'}.</div>`; return; }
      b.innerHTML=`<div style="border:1px solid var(--line);border-radius:9px;margin-top:6px;overflow:hidden">`+
        j.customers.map(c=>`<div class="invrow" onclick="qbPick('${qesc(c.id)}','${qesc(c.name).replace(/'/g,'&#39;')}','${qesc(c.currency||'KES')}')">
          <div style="font-size:12.5px;font-weight:600">${qesc(c.name)}</div><div class="muted">${qesc(c.currency||'KES')} · pick ›</div></div>`).join('')+`</div>`;
    }).catch(e=>{ const b=document.getElementById('qbCustResults'); if(b) b.innerHTML=`<div class="warn" style="font-size:11px">${e}</div>`; }); },300);
}
function qbPick(id,name,cur){ QB.customerId=id; QB.customerName=name; QB.currency=cur||'KES'; render(); }
function qbPayload(){ return { id:QB.id||undefined, zoho_customer_id:QB.customerId, customer_name:QB.customerName,
  currency:QB.currency, reference:QB.reference, subject:QB.subject, quote_date:QB.quoteDate, expiry_date:QB.expiryDate,
  notes:QB.notes, terms:QB.terms, discount_value:QB.discVal, discount_type:QB.discType,
  line_items:QB.items.map(it=>({name:it.name,description:it.description,qty:it.qty,rate:it.rate,tax:it.tax||'vat'})) }; }
function qbReqOk(){
  if(!QB.customerId && !QB.customerName){ QB.msg='Choose a customer.'; QB.err=true; render(); return false; }
  if(!String(QB.subject||'').trim()){ QB.msg='Subject is required.'; QB.err=true; render(); return false; }
  if(!QB.items.some(it=>String(it.name||'').trim())){ QB.msg='Add at least one line item.'; QB.err=true; render(); return false; }
  return true;
}
function qbSave(cb){ if(!qbReqOk())return; QB.busy=true; QB.msg=''; render();
  fetch('api/quotes.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify(Object.assign({action:'save'},qbPayload()))})
  .then(r=>r.json()).then(j=>{ QB.busy=false;
    if(j.ok){ QB.id=j.quote.id; QB.msg='Saved draft #'+j.quote.id+'.'; QB.err=false; MQ.loaded=false; if(cb){cb();return;} render(); }
    else { QB.msg=j.error||'Save failed.'; QB.err=true; render(); }
  }).catch(e=>{ QB.busy=false; QB.msg='Error: '+e; QB.err=true; render(); });
}
function qbSaveAndPush(){ qbSave(()=>qbPush(QB.id)); }
function qbSaveUpdate(){ qbSave(()=>qbUpdate(QB.id)); }
function qbUpdate(id){ QB.busy=true; QB.msg='Updating Zoho…'; QB.err=false; render();
  fetch('api/quote_update.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({id})})
  .then(r=>r.json()).then(j=>{ QB.busy=false;
    if(j.ok){ QB=Object.assign(qbBlank(), { assignOpen:QB.assignOpen, assignLoaded:QB.assignLoaded, assignments:QB.assignments, users:QB.users, aCust:null, aUsers:[] });
      MQ.loaded=false; MQ.msg='Quote updated in Zoho — '+(j.status==='pending_approval'?'re-submitted for approval.':'status: '+j.status+'.')+(j.note?' '+j.note:''); MQ.err=!!j.note;
      navTo('myquotes');
    } else { QB.msg=j.error||'Update failed.'; QB.err=true; render(); }
  }).catch(e=>{ QB.busy=false; QB.msg='Error: '+e; QB.err=true; render(); });
}
function qbPush(id){ QB.busy=true; QB.msg='Pushing to Zoho…'; QB.err=false; render();
  fetch('api/quote_push.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({id})})
  .then(r=>r.json()).then(j=>{ QB.busy=false;
    if(j.ok){ QB=Object.assign(qbBlank(), { assignOpen:QB.assignOpen, assignLoaded:QB.assignLoaded, assignments:QB.assignments, users:QB.users, aCust:null, aUsers:[] });
      MQ.loaded=false; MQ.msg='Quote pushed to Zoho as '+(j.estimate_number||'estimate')+' — '+(j.status==='pending_approval'?'awaiting approval.':'status: '+j.status+'.')+(j.note?' '+j.note:''); MQ.err=!!j.note;
      navTo('myquotes');
    } else { QB.msg=j.error||'Push failed.'; QB.err=true; render(); }
  }).catch(e=>{ QB.busy=false; QB.msg='Error: '+e; QB.err=true; render(); });
}

/* ---- admin: customer → users assignment panel ---- */
function qbAssignPanel(){
  const list = QB.assignments.length
    ? QB.assignments.map(a=>`<div class="invrow" style="cursor:default"><div><div style="font-size:12.5px;font-weight:600">${qesc(a.name||a.id)}</div>
        <div class="muted">${(a.users||[]).map(qesc).join(', ')||'no users'}</div></div>
        <button class="btn sec" style="width:auto;padding:4px 9px;font-size:11px" onclick="qbAEdit('${qesc(a.id)}','${qesc(a.name||'').replace(/'/g,'&#39;')}')">Edit</button></div>`).join('')
    : `<div class="muted" style="font-size:11px;padding:8px">No customers assigned yet.</div>`;
  const editor = QB.aCust ? `<div class="card" style="background:#FAFBFD;margin-top:8px">
      <div class="row" style="margin-bottom:8px"><b style="font-size:12px">${qesc(QB.aCust.name)}</b>
        <span style="cursor:pointer;color:var(--bad);font-weight:700" onclick="QB.aCust=null;render()">✕</span></div>
      <div class="muted" style="font-size:11px;margin-bottom:6px">Tick the app users who can raise quotes for this customer:</div>
      <div style="display:flex;flex-wrap:wrap;gap:6px">${QB.users.map(u=>`<label style="display:inline-flex;align-items:center;gap:6px;background:#fff;border:1px solid var(--line);border-radius:20px;padding:4px 10px;font-size:11.5px">
        <input type="checkbox" ${QB.aUsers.includes(u)?'checked':''} onchange="qbAToggle('${qesc(u)}',this.checked)">${qesc(u)}</label>`).join('')||'<span class="muted" style="font-size:11px">No app users yet.</span>'}</div>
      <button class="btn" style="margin-top:10px;width:auto;padding:7px 14px;font-size:12px" onclick="qbASave()">Save access (${QB.aUsers.length})</button>
    </div>` : '';
  return `<div class="tool" style="margin-bottom:14px"><details ${QB.assignOpen?'open':''} ontoggle="QB.assignOpen=this.open">
    <summary>Customer access <span class="cv">assign Zoho customers to staff</span></summary>
    <div class="body">
      <div class="muted" style="font-size:11px;margin-bottom:8px">Decide which staff can raise quotes for which customers. A customer can go to as many users as you like.</div>
      <input type="text" id="qbAssignSearch" placeholder="Search a Zoho customer to assign…" oninput="qbASearch(this.value)" autocomplete="off">
      <div id="qbAssignResults"></div>
      ${editor}
      <div class="muted" style="font-size:11px;margin:12px 0 4px">Current assignments</div>
      ${list}
    </div></details></div>`;
}
function qbAssignLoad(){ fetch('api/customer_assign.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'list'})})
  .then(r=>r.json()).then(j=>{ if(j.ok){ QB.assignments=j.assignments||[]; QB.users=j.users||[]; QB.assignLoaded=true; if(TAB==='newquote') render(); } }).catch(()=>{}); }
function qbASearch(v){ clearTimeout(_qbAssignT); const box=document.getElementById('qbAssignResults');
  if((v||'').trim().length<2){ if(box) box.innerHTML=''; return; }
  _qbAssignT=setTimeout(()=>{ fetch('api/customers.php?q='+encodeURIComponent(v),{credentials:'same-origin'})
    .then(r=>r.json()).then(j=>{ const b=document.getElementById('qbAssignResults'); if(!b)return;
      if(!j.ok||!(j.customers||[]).length){ b.innerHTML=`<div class="muted" style="font-size:11px;padding:8px">No match.</div>`; return; }
      b.innerHTML=`<div style="border:1px solid var(--line);border-radius:9px;margin-top:6px;overflow:hidden">`+
        j.customers.map(c=>`<div class="invrow" onclick="qbAEdit('${qesc(c.id)}','${qesc(c.name).replace(/'/g,'&#39;')}')">
          <div style="font-size:12.5px;font-weight:600">${qesc(c.name)}</div><div class="muted">assign ›</div></div>`).join('')+`</div>`;
    }).catch(()=>{}); },300);
}
function qbAEdit(id,name){ const ex=QB.assignments.find(a=>a.id===id); QB.aCust={id,name}; QB.aUsers=ex?(ex.users||[]).slice():[]; const b=document.getElementById('qbAssignResults'); if(b)b.innerHTML=''; render(); }
function qbAToggle(u,on){ if(on){ if(!QB.aUsers.includes(u)) QB.aUsers.push(u); } else { QB.aUsers=QB.aUsers.filter(x=>x!==u); } }
function qbASave(){ if(!QB.aCust)return; fetch('api/customer_assign.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'set',zoho_customer_id:QB.aCust.id,customer_name:QB.aCust.name,usernames:QB.aUsers})})
  .then(r=>r.json()).then(j=>{ if(j.ok){ QB.aCust=null; qbAssignLoad(); } else { alert(j.error||'Save failed'); } }).catch(e=>alert(''+e)); }

/* ---- My Quotes ---- */
function mqPreviewHtml(q){
  const its=q.line_items||[];
  const rows=its.map(it=>`<div class="row" style="gap:8px;padding:5px 0;border-bottom:1px dashed var(--line)">
      <div style="flex:1;min-width:0"><div style="font-size:11.5px;font-weight:600;text-transform:uppercase">${qesc(it.name||'')}</div>
        ${it.description?`<div class="muted" style="font-size:10.5px">${qesc(it.description)}</div>`:''}</div>
      <div class="muted" style="font-size:11px;white-space:nowrap">${fmt1(it.qty)} × ${fmtn(it.rate)}${(it.tax==='none')?' · no tax':''}</div>
      <div style="font-weight:600;font-size:11.5px;white-space:nowrap;min-width:74px;text-align:right">${fmtn(it.amount!=null?it.amount:(it.qty*it.rate))}</div>
    </div>`).join('');
  const meta=[];
  if(q.reference) meta.push('Ref: '+qesc(q.reference));
  if(q.subject) meta.push('Subject: '+qesc(q.subject));
  if(q.quote_date) meta.push('Date: '+qesc(q.quote_date));
  if(q.expiry_date) meta.push('Expiry: '+qesc(q.expiry_date));
  return `<div style="margin-top:10px;padding-top:10px;border-top:1px solid var(--line)">
    ${meta.length?`<div class="muted" style="font-size:11px;margin-bottom:6px">${meta.join(' · ')}</div>`:''}
    ${rows||'<div class="muted" style="font-size:11px">No line items.</div>'}
    <div class="row" style="margin-top:8px"><span class="muted" style="font-size:11px">Sub ${fmtn(q.sub_total)}${(q.discount_amount>0)?' · disc −'+fmtn(q.discount_amount):''} · VAT ${fmtn(q.tax_amount)}</span>
      <b style="font-size:12px">${qesc(q.currency||'KES')} ${fmtn(q.total)}</b></div>
    ${q.notes?`<div class="muted" style="font-size:11px;margin-top:6px">📝 ${qesc(q.notes)}</div>`:''}
  </div>`;
}
function vMyQuotes(){
  const all=MQ.quotes||[];
  // month options from the data (newest first)
  const monthKeys=[...new Set(all.map(mqMonthKey).filter(k=>k&&k.length>=7))].sort().reverse();
  const filtered = MQ.month==='all' ? all : all.filter(q=>mqMonthKey(q)===MQ.month);
  const pages=Math.max(1,Math.ceil(filtered.length/MQ_PER_PAGE));
  if(MQ.page>pages) MQ.page=pages; if(MQ.page<1) MQ.page=1;
  const slice=filtered.slice((MQ.page-1)*MQ_PER_PAGE, MQ.page*MQ_PER_PAGE);

  const cards = slice.length ? slice.map(q=>{
    const pushed=!!q.zoho_estimate_id; const isOpen=!!MQ.open[q.id];
    return `<div class="card">
      <div class="row" style="align-items:flex-start"><div>
        <b style="font-size:13.5px">${qesc(q.customer_name||'(no customer)')}</b>
        <div class="muted" style="margin-top:2px">${pushed?('Zoho '+qesc(q.zoho_estimate_number||'')+' · '):''}${ME.admin?('by '+qesc(q.created_by)+' · '):''}${q.line_items.length} item${q.line_items.length===1?'':'s'}</div>
      </div><div style="text-align:right"><b style="color:var(--orange)">${qesc(q.currency||'KES')} ${fmtn(q.total)}</b>
        <div style="margin-top:4px">${quoteBadge(q.status)}</div></div></div>
      <div class="row" style="gap:8px;margin-top:10px;flex-wrap:wrap">
        <button class="btn sec" style="width:auto;padding:6px 12px;font-size:12px" onclick="mqTogglePreview(${q.id})">${isOpen?'Hide ▴':'Preview ▾'}</button>
        ${mqEditable(q)?`<button class="btn sec" style="width:auto;padding:6px 12px;font-size:12px" onclick="mqEdit(${q.id})">Edit</button>`:''}
        ${!pushed?`<button class="btn" style="width:auto;padding:6px 12px;font-size:12px" onclick="mqPush(${q.id})" ${MQ.busyId===q.id?'disabled':''}>${MQ.busyId===q.id?'Pushing…':'Push to Zoho →'}</button>`:''}
        ${pushed?`<button class="btn sec" style="width:auto;padding:6px 12px;font-size:12px" onclick="mqSyncOne(${q.id})" ${MQ.busyId===q.id?'disabled':''}>${MQ.busyId===q.id?'Checking…':'↻ Check status'}</button>`:''}
        <button class="btn sec" style="width:auto;padding:6px 12px;font-size:12px" onclick="mqDelete(${q.id})">Remove</button>
      </div>
      ${isOpen?mqPreviewHtml(q):''}
    </div>`; }).join('')
    : `<div class="card muted" style="text-align:center;padding:22px">${all.length?'No quotes in this month.':'No quotes yet. Make one under <b>New Quote</b>.'}</div>`;

  const monthSel = `<select onchange="mqSetMonth(this.value)" style="width:auto;margin-bottom:0;font-size:12px;padding:6px 10px">
      <option value="all" ${MQ.month==='all'?'selected':''}>All months</option>
      ${monthKeys.map(k=>`<option value="${k}" ${MQ.month===k?'selected':''}>${mqMonthLabel(k)}</option>`).join('')}
    </select>`;
  const pager = pages>1 ? `<div class="row" style="justify-content:center;gap:8px;margin-top:14px">
      <button class="btn sec" style="width:auto;padding:6px 12px;font-size:12px" onclick="mqGoPage(${MQ.page-1})" ${MQ.page<=1?'disabled':''}>‹ Prev</button>
      <span class="muted" style="font-size:12px">Page ${MQ.page} of ${pages}</span>
      <button class="btn sec" style="width:auto;padding:6px 12px;font-size:12px" onclick="mqGoPage(${MQ.page+1})" ${MQ.page>=pages?'disabled':''}>Next ›</button>
    </div>` : '';

  return `<h2>My quotes</h2>
    ${MQ.msg?`<div class="${MQ.err?'warn':'ok'}" style="margin-bottom:10px">${qesc(MQ.msg)}</div>`:''}
    <div class="row" style="margin-bottom:12px;gap:8px;flex-wrap:wrap">
      <span class="muted">${MQ.syncing?'Checking Zoho…':(filtered.length+' quote'+(filtered.length===1?'':'s')+(MQ.month==='all'?'':' · '+mqMonthLabel(MQ.month)))}</span>
      <span style="display:inline-flex;gap:8px;align-items:center;margin-left:auto">${monthSel}
        <button class="btn sec" style="width:auto;padding:6px 12px;font-size:12px" onclick="mqSync()" ${MQ.syncing?'disabled':''}>${MQ.syncing?'Syncing…':'↻ Refresh statuses'}</button></span>
    </div>
    ${cards}
    ${pager}`;
}
function mqTogglePreview(id){ MQ.open[id]=!MQ.open[id]; render(); }
function mqSetMonth(v){ MQ.month=v; MQ.page=1; render(); }
function mqGoPage(n){ MQ.page=n; render(); }
function mqLoad(){ MQ.loading=true;
  fetch('api/quotes.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'list'})})
  .then(r=>r.json()).then(j=>{ MQ.loading=false; if(j.ok){ MQ.quotes=j.quotes||[]; MQ.loaded=true; if(TAB==='myquotes'){ render(); if(MQ.quotes.some(q=>q.zoho_estimate_id)) mqSync(true); } } })
  .catch(()=>{ MQ.loading=false; });
}
function mqSync(silent){ if(MQ.syncing)return; MQ.syncing=true; if(!silent) MQ.msg=''; if(TAB==='myquotes')render();
  fetch('api/quote_sync.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({})})
  .then(r=>r.json()).then(j=>{ MQ.syncing=false;
    if(j.ok){ (j.quotes||[]).forEach(u=>{ const q=MQ.quotes.find(x=>x.id===u.id); if(q) q.status=u.status; }); }
    if(TAB==='myquotes')render();
  }).catch(()=>{ MQ.syncing=false; if(TAB==='myquotes')render(); });
}
function mqSyncOne(id){ MQ.busyId=id; render();
  fetch('api/quote_sync.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({id})})
  .then(r=>r.json()).then(j=>{ MQ.busyId=0; if(j.ok&&j.quotes&&j.quotes[0]){ const q=MQ.quotes.find(x=>x.id===id); if(q)q.status=j.quotes[0].status; } render(); })
  .catch(()=>{ MQ.busyId=0; render(); });
}
function mqPush(id){ MQ.busyId=id; render();
  fetch('api/quote_push.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({id})})
  .then(r=>r.json()).then(j=>{ MQ.busyId=0; if(j.ok){ MQ.msg='Pushed as '+(j.estimate_number||'estimate')+' — '+(j.status==='pending_approval'?'awaiting approval.':'status: '+j.status+'.')+(j.note?' '+j.note:''); MQ.err=!!j.note; MQ.loaded=false; mqLoad(); } else { MQ.msg=j.error||'Push failed.'; MQ.err=true; render(); } })
  .catch(e=>{ MQ.busyId=0; MQ.msg='Error: '+e; MQ.err=true; render(); });
}
function mqEdit(id){ fetch('api/quotes.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'get',id})})
  .then(r=>r.json()).then(j=>{ if(!j.ok){ alert(j.error||'Could not load'); return; } const q=j.quote;
    QB.id=q.id; QB.zohoId=q.zoho_estimate_id||''; QB.status=q.status||'local_draft';
    QB.customerId=q.zoho_customer_id; QB.customerName=q.customer_name; QB.currency=q.currency||'KES';
    QB.reference=q.reference||''; QB.subject=q.subject||''; QB.quoteDate=q.quote_date||today(); QB.expiryDate=q.expiry_date||'';
    QB.items=(q.line_items||[]).map(it=>({name:it.name,description:it.description||'',qty:it.qty,rate:it.rate,tax:it.tax||'vat'})); if(!QB.items.length)QB.items=[{name:'',description:'',qty:1,rate:0,tax:'vat'}];
    QB.notes=q.notes||''; QB.terms=q.terms||''; QB.discVal=q.discount_value||0; QB.discType=q.discount_type||'percent'; QB.msg=''; QB.err=false; navTo('newquote');
  }).catch(e=>alert(''+e)); }
function mqDelete(id){ if(!confirm('Remove this quote from the app? (If it was pushed, it stays in Zoho.)'))return;
  fetch('api/quotes.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'delete',id})})
  .then(r=>r.json()).then(j=>{ if(j.ok){ MQ.quotes=MQ.quotes.filter(q=>q.id!==id); render(); } else alert(j.error||'Delete failed'); }).catch(e=>alert(''+e)); }

function vJobCards(){ return `<h2>Job cards</h2>
  <div class="card" style="text-align:center;padding:26px">
    <div style="font-size:30px;margin-bottom:6px">🧾</div>
    <b style="font-size:14px">Job cards are coming next</b>
    <div class="muted" style="margin-top:6px;max-width:420px;margin-left:auto;margin-right:auto">This will use the same builder + Zoho engine as quotes, so jobs can be raised and tracked here too.</div>
  </div>`; }

/* programmatic tab switch (used after push / edit) */
function navTo(tab){ const b=document.querySelector('.tabs button[data-tab="'+tab+'"]'); if(b){ navActivate(b); } else { TAB=tab; render(); } }

/* direct tabs + submenu tabs */
document.querySelectorAll('.tabs button[data-tab]').forEach(b=>b.onclick=()=>navActivate(b));
/* external links (e.g. Audrey) */
document.querySelectorAll('.tabs button[data-ext]').forEach(b=>b.onclick=()=>{ window.open(b.dataset.ext,'_blank'); closeNavGroups(); });
/* group headers toggle their dropdown */
document.querySelectorAll('.tabs .navgroup .grp').forEach(g=>g.onclick=(e)=>{
  e.stopPropagation();
  const ng=g.closest('.navgroup'); const wasOpen=ng.classList.contains('open');
  closeNavGroups();
  if(!wasOpen) ng.classList.add('open');
});
/* click anywhere else closes open dropdowns */
document.addEventListener('click',(e)=>{ if(!e.target.closest('.navgroup')) closeNavGroups(); });

/* ---- Material-style ripple on button taps (respects reduced-motion) ---- */
document.addEventListener('click', function(e){
  const t = e.target.closest('.btn, .tabs button');
  if(!t) return;
  if(window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches) return;
  const r = t.getBoundingClientRect();
  const size = Math.max(r.width, r.height);
  const span = document.createElement('span');
  span.className = 'ripple';
  span.style.width = span.style.height = size + 'px';
  span.style.left = (e.clientX - r.left - size/2) + 'px';
  span.style.top  = (e.clientY - r.top  - size/2) + 'px';
  t.appendChild(span);
  setTimeout(()=>span.remove(), 600);
}, true);

applyPerms();
loadDeployments();
loadLoans();
loadCacheMeta();
loadBackupStatus();
loadDashTasks();
</script>
</body></html>

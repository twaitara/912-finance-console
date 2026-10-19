<?php
/**
 * Event Preparation Checklist — shared, multi-user, mobile-first.
 *
 * Flow:
 *   1. On open you enter your name. That name is your identity (remembered on
 *      this device) and is shown while you work.
 *   2. Ticks are shared. When anyone marks an item done it is recorded with
 *      their name; everyone sees who did what, updated live.
 *
 * Vanilla PHP + a flat-file store (see api.php). No database, no password.
 * Dark professional theme (912 system): Work Sans + Manrope, White Buddha on
 * warm charcoal, Middle Orange accent, flat hairline surfaces.
 */

require __DIR__ . '/checklist_data.php';
$total_items = checklist_main_count();

$check_svg   = '<svg class="tick" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>';
$chevron_svg = '<svg class="chev" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#1c1a18">
<title>Event Checklist</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Work+Sans:wght@300;400;500;600;700&family=Manrope:wght@400;500;600&display=swap" rel="stylesheet">
<style>
  :root{
    --bg:#1c1a18;
    --ink:#F0F4FF;          /* White Buddha */
    --muted:#9c968d;
    --faint:#6b665f;
    --line:rgba(240,244,255,.10);
    --line-2:rgba(240,244,255,.17);
    --accent:#FF8F33;       /* Middle Orange */
    --accent-dim:rgba(255,143,51,.14);
    --online:#56c07a;
    --hover:rgba(240,244,255,.04);
    --display:'Work Sans',system-ui,-apple-system,Segoe UI,sans-serif;
    --sans:'Manrope',system-ui,-apple-system,Segoe UI,sans-serif;
  }
  *{box-sizing:border-box}
  html{-webkit-text-size-adjust:100%}
  body{
    margin:0;color:var(--ink);background:var(--bg);
    background-image:radial-gradient(130% 80% at 50% -10%,rgba(255,143,51,.06),transparent 60%);
    background-attachment:fixed;
    font-family:var(--sans);font-size:16px;line-height:1.6;
    -webkit-font-smoothing:antialiased;text-rendering:optimizeLegibility;
  }
  ::selection{background:rgba(255,143,51,.28);color:#fff}
  .wrap{max-width:720px;margin:0 auto;padding:22px 22px 120px}

  /* ---- Name gate ---- */
  .gate{position:fixed;inset:0;z-index:50;background:var(--bg);
    background-image:radial-gradient(120% 70% at 50% 0%,rgba(255,143,51,.10),transparent 60%);
    display:flex;align-items:center;justify-content:center;padding:28px}
  .gate.hidden{display:none}
  .gate .inner{width:100%;max-width:400px}
  .gate .eyebrow{color:var(--accent)}
  .gate h1{font-family:var(--display);font-weight:300;font-size:clamp(30px,8vw,44px);line-height:1.05;
    letter-spacing:-.015em;margin:14px 0 10px}
  .gate p{color:var(--muted);font-size:15px;margin:0 0 26px}
  .gate form{display:flex;flex-direction:column;gap:12px}
  .gate input{font-family:var(--sans);font-size:17px;color:var(--ink);background:rgba(240,244,255,.05);
    border:1px solid var(--line-2);border-radius:10px;padding:15px 16px;width:100%}
  .gate input::placeholder{color:var(--faint)}
  .gate input:focus{outline:none;border-color:var(--accent);background:rgba(255,143,51,.06)}
  .gate button{font-family:var(--display);font-weight:600;font-size:15px;letter-spacing:.02em;
    color:#1c1a18;background:var(--accent);border:0;border-radius:8px;padding:15px 18px;cursor:pointer;
    transition:filter .15s}
  .gate button:hover{filter:brightness(1.07)}
  .gate .err{color:#ff9a7a;font-size:13.5px;min-height:1.2em;margin:0}
  .gate .err.demo{color:var(--accent);font-size:14px;line-height:1.5;font-weight:500}
  .gate .seen{margin-top:22px;font-size:13px;color:var(--faint);line-height:1.7}
  .gate .seen b{color:var(--muted);font-weight:500}
  .gate .seen .demoline{color:var(--accent);font-family:var(--display);font-weight:600;letter-spacing:.02em}

  .eyebrow{font-family:var(--display);font-weight:700;font-size:11.5px;letter-spacing:.28em;
    text-transform:uppercase;color:var(--accent)}

  /* ---- Logged-in bar (sticky) ---- */
  .userbar{position:sticky;top:0;z-index:20;margin:0 -22px 24px;padding:12px 22px;
    background:rgba(28,26,24,.86);backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px);
    border-bottom:1px solid var(--line);display:flex;align-items:center;gap:12px}
  .whoami{display:flex;align-items:center;gap:9px;min-width:0}
  .avatar{width:30px;height:30px;border-radius:50%;flex:none;display:grid;place-items:center;
    background:var(--accent);color:#1c1a18;font-family:var(--display);font-weight:700;font-size:13px}
  .whoami .lbl{min-width:0}
  .whoami .lbl small{display:block;font-size:10.5px;letter-spacing:.14em;text-transform:uppercase;color:var(--faint);line-height:1.3}
  .whoami .lbl b{font-family:var(--display);font-weight:600;font-size:14.5px;color:var(--ink);
    white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:40vw;display:block}
  .userbar .sp{flex:1}
  .roster{display:flex;align-items:center;gap:7px;cursor:default}
  .roster .stack{display:flex}
  .roster .chip{width:28px;height:28px;border-radius:50%;margin-left:-8px;border:2px solid var(--bg);
    display:grid;place-items:center;font-family:var(--display);font-weight:600;font-size:12px;
    background:#3a3732;color:var(--ink);position:relative}
  .roster .chip:first-child{margin-left:0}
  .roster .chip.on{background:#2f3b33}
  .roster .chip .dot{position:absolute;right:-1px;bottom:-1px;width:9px;height:9px;border-radius:50%;
    background:var(--online);border:2px solid var(--bg)}
  .roster .more{font-size:12px;color:var(--muted);margin-left:4px}
  .switch{font-family:var(--display);font-size:12px;font-weight:500;color:var(--muted);background:0;border:0;
    cursor:pointer;letter-spacing:.02em;padding:4px 2px}
  .switch:hover{color:var(--accent)}
  .ubtns{display:flex;align-items:center;gap:10px;flex:none}
  .logout{font-family:var(--display);font-size:12px;font-weight:600;color:var(--accent);background:0;
    border:1px solid var(--line-2);border-radius:8px;padding:6px 12px;cursor:pointer;letter-spacing:.02em;
    transition:border-color .18s,background .18s}
  .logout:hover{border-color:var(--accent);background:rgba(255,143,51,.1)}
  .demostrip{display:flex;align-items:center;gap:8px;margin:-4px 0 22px;font-family:var(--display);
    font-size:12.5px;font-weight:500;color:var(--muted);letter-spacing:.01em}
  .demostrip .ld{width:7px;height:7px;border-radius:50%;background:var(--online);flex:none}
  .demostrip.full .ld{background:var(--accent)}
  .demostrip b{color:var(--accent);font-weight:600}

  /* ---- Masthead ---- */
  .mast h1{font-family:var(--display);font-weight:300;font-size:clamp(32px,7vw,52px);
    line-height:1.05;letter-spacing:-.015em;margin:4px 0 0;color:var(--ink)}
  .mast h1 input{font:inherit;color:inherit;background:transparent;border:0;border-bottom:1.5px solid var(--line);
    padding:0 0 7px;width:100%;display:block;transition:border-color .2s}
  .mast h1 input::placeholder{color:#4f4a44}
  .mast h1 input:hover{border-bottom-color:var(--line-2)}
  .mast h1 input:focus{outline:none;border-bottom-color:var(--accent)}
  .metaline{display:flex;flex-wrap:wrap;gap:0 11px;align-items:baseline;margin-top:20px;
    font-family:var(--sans);font-size:15px;color:var(--muted)}
  .metaline input{font:inherit;color:var(--ink);background:transparent;border:0;border-bottom:1px solid var(--line);
    padding:4px 2px;transition:border-color .2s}
  .metaline input::placeholder{color:var(--faint)}
  .metaline input:hover{border-bottom-color:var(--line-2)}
  .metaline input:focus{outline:none;border-bottom-color:var(--accent)}
  .edithint{display:flex;align-items:center;gap:7px;margin-top:16px;font-size:12.5px;color:var(--faint);letter-spacing:.01em}
  .edithint svg{width:13px;height:13px;flex:none;fill:none;stroke:var(--accent);stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}
  .metaline input[type=date]{color-scheme:dark}
  .metaline .dot{color:var(--faint)}
  .metaline .pax{width:4.5ch}
  .metaline .date{width:15ch}
  .metaline .venue{min-width:12ch}

  /* ---- Progress ---- */
  .progress{margin:40px 0 6px}
  .progress .row{display:flex;align-items:flex-end;justify-content:space-between;margin-bottom:14px}
  .progress .pct{font-family:var(--display);font-weight:300;font-size:44px;line-height:.9;letter-spacing:-.02em}
  .progress .pct b{color:var(--accent);font-weight:300}
  .progress .label{font-size:13px;color:var(--muted);letter-spacing:.01em;padding-bottom:4px;text-align:right}
  .track{height:3px;background:var(--line);border-radius:2px;position:relative;overflow:hidden}
  .track>span{position:absolute;inset:0 auto 0 0;width:0;background:var(--accent);
    transition:width .5s cubic-bezier(.22,1,.36,1)}

  /* ---- Nav ---- */
  nav{display:flex;gap:26px;margin:44px 0 8px;border-bottom:1px solid var(--line);
    overflow-x:auto;scrollbar-width:none;-webkit-overflow-scrolling:touch}
  nav::-webkit-scrollbar{display:none}
  nav button{appearance:none;border:0;background:0;font-family:var(--display);cursor:pointer;
    font-size:12px;letter-spacing:.16em;text-transform:uppercase;font-weight:600;color:var(--faint);
    padding:0 0 15px;white-space:nowrap;position:relative;transition:color .2s}
  nav button:hover{color:var(--muted)}
  nav button.active{color:var(--ink)}
  nav button.active::after{content:"";position:absolute;left:0;right:0;bottom:-1px;height:2px;background:var(--accent)}

  .panel{display:none;animation:fade .4s ease both}
  .panel.active{display:block}
  @keyframes fade{from{opacity:0;transform:translateY(5px)}to{opacity:1;transform:none}}

  /* ---- Checklist ---- */
  .section{border-bottom:1px solid var(--line)}
  .section > .head{display:flex;align-items:center;gap:15px;padding:24px 2px 20px;cursor:pointer;user-select:none}
  .section .tickbar{width:26px;height:3px;background:var(--accent);flex:none;opacity:.9;border-radius:2px}
  .section .name{font-family:var(--display);font-weight:600;font-size:18.5px;letter-spacing:-.005em;flex:1;line-height:1.2}
  .section .count{font-family:var(--display);font-size:12px;color:var(--faint);font-weight:500;
    font-variant-numeric:tabular-nums;letter-spacing:.04em;white-space:nowrap}
  .section .count b{color:var(--accent);font-weight:600}
  .section .chev{width:16px;height:16px;flex:none;color:var(--faint);transition:transform .25s}
  .section .chev path{fill:none;stroke:currentColor;stroke-width:1.6;stroke-linecap:round;stroke-linejoin:round}
  .section.collapsed .chev{transform:rotate(-90deg)}
  .section .items{padding-bottom:14px;overflow:hidden}
  .section.collapsed .items{display:none}

  .item{display:flex;align-items:flex-start;gap:14px;padding:12px;margin:0 -12px;
    border-radius:10px;cursor:pointer;transition:background .15s;min-height:44px}
  .item:active{background:var(--hover)}
  @media(hover:hover){.item:hover{background:var(--hover)}}
  .item input{position:absolute;opacity:0;width:0;height:0}
  .item .box{width:22px;height:22px;flex:none;margin-top:1px;border:1.5px solid var(--line-2);
    border-radius:5px;display:grid;place-items:center;transition:.18s;background:transparent}
  .item .box .tick{width:16px;height:16px;opacity:0;transform:scale(.5);transition:.18s}
  .item .box .tick path{fill:none;stroke:#1c1a18;stroke-width:2.6;stroke-linecap:round;stroke-linejoin:round}
  .item .body{min-width:0;flex:1}
  .item .txt{font-size:15.5px;letter-spacing:-.003em;color:var(--ink);transition:color .2s;display:block}
  .item .by{display:none;margin-top:4px;font-family:var(--display);font-size:11.5px;font-weight:500;
    letter-spacing:.02em;color:var(--accent)}
  .item .by svg{width:11px;height:11px;vertical-align:-1px;margin-right:3px;fill:none;stroke:currentColor;stroke-width:2.4;stroke-linecap:round;stroke-linejoin:round}
  .item.done .box{background:var(--accent);border-color:var(--accent)}
  .item.done .box .tick{opacity:1;transform:scale(1)}
  .item.done .txt{color:var(--muted)}
  .item.done .by{display:block}
  .item.mine.done .by{color:var(--muted)}

  /* ---- Schedule ---- */
  .tl{display:grid;grid-template-columns:62px 1fr;gap:20px;padding:22px 2px;border-bottom:1px solid var(--line)}
  .tl .t{font-family:var(--display);font-weight:500;font-size:19px;color:var(--accent);line-height:1;padding-top:2px;
    font-variant-numeric:tabular-nums}
  .tl .b b{display:block;font-family:var(--display);font-weight:600;font-size:16px;margin-bottom:5px;color:var(--ink)}
  .tl .b p{margin:0;color:var(--muted);font-size:14.5px;line-height:1.55}

  /* ---- Tables ---- */
  table{width:100%;border-collapse:collapse}
  th{font-family:var(--display);font-size:11px;letter-spacing:.16em;text-transform:uppercase;color:var(--faint);
    font-weight:600;text-align:left;padding:0 0 15px;border-bottom:1px solid var(--line-2)}
  th.r,td.r{text-align:right;white-space:nowrap}
  td{padding:16px 0;border-bottom:1px solid var(--line);font-size:15px;vertical-align:top}
  td.role{font-family:var(--display);font-weight:600;color:var(--ink)}
  td.sub{color:var(--muted)}
  td.num{font-family:var(--display);font-variant-numeric:tabular-nums;color:var(--accent);font-weight:500;white-space:nowrap}

  .lead{font-family:var(--display);font-weight:300;font-size:19px;line-height:1.5;color:var(--ink);
    margin:0 0 28px;letter-spacing:-.01em}
  .foot{margin-top:28px;font-size:13.5px;color:var(--muted);line-height:1.6;padding-left:16px;
    border-left:2px solid var(--accent)}
  .signoff .item .box{border-radius:50%}

  /* ---- Tools ---- */
  .tools{display:flex;gap:22px;align-items:center;margin-top:60px;padding-top:24px;border-top:1px solid var(--line)}
  .tools button{appearance:none;border:0;background:0;font-family:var(--display);cursor:pointer;color:var(--muted);
    font-size:13px;font-weight:500;letter-spacing:.02em;display:inline-flex;align-items:center;gap:8px;transition:color .2s}
  .tools button:hover{color:var(--ink)}
  .tools button svg{width:15px;height:15px;fill:none;stroke:currentColor;stroke-width:1.6;stroke-linecap:round;stroke-linejoin:round}
  .tools .sp{flex:1}
  .sync{font-size:12px;color:var(--faint);letter-spacing:.02em;display:inline-flex;align-items:center;gap:7px}
  .sync .led{width:7px;height:7px;border-radius:50%;background:var(--online);transition:background .2s}
  .sync.off .led{background:#c4553f}

  /* ---- 912 credit (conspicuous) ---- */
  .credit{margin-top:40px;padding-top:28px;border-top:1px solid var(--line);
    display:flex;justify-content:center}
  .credit a{display:inline-flex;align-items:center;gap:12px;text-decoration:none;
    padding:12px 20px;border:1px solid var(--line-2);border-radius:14px;
    background:rgba(255,143,51,.05);transition:.18s}
  .credit a:hover{border-color:var(--accent);background:rgba(255,143,51,.1)}
  .credit .dev{font-family:var(--display);font-size:10.5px;font-weight:600;letter-spacing:.22em;
    text-transform:uppercase;color:var(--faint);line-height:1.3}
  .credit .brand{font-family:var(--display);font-weight:700;font-size:26px;color:var(--accent);
    letter-spacing:.01em;line-height:1}
  .credit .meta{display:flex;flex-direction:column;gap:2px}
  .credit .tag{font-family:var(--sans);font-size:12.5px;color:var(--muted);line-height:1.3}
  .credit a:hover .dev{color:var(--muted)}
  .gate .credit{margin-top:34px;padding-top:0;border-top:0}

  /* ---- delight (evidence-based micro-feedback) ---- */
  .pcap{font-family:var(--display);font-size:13px;font-weight:500;color:var(--accent);
    letter-spacing:.02em;margin-top:13px;min-height:1.2em;transition:opacity .3s}
  @keyframes pop{0%{transform:scale(1)}42%{transform:scale(1.22)}100%{transform:scale(1)}}
  .item.just .box{animation:pop .36s cubic-bezier(.34,1.56,.64,1)}
  @keyframes ping{0%{box-shadow:0 0 0 0 rgba(255,143,51,.55)}100%{box-shadow:0 0 0 13px rgba(255,143,51,0)}}
  .item.peer .box{animation:ping .95s ease-out}
  .item.peer .by{animation:flash 1.2s ease-out}
  @keyframes flash{0%,100%{color:var(--muted)}25%{color:var(--accent)}}
  .section .tickbar{transition:box-shadow .3s,opacity .3s}
  .section.complete .tickbar{box-shadow:0 0 11px 1px rgba(255,143,51,.6);opacity:1}
  .section.complete .count b{color:var(--online)}
  .confetti{position:fixed;inset:0;pointer-events:none;z-index:40;overflow:hidden}
  .confetti i{position:absolute;top:-14px;width:7px;height:13px;opacity:.95;border-radius:1px;
    animation:fall linear forwards}
  @keyframes fall{to{transform:translateY(106vh) rotate(560deg);opacity:0}}
  @media (prefers-reduced-motion: reduce){
    .item.just .box,.item.peer .box,.item.peer .by{animation:none}
    .confetti{display:none}
    .panel{animation:none}
  }

  @media(max-width:560px){
    .wrap{padding:18px 18px 110px}
    .userbar{margin:0 -18px 20px;padding:11px 18px;gap:10px}
    nav{gap:20px}
    .tl{grid-template-columns:52px 1fr;gap:16px}
    .whoami .lbl b{max-width:30vw}
    .switch{display:none}
    .roster .chip{width:26px;height:26px}
  }

  @media print{
    body{background:#fff;background-image:none;color:#111}
    :root{--ink:#111;--muted:#555;--faint:#888;--line:#e5e5e5;--line-2:#ccc;--hover:transparent}
    .gate,nav,.tools,.userbar,.section .chev,.track{display:none !important}
    .panel{display:block !important}
    .panel + .panel{margin-top:40px}
    .section.collapsed .items{display:block !important}
    .item{break-inside:avoid}
    .item .box .tick path{stroke:#fff}
    .wrap{padding:0;max-width:none}
  }
</style>
</head>
<body>

<!-- Step 1: who are you -->
<div class="gate" id="gate">
  <div class="inner">
    <div class="eyebrow">Event Checklist</div>
    <h1>Who's planning?</h1>
    <p>Enter your name to begin. It stays with you on this device, and your ticks are shared with the team.</p>
    <form id="gateForm" autocomplete="off">
      <input id="nameInput" type="text" placeholder="Your name" maxlength="40" autocomplete="name"
             autocapitalize="words" enterkeyhint="go" required>
      <button type="submit">Continue</button>
      <p class="err" id="gateErr"></p>
    </form>
    <div class="seen" id="gateSeen"></div>
    <div class="credit">
      <a href="https://nineonetwo.co.ke" target="_blank" rel="noopener">
        <span class="dev">Developed&nbsp;by</span>
        <span class="brand">912</span>
      </a>
    </div>
  </div>
</div>

<div class="wrap" id="app" hidden>

  <!-- Logged-in user + live roster -->
  <div class="userbar">
    <div class="whoami">
      <div class="avatar" id="meAvatar">?</div>
      <div class="lbl"><small>Signed in</small><b id="meName">—</b></div>
    </div>
    <div class="sp"></div>
    <div class="roster" id="roster" title="People using this checklist"></div>
    <div class="ubtns">
      <button class="switch" id="switchBtn" title="Sign in as someone else">Switch</button>
      <button class="logout" id="logoutBtn" title="Log out completely">Log out</button>
    </div>
  </div>

  <div class="demostrip" id="demoStrip"></div>

  <header class="mast">
    <h1><input id="m_name" placeholder="Name your event" autocomplete="off" spellcheck="false"></h1>
    <div class="metaline">
      <input class="date" id="m_date" type="date" aria-label="Event date">
      <span class="dot">·</span>
      <input class="venue" id="m_venue" placeholder="Venue" autocomplete="off">
      <span class="dot">·</span>
      <input class="pax" id="m_pax" type="number" min="1" placeholder="180" aria-label="Guests">
      <span>guests</span>
    </div>

    <p class="edithint"><svg viewBox="0 0 24 24"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 013 3L7 19l-4 1 1-4 12.5-12.5z"/></svg>Anyone signed in can set the event name, date and venue — saved and shared with everyone.</p>

    <div class="progress">
      <div class="row">
        <div class="pct" id="pct">0<b>%</b></div>
        <div class="label"><span id="done">0</span> of <?= $total_items ?> complete</div>
      </div>
      <div class="track"><span id="bar"></span></div>
      <div class="pcap" id="pcap"></div>
    </div>
  </header>

  <nav id="nav">
    <button data-tab="checklist" class="active">Checklist</button>
    <button data-tab="schedule">Schedule</button>
    <button data-tab="staffing">Staffing</button>
    <button data-tab="quantities">Quantities</button>
    <button data-tab="signoff">Sign-off</button>
  </nav>

  <!-- CHECKLIST -->
  <section class="panel active" id="panel-checklist">
    <?php foreach ($SECTIONS as $letter => $sec): $n = count($sec['items']); ?>
      <div class="section" data-section="<?= $letter ?>">
        <div class="head">
          <span class="tickbar"></span>
          <span class="name"><?= htmlspecialchars($sec['title']) ?></span>
          <span class="count"><b class="cdone">0</b> / <?= $n ?></span>
          <?= $chevron_svg ?>
        </div>
        <div class="items">
          <?php foreach ($sec['items'] as $i => $text): $id = item_id($letter, $i); ?>
            <label class="item" for="<?= $id ?>" data-item="<?= $id ?>">
              <input type="checkbox" id="<?= $id ?>" class="chk" data-section="<?= $letter ?>">
              <span class="box"><?= $check_svg ?></span>
              <span class="body">
                <span class="txt"><?= htmlspecialchars($text) ?></span>
                <span class="by"></span>
              </span>
            </label>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </section>

  <!-- SCHEDULE -->
  <section class="panel" id="panel-schedule">
    <p class="lead">A working timeline for the day. Confirm the real start time and supplier access, then adjust.</p>
    <?php foreach ($SCHEDULE as $r): ?>
      <div class="tl"><div class="t"><?= htmlspecialchars($r[0]) ?></div>
        <div class="b"><b><?= htmlspecialchars($r[1]) ?></b><p><?= htmlspecialchars($r[2]) ?></p></div></div>
    <?php endforeach; ?>
  </section>

  <!-- STAFFING -->
  <section class="panel" id="panel-staffing">
    <p class="lead">Suggested team for a mid-size indoor event. Combine roles where the day allows.</p>
    <table>
      <thead><tr><th>Role</th><th class="r">Number</th><th>Responsibility</th></tr></thead>
      <tbody>
        <?php foreach ($STAFFING as $r): ?>
          <tr><td class="role"><?= htmlspecialchars($r[0]) ?></td><td class="r num"><?= htmlspecialchars($r[1]) ?></td><td class="sub"><?= htmlspecialchars($r[2]) ?></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <p class="foot">Appoint one person to make final decisions on the day. Staff report to their team leader, not to several people at once.</p>
  </section>

  <!-- QUANTITIES -->
  <section class="panel" id="panel-quantities">
    <p class="lead">Opening estimates, not final orders. Confirm catering numbers and dietary needs with the caterer before ordering.</p>
    <table>
      <thead><tr><th>Item</th><th>Planning quantity</th></tr></thead>
      <tbody>
        <?php foreach ($QUANTITIES as $r): ?>
          <tr><td class="role"><?= htmlspecialchars($r[0]) ?></td><td class="sub"><?= htmlspecialchars($r[1]) ?></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <p class="foot">Where the layout allows, split registration and food service across several stations to avoid queues.</p>
  </section>

  <!-- SIGN-OFF -->
  <section class="panel signoff" id="panel-signoff">
    <p class="lead">The day before — confirm each of these, then brief the team.</p>
    <?php foreach ($SIGNOFF as $i => $text): $id = 'so_' . $i; ?>
      <label class="item" for="<?= $id ?>" data-item="<?= $id ?>">
        <input type="checkbox" id="<?= $id ?>" class="chk-so">
        <span class="box"><?= $check_svg ?></span>
        <span class="body"><span class="txt"><?= htmlspecialchars($text) ?></span><span class="by"></span></span>
      </label>
    <?php endforeach; ?>
    <p class="foot">Have each team leader report green (ready), amber (at risk) or red (not ready) during the final briefing.</p>
  </section>

  <div class="tools">
    <button id="printBtn"><svg viewBox="0 0 24 24"><path d="M6 9V3h12v6M6 18H4v-6h16v6h-2M8 14h8v7H8z"/></svg>Print</button>
    <span class="sp"></span>
    <span class="sync" id="sync"><span class="led"></span><span id="syncTxt">Live</span></span>
  </div>

  <footer class="credit">
    <a href="https://nineonetwo.co.ke" target="_blank" rel="noopener" title="Visit Nine One Two Holdings">
      <span class="dev">Developed&nbsp;by</span>
      <span class="brand">912</span>
      <span class="meta"><span class="tag">Nine One Two Holdings</span><span class="tag">nineonetwo.co.ke</span></span>
    </a>
  </footer>

</div>

<script>
(function(){
  "use strict";
  var API='/elichecklist/api.php';
  var NAME_KEY='eliChecklist.name';
  var me = (localStorage.getItem(NAME_KEY)||'').trim();
  var state = {meta:{},items:{},users:[],rev:-1};
  var MAXU=2;   // demo cap, mirrored from the server
  var byTick = '<svg viewBox="0 0 24 24"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>';

  function esc(s){ return (s||'').replace(/[&<>"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];}); }
  function initials(n){ n=(n||'').trim(); if(!n) return '?';
    var p=n.split(/\s+/); return (p[0][0]+(p.length>1?p[p.length-1][0]:'')).toUpperCase(); }
  function onlineUsers(){ return (state.users||[]).filter(function(x){return x.online;}); }

  function api(params){
    return fetch(API,{method:'POST',headers:{'Content-Type':'application/json'},
      body:JSON.stringify(params)}).then(function(r){return r.json();});
  }

  /* ---------- Name gate ---------- */
  var gate=document.getElementById('gate'), app=document.getElementById('app');
  var nameInput=document.getElementById('nameInput'), gateErr=document.getElementById('gateErr');

  function showGate(){
    gate.classList.remove('hidden'); app.hidden=true;
    if(timer){ clearInterval(timer); timer=null; }   // stop syncing while signed out
    nameInput.value=me||''; gateErr.textContent='';
    setTimeout(function(){ nameInput.focus(); },60);
    // show who's already here, if we can
    api({action:'state'}).then(function(res){
      if(res && res.ok){ state=res.state; if(res.max)MAXU=res.max; renderSeen(); }
    }).catch(function(){});
  }
  function renderSeen(){
    var el=document.getElementById('gateSeen');
    var on=onlineUsers();
    var names=on.map(function(x){return esc(x.name);}).join(', ');
    var full=on.length>=MAXU;
    var html='<span class="demoline">Live demo · '+on.length+'/'+MAXU+' in use</span>';
    if(on.length) html+='<br>Already here: <b>'+names+'</b>';
    else html+='<br>Be the first to sign in.';
    if(full) html+='<br>Both spots are taken right now — try again shortly.';
    el.innerHTML=html;
  }
  document.getElementById('gateForm').addEventListener('submit',function(e){
    e.preventDefault();
    var n=nameInput.value.trim();
    if(!n){ gateErr.textContent='Please enter your name.'; return; }
    gateErr.textContent='';
    api({action:'join',user:n}).then(function(res){
      if(res&&res.max) MAXU=res.max;
      if(!res||!res.ok){
        gateErr.textContent=(res&&res.error)||'Could not sign in. Try again.';
        gateErr.classList.toggle('demo', !!(res&&res.demo));
        if(res&&res.demo){ api({action:'state'}).then(function(r){ if(r&&r.ok){ state=r.state; if(r.max)MAXU=r.max; renderSeen(); } }).catch(function(){}); }
        return;
      }
      gateErr.classList.remove('demo');
      me=n; localStorage.setItem(NAME_KEY,me);
      state=res.state; enterApp();
    }).catch(function(){ gateErr.textContent='Network error. Check your connection.'; });
  });

  document.getElementById('switchBtn').addEventListener('click',function(){ showGate(); });
  document.getElementById('logoutBtn').addEventListener('click',function(){
    if(!confirm('Log out completely? You will be removed from the list and returned to the sign-in screen.')) return;
    if(timer){ clearInterval(timer); timer=null; }
    api({action:'leave',user:me}).catch(function(){});   // leave the shared roster
    try{ localStorage.removeItem(NAME_KEY); }catch(e){}
    me=''; seen=null; prevPct=null;
    showGate();
  });

  /* ---------- App ---------- */
  function enterApp(){
    gate.classList.add('hidden'); app.hidden=false;
    document.getElementById('meName').textContent=me;
    document.getElementById('meAvatar').textContent=initials(me);
    render(); startSync();
  }

  function render(){ renderMeta(); renderItems(); renderProgress(); renderRoster(); }

  function renderMeta(){
    ['name','date','venue','pax'].forEach(function(k){
      var el=document.getElementById('m_'+k); if(!el) return;
      if(document.activeElement===el) return;           // don't stomp what someone's typing
      var v=(state.meta&&state.meta[k]!=null)?state.meta[k]:'';
      if(el.value!==v) el.value=v;
    });
  }

  var seen=null;  // id -> who, to detect teammates' new ticks between renders
  function pulsePeer(lab){ lab.classList.remove('peer'); void lab.offsetWidth; lab.classList.add('peer');
    setTimeout(function(){ lab.classList.remove('peer'); },1000); }
  function renderItems(){
    var items=state.items||{}; var newSeen={};
    document.querySelectorAll('.item').forEach(function(lab){
      var id=lab.getAttribute('data-item'); var rec=items[id];
      var cb=lab.querySelector('input'); var by=lab.querySelector('.by');
      if(rec){
        newSeen[id]=rec.by||'';
        lab.classList.add('done'); if(cb) cb.checked=true;
        var mine=(rec.by||'').toLowerCase()===me.toLowerCase();
        lab.classList.toggle('mine',mine);
        by.innerHTML=byTick+(mine?'You':esc(rec.by));
        if(seen && !seen[id] && !mine) pulsePeer(lab);   // a teammate just ticked this
      }else{
        lab.classList.remove('done','mine'); if(cb) cb.checked=false; by.textContent='';
      }
    });
    seen=newSeen;
  }

  var prevPct=null;
  function capFor(p){
    if(p>=100) return 'Everything’s ready.';
    if(p>=95)  return 'So close — just a few to go.';
    if(p>=75)  return 'Almost ready.';
    if(p>=50)  return 'Over halfway there.';
    if(p>=25)  return 'Momentum is building.';
    if(p>0)    return 'Good start — keep going.';
    return 'Let’s get this event ready.';
  }
  function celebrate(){
    if(window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    if(navigator.vibrate) try{ navigator.vibrate([14,45,14]); }catch(e){}
    var c=document.createElement('div'); c.className='confetti';
    var cols=['#FF8F33','#F0F4FF','#2350C5','#FFB066'];
    for(var i=0;i<30;i++){ var p=document.createElement('i');
      p.style.left=(Math.random()*100)+'%';
      p.style.background=cols[i%cols.length];
      p.style.animationDuration=(1+Math.random()*0.9)+'s';
      p.style.animationDelay=(Math.random()*0.25)+'s';
      c.appendChild(p);
    }
    document.body.appendChild(c);
    setTimeout(function(){ c.remove(); },2300);
  }
  function renderProgress(){
    var main=document.querySelectorAll('.chk'); var items=state.items||{};
    var total=main.length, done=0;
    main.forEach(function(cb){ if(items[cb.id]) done++; });
    var pct=total?Math.round(done/total*100):0;
    document.getElementById('pct').innerHTML=pct+'<b>%</b>';
    document.getElementById('done').textContent=done;
    document.getElementById('bar').style.width=pct+'%';
    document.getElementById('pcap').textContent=capFor(pct);
    document.querySelectorAll('.section').forEach(function(sec){
      var b=sec.querySelectorAll('.chk'), d=0; b.forEach(function(cb){ if(items[cb.id]) d++; });
      sec.querySelector('.cdone').textContent=d;
      sec.classList.toggle('complete', b.length>0 && d===b.length);
    });
    if(prevPct!==null && pct===100 && prevPct<100) celebrate();  // peak-end moment, once
    prevPct=pct;
  }

  function renderRoster(){
    var u=state.users||[], el=document.getElementById('roster');
    var show=u.slice(0,4), extra=u.length-show.length;
    var html='<div class="stack">'+show.map(function(x){
      return '<span class="chip'+(x.online?' on':'')+'" title="'+esc(x.name)+(x.online?' · online':'')+'">'
        +esc(initials(x.name))+(x.online?'<span class="dot"></span>':'')+'</span>';
    }).join('')+'</div>'+(extra>0?'<span class="more">+'+extra+'</span>':'');
    el.innerHTML=html;
    renderDemo();
  }
  function renderDemo(){
    var el=document.getElementById('demoStrip'); if(!el) return;
    var on=onlineUsers();
    var others=on.filter(function(x){return x.name.toLowerCase()!==me.toLowerCase();}).map(function(x){return esc(x.name);});
    el.classList.toggle('full', on.length>=MAXU);
    var html='<span class="ld"></span>Live demo · <b>'+on.length+'/'+MAXU+'</b> in use';
    if(others.length) html+=' · with '+others.join(', ');
    el.innerHTML=html;
  }

  /* ---------- Toggling ---------- */
  document.querySelectorAll('.item').forEach(function(lab){
    lab.addEventListener('click',function(e){
      e.preventDefault();
      var id=lab.getAttribute('data-item');
      var currentlyDone=!!(state.items&&state.items[id]);
      var want=!currentlyDone;
      // optimistic + immediate sensory feedback
      if(want){
        state.items[id]={by:me,at:Math.floor(Date.now()/1000)};
        if(navigator.vibrate) try{ navigator.vibrate(9); }catch(_){}
        lab.classList.remove('just'); void lab.offsetWidth; lab.classList.add('just');
        setTimeout(function(){ lab.classList.remove('just'); },380);
      } else { delete state.items[id]; }
      renderItems(); renderProgress();
      api({action:'toggle',user:me,item:id,done:want}).then(function(res){
        if(res&&res.ok){ state=res.state; render(); }
      }).catch(function(){ setSync(false); });
    });
  });

  /* ---------- Shared meta editing ---------- */
  var metaTimers={};
  ['name','date','venue','pax'].forEach(function(k){
    var el=document.getElementById('m_'+k); if(!el) return;
    el.addEventListener('input',function(){
      clearTimeout(metaTimers[k]);
      metaTimers[k]=setTimeout(function(){
        api({action:'meta',user:me,field:k,value:el.value}).then(function(res){
          if(res&&res.ok){ state.meta=res.state.meta; state.rev=res.state.rev; }
        }).catch(function(){ setSync(false); });
      },500);
    });
  });

  /* ---------- Collapsible (local pref) ---------- */
  document.querySelectorAll('.section > .head').forEach(function(h){
    var sec=h.parentElement, key='eliCol.'+sec.getAttribute('data-section');
    if(localStorage.getItem(key)==='1') sec.classList.add('collapsed');
    h.addEventListener('click',function(){
      sec.classList.toggle('collapsed');
      try{ localStorage.setItem(key,sec.classList.contains('collapsed')?'1':'0'); }catch(e){}
    });
  });

  /* ---------- Tabs ---------- */
  var nav=document.getElementById('nav');
  function activate(name){
    nav.querySelectorAll('button').forEach(function(b){ b.classList.toggle('active',b.dataset.tab===name); });
    document.querySelectorAll('.panel').forEach(function(p){ p.classList.toggle('active',p.id==='panel-'+name); });
  }
  var lastTab=localStorage.getItem('eliTab'); if(lastTab) activate(lastTab);
  nav.addEventListener('click',function(e){ var b=e.target.closest('button'); if(!b) return;
    activate(b.dataset.tab); try{localStorage.setItem('eliTab',b.dataset.tab);}catch(_){}
    window.scrollTo({top:0,behavior:'smooth'}); });

  document.getElementById('printBtn').addEventListener('click',function(){ window.print(); });

  /* ---------- Live sync ---------- */
  var syncEl=document.getElementById('sync'), syncTxt=document.getElementById('syncTxt');
  function setSync(ok){ syncEl.classList.toggle('off',!ok); syncTxt.textContent=ok?'Live':'Reconnecting…'; }
  function poll(){
    api({action:'state',user:me}).then(function(res){
      if(res&&res.ok){ setSync(true); if(res.max)MAXU=res.max;
        if(res.state.rev!==state.rev){ state=res.state; render(); } else { renderRoster(); }
      } else setSync(false);
    }).catch(function(){ setSync(false); });
  }
  var timer=null;
  function startSync(){ if(timer) clearInterval(timer); poll(); timer=setInterval(poll,6000); }
  document.addEventListener('visibilitychange',function(){ if(!document.hidden && !app.hidden) poll(); });

  /* ---------- Boot ---------- */
  if(me){ api({action:'join',user:me}).then(function(res){
      if(res&&res.max) MAXU=res.max;
      if(res&&res.ok){ state=res.state; enterApp(); }
      else { showGate(); if(res&&res.error){ gateErr.textContent=res.error; gateErr.classList.toggle('demo',!!res.demo); } }
    }).catch(function(){ showGate(); });
  } else { showGate(); }
})();
</script>
</body>
</html>

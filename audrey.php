<?php /* PUBLIC page — no password. Audrey's unpaid-invoice tracker. */ ?>
<!doctype html><html><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>AUDREY REPORT — WAITARA HOLDINGS GROUP OF COMPANIES CONSOLE</title>
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'%3E%3Crect width='64' height='64' rx='16' fill='%23F56F00'/%3E%3Ctext x='32' y='45' font-family='Arial' font-size='29' font-weight='700' fill='white' text-anchor='middle'%3E912%3C/text%3E%3C/svg%3E">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  :root{--orange:#F56F00;--blue:#2350C5;--ink:#15202B;--mute:#64748B;--line:#E6EAF0;--bg:#F4F6FA;--good:#16A34A;--bad:#D64933}
  *{box-sizing:border-box}
  body{margin:0;font-family:Poppins,system-ui,Arial,sans-serif;background:var(--bg);color:var(--ink);font-size:13px;-webkit-font-smoothing:antialiased}

  /* ---- Top bar ---- */
  .top{background:linear-gradient(135deg,#1B2A3A,#15202B);color:#fff;padding:10px 14px;display:flex;align-items:center;gap:10px;position:sticky;top:0;z-index:50;box-shadow:0 2px 10px rgba(0,0,0,.25)}
  .b{width:28px;height:28px;border-radius:7px;background:var(--orange);display:grid;place-items:center;font-weight:800;font-size:11px;color:#fff;flex:0 0 auto}
  .top h1{font-size:12px;margin:0;font-weight:700;letter-spacing:.4px}
  .top .sub{font-size:9.5px;color:#9AA7B8;letter-spacing:.4px}
  .refresh{margin-left:auto;border:1px solid rgba(255,255,255,.2);color:#fff;background:rgba(255,255,255,.1);border-radius:7px;padding:5px 11px;font-size:11px;font-weight:600;cursor:pointer;font-family:inherit;transition:background .15s}
  .refresh:hover{background:rgba(255,255,255,.18)}

  /* ---- Layout ---- */
  .wrap{max-width:960px;margin:0 auto;padding:12px 12px 24px}

  /* ---- Summary KPI strip ---- */
  .sum{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px}
  .sum .card{flex:1;min-width:110px;padding:10px 13px}
  .card{background:#fff;border:1px solid var(--line);border-radius:12px}
  .lab{font-size:9.5px;color:var(--mute);text-transform:uppercase;letter-spacing:.4px;font-weight:600}
  .val{font-weight:700;font-size:16px;margin-top:2px;line-height:1.2}

  /* ---- Section header ---- */
  .section-h{font-weight:700;font-size:11px;margin:14px 2px 6px;text-transform:uppercase;letter-spacing:.6px;color:var(--mute)}

  /* ---- Summary table ---- */
  .sumtbl{width:100%;border-collapse:collapse;font-size:12px}
  .sumtbl th,.sumtbl td{padding:6px 10px;border-bottom:1px solid #F0F2F6;text-align:left}
  .sumtbl th{font-size:9.5px;text-transform:uppercase;color:var(--mute);letter-spacing:.3px;background:#F8FAFC;font-weight:700}
  .sumtbl td.amt,.sumtbl th.amt{text-align:right;white-space:nowrap;font-weight:600}
  .sumtbl tfoot td{font-weight:700;border-top:2px solid var(--ink);border-bottom:0;background:#F4F7FB;font-size:12px}
  .sumtbl tbody tr:hover{background:#FFF8F3;box-shadow:inset 3px 0 0 var(--orange)}

  /* ---- Quick search ---- */
  .uqsearch{width:100%;padding:8px 12px;border:1.5px solid var(--line);border-radius:9px;font-family:inherit;font-size:12.5px;margin-bottom:8px;background:#fff}
  .uqsearch:focus{outline:none;border-color:var(--orange);box-shadow:0 0 0 3px rgba(245,111,0,.12)}

  /* ---- Client cards ---- */
  .client{background:#fff;border:1px solid var(--line);border-radius:10px;margin-bottom:6px;overflow:hidden;box-shadow:0 1px 3px rgba(21,32,43,.05)}
  .chead{display:flex;justify-content:space-between;align-items:center;padding:8px 12px;border-bottom:1px solid var(--line);background:#FAFBFE;gap:8px}
  .chead b{font-size:12px;text-transform:uppercase;letter-spacing:.2px;flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
  .chead-right{display:flex;gap:6px;align-items:center;flex:0 0 auto}
  .cdue{font-size:10.5px;color:var(--mute);white-space:nowrap;font-weight:600}
  .stmtbtn{border:1px solid var(--blue);color:var(--blue);background:#fff;border-radius:6px;padding:4px 9px;font-size:10.5px;font-weight:600;cursor:pointer;font-family:inherit;transition:background .13s}
  .stmtbtn:hover{background:var(--blue);color:#fff}

  /* ---- Invoice rows (compact) ---- */
  .inv{padding:6px 12px;border-bottom:1px solid #F0F3F8;transition:background .12s}
  .inv:last-child{border-bottom:0}
  .inv:hover{background:#FAFBFE}
  .inv.ispaid{opacity:.55}
  .invtop{display:flex;align-items:center;gap:6px;flex-wrap:nowrap;min-width:0}
  .invtop .num{font-weight:700;font-size:11.5px;white-space:nowrap;flex:0 0 auto}
  .invtop a.num{color:var(--blue);text-decoration:none}
  .invtop a.num:hover{text-decoration:underline}
  .invtop .meta{color:var(--mute);font-size:10px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;flex:1;min-width:0}
  .invtop .amt{font-weight:700;font-size:11.5px;white-space:nowrap;flex:0 0 auto}
  .ccy{font-size:9.5px;background:#EEF2FF;color:var(--blue);border-radius:4px;padding:1px 5px;font-weight:700;margin-left:2px}
  .seg{display:inline-flex;border:1px solid var(--line);border-radius:6px;overflow:hidden;flex:0 0 auto}
  .seg button{border:0;background:#fff;color:var(--mute);font-family:inherit;font-size:10px;font-weight:600;padding:3px 9px;cursor:pointer;transition:background .12s,color .12s;white-space:nowrap}
  .seg button.on.paid{background:var(--good);color:#fff}
  .seg button.on.unpaid{background:var(--bad);color:#fff}

  /* ---- Note toggle ---- */
  .notebtn{border:1px solid var(--line);background:#fff;color:var(--mute);border-radius:6px;padding:2px 6px;font-size:10px;cursor:pointer;flex:0 0 auto;transition:background .12s,color .12s;font-family:inherit;line-height:1.5}
  .notebtn:hover,.notebtn.has{background:#FFF4EB;border-color:#F7C99A;color:var(--orange)}
  .notewrap{padding:4px 0 4px;display:flex;align-items:center;gap:6px}
  .note{flex:1;padding:6px 10px;border:1.5px solid var(--line);border-radius:7px;font-family:inherit;font-size:11.5px;background:#FAFBFE;margin:0}
  .note:focus{outline:none;border-color:var(--orange);box-shadow:0 0 0 3px rgba(245,111,0,.12);background:#fff}
  .saved{color:var(--good);font-size:10px;white-space:nowrap;opacity:0;transition:opacity .2s;flex:0 0 auto}
  .saved.show{opacity:1}

  /* ---- Statement panel ---- */
  .stmt{padding:0 12px 12px;border-top:1px solid var(--line);background:#FAFBFE}
  .stmt .pf{display:flex;gap:6px;align-items:center;flex-wrap:wrap;padding:8px 0}
  .stmt input[type=date]{padding:5px 8px;border:1px solid var(--line);border-radius:7px;font-family:inherit;font-size:11px}
  .stmt .rb{border:0;background:var(--ink);color:#fff;border-radius:7px;padding:5px 11px;font-size:11px;font-weight:600;cursor:pointer;font-family:inherit}
  .stmt table{width:100%;border-collapse:collapse;font-size:11px;margin-top:4px}
  .stmt th,.stmt td{padding:5px 7px;border-bottom:1px solid #EEF1F5;text-align:left}
  .stmt th{font-size:9px;text-transform:uppercase;color:var(--mute)}
  .stmt td.amt,.stmt th.amt{text-align:right;white-space:nowrap}
  .stmt tr.tot td{font-weight:700;border-top:2px solid var(--ink)}
  .pst{font-weight:600}.pst.up{color:var(--bad)}.pst.pd{color:var(--good)}

  /* ---- Other clients (no pending) ---- */
  .ncbox{background:#fff;border:1px solid var(--line);border-radius:12px;padding:12px}
  .ncbox input{width:100%;padding:8px 11px;border:1.5px solid var(--line);border-radius:8px;font-family:inherit;font-size:12.5px}
  .ncbox input:focus{outline:none;border-color:var(--orange);box-shadow:0 0 0 3px rgba(245,111,0,.12)}
  .ncrow{padding:7px 10px;border:1px solid var(--line);border-radius:7px;margin-top:5px;cursor:pointer;font-size:12px;font-weight:600;transition:background .12s}
  .ncrow:hover{background:#FFF4EB;border-color:var(--orange)}

  /* ---- Export ---- */
  .exportbtn{border:0;background:var(--good);color:#fff;border-radius:7px;padding:6px 12px;font-size:11px;font-weight:600;cursor:pointer;font-family:inherit;white-space:nowrap}
  .exportbtn:hover{filter:brightness(1.06)}

  /* ---- Global progress bar ---- */
  .bar{position:fixed;top:0;left:0;height:3px;background:var(--orange);width:0;transition:width .2s,opacity .3s;opacity:0;box-shadow:0 0 8px var(--orange);z-index:999}
  .muted{color:var(--mute);font-size:11px}

  /* ---- Footer ---- */
  .pgfoot{text-align:center;padding:16px 12px 20px;line-height:1.7}
  .pgfoot .co{font-size:11.5px;color:#64748B}
  .pgfoot .sec{font-size:11px;color:#F56F00;margin-top:4px}

  /* ---- Mobile ---- */
  @media(max-width:560px){
    .sum{gap:6px}
    .sum .card{min-width:90px;padding:8px 10px}
    .val{font-size:14px}
    .invtop .meta{display:none}
    .wrap{padding:8px 8px 20px}
    .chead{padding:7px 10px}
    .inv{padding:5px 10px}
    .seg button{padding:3px 7px;font-size:9.5px}
  }
</style></head>
<body>
<div id="bar" class="bar"></div>
<div class="top">
  <div class="b">912</div>
  <div><h1>AUDREY REPORT</h1><div class="sub">UNPAID INVOICES TRACKER</div></div>
  <button class="refresh" onclick="load(true)">⟳ Refresh</button>
</div>
<div class="wrap" id="app"><div class="card muted" style="padding:14px">Loading unpaid invoices…</div></div>

<script>
const fmt = n => 'KES ' + Math.round(n||0).toLocaleString('en-KE');
const esc = s => String(s==null?'':s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
let DATA = null;

function bar(s){ const b=document.getElementById('bar'); if(!b) return; if(s){b.style.opacity='1';b.style.width='80%';} else {b.style.width='100%';setTimeout(()=>{b.style.opacity='0';b.style.width='0';},300);} }

async function load(refresh){
  bar(true);
  try{
    const r = await fetch('api/audrey_data.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({refresh:refresh?1:0})});
    DATA = await r.json();
  }catch(e){ DATA={ok:false,error:String(e)}; }
  bar(false); render();
}

function setLocal(invoice, patch){
  (DATA.clients||[]).forEach(c=>c.invoices.forEach(iv=>{ if(iv.number===invoice) Object.assign(iv, patch); }));
}

async function mark(invoice, status){
  bar(true);
  try{
    const r = await fetch('api/audrey_mark.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({invoice,status})});
    const j = await r.json();
    if(j.ok){ setLocal(invoice,{audrey:status}); render(); }
  }catch(e){}
  bar(false);
}

let noteTimer={};
function saveNote(invoice, value, badgeId){
  setLocal(invoice,{note:value});
  clearTimeout(noteTimer[invoice]);
  noteTimer[invoice]=setTimeout(async()=>{
    try{
      const r=await fetch('api/audrey_mark.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({invoice,note:value})});
      const j=await r.json();
      if(j.ok){ const b=document.getElementById(badgeId); if(b){ b.classList.add('show'); setTimeout(()=>b.classList.remove('show'),1500); } }
    }catch(e){}
  },600);
}

function toggleNote(id){
  const el=document.getElementById(id);
  if(el){ el.style.display=el.style.display==='none'?'flex':'none'; if(el.style.display==='flex') el.querySelector('input')?.focus(); }
}

function render(){
  const app=document.getElementById('app');
  if(!DATA){ return; }
  if(DATA.ok===false){ app.innerHTML='<div class="card" style="color:var(--bad);padding:14px">Error: '+esc(DATA.error||'failed')+'</div>'; return; }
  const totalDue=DATA.totalDue||0, paid=DATA.paidMarked||0, n=DATA.count||0;

  let html = `<div class="sum">
    <div class="card"><div class="lab">Outstanding</div><div class="val" style="color:var(--bad)">${fmt(totalDue)}</div></div>
    <div class="card"><div class="lab">Invoices</div><div class="val">${n}</div></div>
    <div class="card"><div class="lab">Marked paid</div><div class="val" style="color:var(--good)">${paid}</div></div>
  </div>
  <div class="muted" style="margin:0 2px 10px">As of ${DATA.asOf? new Date(DATA.asOf).toLocaleString('en-KE'):'now'}. Marking paid here is your own follow-up record — it does not change Zoho.</div>`;

  if(!(DATA.clients||[]).length){ html += '<div class="card muted" style="padding:14px">No clients to show.</div>'; app.innerHTML=html; return; }

  const withIdx = (DATA.clients||[]).map((c,ci)=>({c,ci}));
  const withUnpaid = withIdx.filter(o=>o.c.invoices.length>0);
  const noUnpaid   = withIdx.filter(o=>o.c.invoices.length===0);
  NC.items = noUnpaid.map(o=>({name:o.c.name, ci:o.ci}));

  // summary table
  const sumList = withUnpaid.map(o=>o.c).slice().sort((a,b)=>b.subtotalDue-a.subtotalDue);
  if(sumList.length){
    let invTot=0;
    const srows = sumList.map(c=>{ invTot+=c.invoices.length; return `<tr>
        <td>${esc(c.name)}</td>
        <td class="amt">${c.invoices.length}</td>
        <td class="amt">${fmt(c.subtotalDue)}</td></tr>`; }).join('');
    html += `<div class="section-h">Unpaid invoices summary</div>
      <div class="card" style="padding:0;overflow:hidden;margin-bottom:10px">
        <table class="sumtbl">
          <thead><tr><th>Client</th><th class="amt">Unpaid</th><th class="amt">Amount due</th></tr></thead>
          <tbody>${srows}</tbody>
          <tfoot><tr><td>Total · ${sumList.length} clients</td><td class="amt">${invTot}</td><td class="amt">${fmt(totalDue)}</td></tr></tfoot>
        </table>
      </div>`;
  }

  html += `<div style="display:flex;justify-content:space-between;align-items:center;margin:0 2px 6px;flex-wrap:wrap;gap:6px">
      <div class="section-h" style="margin:0">Clients with unpaid invoices (${withUnpaid.length})</div>
      <button class="exportbtn" onclick="exportUnpaid()">⇩ Export</button>
    </div>
    <input id="uqSearch" class="uqsearch" type="text" autocomplete="off" placeholder="🔍 Search client…" value="${esc(UQ)}" oninput="uqFilter(this.value)">`;

  if(!withUnpaid.length){ html += '<div class="card muted" style="padding:14px">No unpaid invoices right now.</div>'; }

  let idc=0;
  withUnpaid.forEach(({c,ci})=>{
    const rows = c.invoices.map(iv=>{
      const isPaid = iv.audrey==='paid';
      const inv = (iv.number+'').replace(/'/g,'');
      const badgeId = 'sv'+(idc++);
      const noteId  = 'nw_'+badgeId;
      const hasNote = !!(iv.note||'').trim();
      const ccyTag  = iv.currency&&iv.currency!=='KES'?`<span class="ccy">${esc(iv.currency)}</span>`:'';
      return `<div class="inv ${isPaid?'ispaid':''}">
        <div class="invtop">
          <span class="num">${esc(iv.number)}</span>
          <span class="meta">${esc(iv.date||'')} · due ${esc(iv.dueDate||'')}</span>
          <span class="amt">${fmt(iv.balance)}${ccyTag}</span>
          <span class="seg">
            <button class="unpaid ${!isPaid?'on':''}" onclick="mark('${inv}','unpaid')">Unpaid</button>
            <button class="paid ${isPaid?'on':''}" onclick="mark('${inv}','paid')">Paid</button>
          </span>
          <button class="notebtn${hasNote?' has':''}" onclick="toggleNote('${noteId}')" title="${hasNote?'View note':'Add note'}">📝</button>
        </div>
        <div id="${noteId}" class="notewrap" style="display:${hasNote?'flex':'none'}">
          <input class="note" placeholder="e.g. paid via MPESA ref, promised 5th…" value="${esc(iv.note||'')}" oninput="saveNote('${inv}', this.value, '${badgeId}')">
          <span id="${badgeId}" class="saved">saved ✓</span>
        </div>
      </div>`;
    }).join('');
    html += `<div class="client uqitem" data-uq="${esc((c.name||'').toLowerCase())}">
      <div class="chead">
        <b>${esc(c.name)}</b>
        <div class="chead-right">
          <span class="cdue">${fmt(c.subtotalDue)}</span>
          <button class="stmtbtn" onclick="toggleStmt(${ci}, this)">Statement</button>
        </div>
      </div>
      <div class="stmt" id="stmt-${ci}" style="display:none"></div>
      ${rows}</div>`;
  });

  html += `<div class="section-h">Other clients — no pending invoices (${noUnpaid.length})</div>
    <div class="ncbox">
      <input id="ncSearch" type="text" autocomplete="off" placeholder="🔍 Search to view statement…" value="${esc(NC.q)}" oninput="ncFilter(this.value)">
      <div id="ncList"></div>
      <div id="ncPanel" class="stmt" style="display:none;border-top:0;padding-top:0"></div>
    </div>`;

  html += `<div class="pgfoot">
    <div class="co">This system is designed for <b>912 Holdings</b>, Zone Fibre Limited, Waitara Holdings Limited, Smart Zone Fibre Limited &amp; Global IT Limited</div>
    <div class="sec">&#9888; If you are a staff member of any of the companies listed here and can see information of a company you are not associated with, report immediately to <b>Njuguna Waitara — +254 722 974 970</b> at a reward of <b>10,000 KES</b></div>
  </div>`;

  app.innerHTML = html;
  if(UQ) uqFilter(UQ);
  if(NC.q) ncFilter(NC.q);
}

const STMT = {};
let NC = { q:'', items:[] };
let UQ = '';
function uqFilter(v){
  UQ = v;
  const q = (v||'').trim().toLowerCase();
  document.querySelectorAll('.uqitem').forEach(el=>{
    el.style.display = (!q || (el.dataset.uq||'').includes(q)) ? '' : 'none';
  });
}

function ncFilter(v){
  NC.q = v;
  const list = document.getElementById('ncList'); if(!list) return;
  const q = v.trim().toLowerCase();
  if(!q){ list.innerHTML=''; return; }
  const matches = (NC.items||[]).filter(o=>o.name.toLowerCase().includes(q)).slice(0,12);
  list.innerHTML = matches.length
    ? matches.map(o=>`<div class="ncrow" onclick="ncOpen(${o.ci})">${esc(o.name)}</div>`).join('')
    : '<div class="muted" style="padding:8px 4px">No match.</div>';
}
function ncOpen(ci){
  const c = (DATA.clients||[])[ci]; if(!c) return;
  const inp = document.getElementById('ncSearch'); if(inp) inp.value = c.name; NC.q = c.name;
  const list = document.getElementById('ncList'); if(list) list.innerHTML='';
  const panel = document.getElementById('ncPanel'); if(!panel) return;
  panel.style.display='block';
  if(STMT[c.name] && STMT[c.name].data) renderStmt(c.name, panel);
  else loadStmt(c.name, panel, '', '');
}

function csvCell(s){ s = String(s==null?'':s); return '"' + s.replace(/"/g,'""') + '"'; }
function exportUnpaid(){
  if(!DATA || !(DATA.clients||[]).length) return;
  const rows = [['Client','Invoice','Date','Due Date','Balance','Currency','Marked','Note']];
  (DATA.clients||[]).forEach(c=>c.invoices.forEach(iv=>{
    rows.push([c.name, iv.number, iv.date||'', iv.dueDate||'', iv.balance, iv.currency||'KES', iv.audrey||'unpaid', iv.note||'']);
  }));
  const csv = '﻿' + rows.map(r=>r.map(csvCell).join(',')).join('\r\n');
  const blob = new Blob([csv], {type:'text/csv;charset=utf-8'});
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = 'unpaid_invoices_' + new Date().toISOString().slice(0,10) + '.csv';
  document.body.appendChild(a); a.click(); setTimeout(()=>{ URL.revokeObjectURL(a.href); a.remove(); }, 500);
}
function toggleStmt(ci, btn){
  const panel = btn.closest('.client').querySelector('.stmt');
  if(!panel) return;
  if(panel.style.display!=='none'){ panel.style.display='none'; btn.textContent='Statement'; return; }
  panel.style.display='block'; btn.textContent='Hide ▴';
  const name = (DATA.clients||[])[ci] ? DATA.clients[ci].name : '';
  panel.dataset.client = name;
  if(STMT[name] && STMT[name].data){ renderStmt(name, panel); }
  else loadStmt(name, panel, '', '');
}
async function loadStmt(name, panel, from, to){
  panel.innerHTML = '<div class="muted" style="padding:10px 0">Building statement…</div>';
  bar(true);
  try{
    const r = await fetch('api/audrey_statement.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({client:name, from:from||'', to:to||''})});
    STMT[name] = {data: await r.json()};
  }catch(e){ STMT[name] = {data:{ok:false,error:String(e)}}; }
  bar(false);
  renderStmt(name, panel);
}
function rebuildStmt(name, panel){
  const f = panel.querySelector('.sf').value, t = panel.querySelector('.st').value;
  loadStmt(name, panel, f, t);
}
function renderStmt(name, panel){
  const d = (STMT[name]||{}).data;
  if(!d){ return; }
  if(d.ok===false){ panel.innerHTML = '<div style="color:var(--bad);padding:10px 0">Error: '+esc(d.error||'failed')+'</div>'; return; }
  const rows = (d.invoices||[]).map(iv=>`<tr>
      <td>${esc(iv.number)}</td>
      <td>${esc(iv.date||'')}</td>
      <td><span class="pst ${iv.unpaid?'up':'pd'}">${iv.unpaid?esc(iv.status||'unpaid'):'paid'}</span></td>
      <td class="amt">${fmt(iv.total)} ${iv.currency&&iv.currency!=='KES'?esc(iv.currency):''}</td>
      <td class="amt">${fmt(iv.balance)}</td></tr>`).join('')
    || `<tr><td colspan="5" class="muted" style="padding:10px 0">No invoices in this period.</td></tr>`;
  panel.innerHTML = `
    <div class="pf">
      <span class="muted">Period</span>
      <input type="date" class="sf" value="${d.period.from}">
      <span class="muted">to</span>
      <input type="date" class="st" value="${d.period.to}">
      <button class="rb" onclick="rebuildStmt('${name.replace(/\\/g,'').replace(/'/g,"\\'")}', this.closest('.stmt'))">Rebuild</button>
      <span class="muted" style="margin-left:auto">Billed ${fmt(d.billed)} · Due <b style="color:var(--bad)">${fmt(d.totalDue)}</b></span>
    </div>
    <table>
      <thead><tr><th>Invoice</th><th>Date</th><th>Status</th><th class="amt">Amount</th><th class="amt">Balance</th></tr></thead>
      <tbody>${rows}</tbody>
      <tfoot><tr class="tot"><td colspan="4" class="amt">Total outstanding</td><td class="amt">${fmt(d.totalDue)}</td></tr></tfoot>
    </table>
    <div class="muted" style="margin-top:6px">Smart default: from last two paid invoices to latest unpaid. Adjust dates and Rebuild for any range.</div>`;
}

load();
</script>
</body></html>

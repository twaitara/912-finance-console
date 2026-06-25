<?php /* PUBLIC page — no password. Audrey's unpaid-invoice tracker for Dunhill clients. */ ?>
<!doctype html><html><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>AUDREY REPORT — WAITARA HOLDINGS GROUP OF COMPANIES CONSOLE</title>
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'%3E%3Crect width='64' height='64' rx='16' fill='%23F56F00'/%3E%3Ctext x='32' y='45' font-family='Arial' font-size='29' font-weight='700' fill='white' text-anchor='middle'%3E912%3C/text%3E%3C/svg%3E">
<style>
  :root{--orange:#F56F00;--blue:#2350C5;--ink:#15202B;--mute:#64748B;--line:#E6EAF0;--bg:#F4F6FA;--good:#16A34A;--bad:#D64933}
  *{box-sizing:border-box}
  body{margin:0;font-family:Poppins,system-ui,Arial,sans-serif;background:var(--bg);color:var(--ink)}
  .top{background:var(--ink);color:#fff;padding:16px 18px;display:flex;align-items:center;gap:12px}
  .b{width:34px;height:34px;border-radius:9px;background:var(--orange);display:grid;place-items:center;font-weight:700;color:#fff}
  .top h1{font-size:15px;margin:0;letter-spacing:.4px}
  .top .sub{font-size:11px;color:#9AA7B8}
  .wrap{max-width:1000px;margin:0 auto;padding:16px}
  .sum{display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;margin-bottom:14px}
  .card{background:#fff;border:1px solid var(--line);border-radius:14px;padding:14px}
  .lab{font-size:10px;color:var(--mute);text-transform:uppercase;letter-spacing:.3px}
  .val{font-weight:700;font-size:18px;margin-top:2px}
  .client{background:#fff;border:1px solid var(--line);border-radius:14px;margin-bottom:12px;overflow:hidden}
  .chead{display:flex;justify-content:space-between;align-items:center;padding:12px 14px;border-bottom:1px solid var(--line)}
  .chead b{font-size:13px;text-transform:uppercase;letter-spacing:.2px}
  .inv{padding:11px 14px;border-bottom:1px solid #F0F2F6}
  .inv:last-child{border-bottom:0}
  .invtop{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
  .invtop .num{font-weight:600;font-size:13px}
  .invtop a.num{color:var(--blue);text-decoration:underline;cursor:pointer}
  .noetr{font-size:10.5px;color:var(--mute);font-style:italic}
  .invtop .meta{color:var(--mute);font-size:11.5px}
  .invtop .amt{margin-left:auto;font-weight:600;font-size:13px;white-space:nowrap}
  .seg{display:inline-flex;border:1px solid var(--line);border-radius:8px;overflow:hidden;flex:0 0 auto}
  .seg button{border:0;background:#fff;color:var(--mute);font-family:inherit;font-size:11px;font-weight:600;padding:6px 13px;cursor:pointer}
  .seg button.on.paid{background:var(--good);color:#fff}
  .seg button.on.unpaid{background:var(--bad);color:#fff}
  .note{width:100%;margin-top:8px;padding:8px 10px;border:1px solid var(--line);border-radius:8px;font-family:inherit;font-size:12.5px;background:#FBFCFE}
  .note:focus{outline:none;border-color:var(--orange);box-shadow:0 0 0 3px rgba(245,111,0,.14);background:#fff}
  .inv.ispaid .num,.inv.ispaid .meta,.inv.ispaid .amt{opacity:.5}
  .saved{color:var(--good);font-size:10.5px;margin-left:8px;opacity:0;transition:opacity .2s}
  .saved.show{opacity:1}
  .stmtbtn{border:1px solid var(--blue);color:var(--blue);background:#fff;border-radius:8px;padding:6px 12px;font-size:11px;font-weight:600;cursor:pointer;font-family:inherit}
  .stmt{padding:0 14px 14px;border-top:1px solid var(--line);background:#FBFCFE}
  .stmt .pf{display:flex;gap:8px;align-items:center;flex-wrap:wrap;padding:12px 0}
  .stmt input[type=date]{padding:7px 9px;border:1px solid var(--line);border-radius:8px;font-family:inherit;font-size:12px}
  .stmt .rb{border:0;background:var(--ink);color:#fff;border-radius:8px;padding:7px 13px;font-size:12px;font-weight:600;cursor:pointer;font-family:inherit}
  .stmt table{width:100%;border-collapse:collapse;font-size:11.5px;margin-top:4px}
  .stmt th,.stmt td{padding:6px 8px;border-bottom:1px solid #EEF1F5;text-align:left}
  .stmt th{font-size:9.5px;text-transform:uppercase;color:var(--mute)}
  .stmt td.amt,.stmt th.amt{text-align:right;white-space:nowrap}
  .stmt tr.tot td{font-weight:700;border-top:2px solid var(--ink)}
  .pst{font-weight:600}.pst.up{color:var(--bad)}.pst.pd{color:var(--good)}
  .exportbtn{border:0;background:var(--good);color:#fff;border-radius:8px;padding:8px 14px;font-size:12px;font-weight:600;cursor:pointer;font-family:inherit;white-space:nowrap}
  .exportbtn:hover{filter:brightness(1.06)}
  .section-h{font-weight:700;font-size:13px;margin:18px 2px 8px;text-transform:uppercase;letter-spacing:.3px;color:var(--ink)}
  .ncbox{background:#fff;border:1px solid var(--line);border-radius:14px;padding:14px}
  .ncbox input#ncSearch{width:100%;box-sizing:border-box;padding:10px 12px;border:1px solid var(--line);border-radius:9px;font-family:inherit;font-size:13px}
  .ncbox input#ncSearch:focus{outline:none;border-color:var(--orange);box-shadow:0 0 0 3px rgba(245,111,0,.14)}
  .ncrow{padding:9px 11px;border:1px solid var(--line);border-radius:8px;margin-top:6px;cursor:pointer;font-size:12.5px;font-weight:600}
  .ncrow:hover{background:#FFF4EB;border-color:var(--orange)}
  .sumtbl{width:100%;border-collapse:collapse;font-size:12.5px}
  .sumtbl th,.sumtbl td{padding:9px 14px;border-bottom:1px solid #F0F2F6;text-align:left}
  .sumtbl th{font-size:10px;text-transform:uppercase;color:var(--mute);letter-spacing:.2px;background:#F1F4F8}
  .sumtbl td.amt,.sumtbl th.amt{text-align:right;white-space:nowrap}
  .sumtbl tfoot td{font-weight:700;border-top:2px solid var(--ink);border-bottom:0;background:#F7F8FB}
  .uqsearch{width:100%;box-sizing:border-box;padding:10px 12px;border:1px solid var(--line);border-radius:9px;font-family:inherit;font-size:13px;margin-bottom:12px}
  .uqsearch:focus{outline:none;border-color:var(--orange);box-shadow:0 0 0 3px rgba(245,111,0,.14)}
  .muted{color:var(--mute);font-size:12px}
  .bar{position:fixed;top:0;left:0;height:3px;background:var(--orange);width:0;transition:width .2s,opacity .3s;opacity:0;box-shadow:0 0 8px var(--orange);z-index:99}
  .refresh{margin-left:auto;border:1px solid #2b3a47;color:#fff;background:#22303d;border-radius:8px;padding:7px 12px;font-size:12px;font-weight:600;cursor:pointer}
  @media(max-width:560px){.sum{grid-template-columns:1fr}.val{font-size:16px}.invtop .amt{margin-left:0}}
</style></head>
<body>
<div id="bar" class="bar"></div>
<div class="top"><div class="b">912</div>
  <div><h1>AUDREY REPORT</h1><div class="sub">DUNHILL CLIENTS · UNPAID INVOICES</div></div>
  <button class="refresh" onclick="load(true)">Refresh</button>
</div>
<div class="wrap" id="app"><div class="card muted">Loading unpaid invoices…</div></div>

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

function render(){
  const app=document.getElementById('app');
  if(!DATA){ return; }
  if(DATA.ok===false){ app.innerHTML='<div class="card" style="color:var(--bad)">Error: '+esc(DATA.error||'failed')+'</div>'; return; }
  const totalDue=DATA.totalDue||0, paid=DATA.paidMarked||0, n=DATA.count||0;
  let html = `<div class="sum">
    <div class="card"><div class="lab">Outstanding (not marked paid)</div><div class="val" style="color:var(--bad)">${fmt(totalDue)}</div></div>
    <div class="card"><div class="lab">Invoices on report</div><div class="val">${n}</div></div>
    <div class="card"><div class="lab">Marked paid</div><div class="val" style="color:var(--good)">${paid}</div></div>
  </div>
  <div class="muted" style="margin:0 2px 12px">As of ${DATA.asOf? new Date(DATA.asOf).toLocaleString('en-KE'):'now'}. Marking paid or adding a note here is your own follow-up record — it does not change Zoho.</div>`;

  if(!(DATA.clients||[]).length){ html += '<div class="card muted">No clients to show.</div>'; app.innerHTML=html; return; }

  // split: clients WITH unpaid invoices first, then the rest
  const withIdx = (DATA.clients||[]).map((c,ci)=>({c,ci}));
  const withUnpaid = withIdx.filter(o=>o.c.invoices.length>0);
  const noUnpaid   = withIdx.filter(o=>o.c.invoices.length===0);
  NC.items = noUnpaid.map(o=>({name:o.c.name, ci:o.ci}));

  // ---- summary table of unpaid invoices (top of page) ----
  const sumList = withUnpaid.map(o=>o.c).slice().sort((a,b)=>b.subtotalDue-a.subtotalDue);
  if(sumList.length){
    let invTot=0;
    const srows = sumList.map(c=>{ invTot+=c.invoices.length; return `<tr>
        <td>${esc(c.name)}</td>
        <td class="amt">${c.invoices.length}</td>
        <td class="amt">${fmt(c.subtotalDue)}</td></tr>`; }).join('');
    html += `<div class="section-h">Unpaid invoices summary</div>
      <div class="card" style="padding:0;overflow:hidden;margin-bottom:14px">
        <table class="sumtbl">
          <thead><tr><th>Client</th><th class="amt">Unpaid</th><th class="amt">Amount due</th></tr></thead>
          <tbody>${srows}</tbody>
          <tfoot><tr><td>Total (${sumList.length} clients)</td><td class="amt">${invTot}</td><td class="amt">${fmt(totalDue)}</td></tr></tfoot>
        </table>
      </div>`;
  }

  html += `<div style="display:flex;justify-content:space-between;align-items:center;margin:4px 2px 10px">
      <div class="section-h" style="margin:0">Clients with unpaid invoices (${withUnpaid.length})</div>
      <button class="exportbtn" onclick="exportUnpaid()">⇩ Export unpaid to Excel</button>
    </div>
    <input id="uqSearch" class="uqsearch" type="text" autocomplete="off" placeholder="Quick search a client with unpaid invoices…" value="${esc(UQ)}" oninput="uqFilter(this.value)">`;

  if(!withUnpaid.length){ html += '<div class="card muted">No unpaid invoices right now.</div>'; }

  let idc=0;
  withUnpaid.forEach(({c,ci})=>{
    const rows = c.invoices.map(iv=>{
      const isPaid = iv.audrey==='paid';
      const inv = (iv.number+'').replace(/'/g,'');
      const badgeId = 'sv'+(idc++);
      return `<div class="inv ${isPaid?'ispaid':''}">
        <div class="invtop">
          <span class="num">${esc(iv.number)}</span>
          <span class="meta">${esc(iv.date||'')} · due ${esc(iv.dueDate||'')}</span>
          <span class="amt">${fmt(iv.balance)} ${iv.currency&&iv.currency!=='KES'?esc(iv.currency):''}</span>
          <span class="seg">
            <button class="unpaid ${!isPaid?'on':''}" onclick="mark('${inv}','unpaid')">Unpaid</button>
            <button class="paid ${isPaid?'on':''}" onclick="mark('${inv}','paid')">Paid</button>
          </span>
        </div>
        <input class="note" placeholder="Add a note (e.g. paid via MPESA ref…, promised 5th, partial payment)" value="${esc(iv.note||'')}" oninput="saveNote('${inv}', this.value, '${badgeId}')">
        <span id="${badgeId}" class="saved">saved ✓</span>
      </div>`;
    }).join('');
    html += `<div class="client uqitem" data-uq="${esc((c.name||'').toLowerCase())}">
      <div class="chead"><b>${esc(c.name)}</b>
        <span style="display:flex;gap:10px;align-items:center">
          <span class="muted">${fmt(c.subtotalDue)} due</span>
          <button class="stmtbtn" onclick="toggleStmt(${ci}, this)">Statement</button>
        </span>
      </div>
      <div class="stmt" id="stmt-${ci}" style="display:none"></div>
      ${rows}</div>`;
  });

  // ---- clients with NO pending invoices: searchable dropdown ----
  html += `<div class="section-h">Other clients — no pending invoices (${noUnpaid.length})</div>
    <div class="ncbox">
      <input id="ncSearch" type="text" autocomplete="off" placeholder="Search a client to view their statement…" value="${esc(NC.q)}" oninput="ncFilter(this.value)">
      <div id="ncList"></div>
      <div id="ncPanel" class="stmt" style="display:none;border-top:0;padding-top:0"></div>
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
  const csv = '\uFEFF' + rows.map(r=>r.map(csvCell).join(',')).join('\r\n');
  const blob = new Blob([csv], {type:'text/csv;charset=utf-8'});
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = 'dunhill_unpaid_invoices_' + new Date().toISOString().slice(0,10) + '.csv';
  document.body.appendChild(a); a.click(); setTimeout(()=>{ URL.revokeObjectURL(a.href); a.remove(); }, 500);
}
function toggleStmt(ci, btn){
  const panel = btn.closest('.client').querySelector('.stmt');
  if(!panel) return;
  if(panel.style.display!=='none'){ panel.style.display='none'; return; }
  panel.style.display='block';
  const name = (DATA.clients||[])[ci] ? DATA.clients[ci].name : '';
  panel.dataset.client = name;
  if(STMT[name] && STMT[name].data){ renderStmt(name, panel); }
  else loadStmt(name, panel, '', '');
}
async function loadStmt(name, panel, from, to){
  panel.innerHTML = '<div class="muted" style="padding:12px 0">Building statement…</div>';
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
  if(d.ok===false){ panel.innerHTML = '<div style="color:var(--bad);padding:12px 0">Error: '+esc(d.error||'failed')+'</div>'; return; }
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
    <div class="muted" style="margin-top:6px">Smart default: from the last two paid invoices to the latest unpaid. Adjust dates and Rebuild for any range.</div>`;
}

load();
</script>
</body></html>

<?php /* PUBLIC page — no password. Team task manager: per-person stats, filter, notes, done/not-done. */ ?>
<!doctype html><html><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>TASK BOARD — WAITARA HOLDINGS GROUP OF COMPANIES CONSOLE</title>
<script>
(function(){
  document.addEventListener('contextmenu',function(e){e.preventDefault();});
  document.addEventListener('keydown',function(e){
    if(e.key==='F12'){e.preventDefault();}
    if(e.ctrlKey&&e.shiftKey&&['I','i','J','j','C','c','K','k'].includes(e.key)){e.preventDefault();}
    if(e.ctrlKey&&['U','u'].includes(e.key)){e.preventDefault();}
    if(e.ctrlKey&&e.shiftKey&&e.key==='F'){e.preventDefault();}
  });
  setInterval(function(){
    var t=new Date();
    debugger;
    if(new Date()-t>100){document.body.innerHTML='';}
  },3000);
})();
</script>
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'%3E%3Crect width='64' height='64' rx='16' fill='%23F56F00'/%3E%3Ctext x='32' y='45' font-family='Arial' font-size='29' font-weight='700' fill='white' text-anchor='middle'%3E912%3C/text%3E%3C/svg%3E">
<style>
  :root{--orange:#F56F00;--blue:#2350C5;--ink:#15202B;--mute:#64748B;--line:#E6EAF0;--bg:#F4F6FA;--good:#16A34A;--bad:#D64933}
  *{box-sizing:border-box}
  body{margin:0;font-family:Poppins,system-ui,Arial,sans-serif;background:var(--bg);color:var(--ink)}
  .top{background:linear-gradient(135deg,#1B2A3A,#15202B 60%,#0E1822);color:#fff;padding:18px 20px;display:flex;align-items:center;gap:13px}
  .b{width:36px;height:36px;border-radius:10px;background:var(--orange);display:grid;place-items:center;font-weight:700;color:#fff}
  .top h1{font-size:16px;margin:0;letter-spacing:.5px}
  .top .sub{font-size:11px;color:#9AA7B8;margin-top:1px}
  .wrap{max-width:1000px;margin:0 auto;padding:18px 16px 40px}
  .kpis{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:8px}
  .kpi{background:#fff;border:1px solid var(--line);border-radius:14px;padding:14px;position:relative;overflow:hidden}
  .kpi::before{content:"";position:absolute;left:0;top:0;bottom:0;width:4px;background:var(--accent,var(--orange))}
  .kpi .l{font-size:9.5px;letter-spacing:.5px;text-transform:uppercase;color:var(--mute);font-weight:600}
  .kpi .n{font-size:28px;font-weight:800;letter-spacing:-.5px;margin-top:4px}
  .sect{display:flex;align-items:center;gap:10px;margin:18px 2px 9px}
  .sect b{font-size:11px;letter-spacing:.8px;text-transform:uppercase}
  .sect .ln{flex:1;height:1px;background:var(--line)}
  .people{display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:10px}
  .pcard{background:#fff;border:1px solid var(--line);border-radius:14px;padding:12px;cursor:pointer;transition:transform .15s,box-shadow .2s,border-color .15s}
  .pcard:hover{transform:translateY(-2px);box-shadow:0 8px 20px rgba(21,32,43,.1)}
  .pcard.sel{border-color:var(--orange);box-shadow:0 0 0 3px rgba(245,111,0,.14)}
  .pcard .ph{display:flex;align-items:center;gap:9px}
  .pcard .nm{font-weight:700;font-size:12.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .pcard .st{display:flex;gap:12px;margin-top:9px}
  .pcard .st div{font-size:10px;color:var(--mute);text-transform:uppercase;letter-spacing:.3px}
  .pcard .st b{display:block;font-size:16px;color:var(--ink)}
  .av{border-radius:50%;display:inline-grid;place-items:center;font-weight:700;color:#fff;flex:0 0 auto}
  .bar{display:flex;gap:9px;flex-wrap:wrap;align-items:center;margin:6px 0 12px}
  .chip{border:1px solid var(--line);background:#fff;border-radius:30px;padding:6px 13px;font-size:11.5px;font-weight:600;cursor:pointer;color:var(--mute)}
  .chip.on{background:var(--ink);color:#fff;border-color:var(--ink)}
  input[type=text],textarea{font-family:inherit;border:1px solid #CBD5E1;border-radius:10px;padding:9px 12px;font-size:13px;width:100%}
  .person{background:#fff;border:1px solid var(--line);border-radius:14px;margin-bottom:12px;overflow:hidden}
  .phead{display:flex;justify-content:space-between;align-items:center;padding:12px 14px;border-bottom:1px solid var(--line);background:#FBFCFE}
  .phead .nm{display:flex;align-items:center;gap:10px;font-weight:700;font-size:13px}
  .pcount{font-size:11px;color:var(--mute)}
  .task{padding:13px 14px;border-bottom:1px solid #F0F2F6}
  .task:last-child{border-bottom:0}
  .trow{display:flex;align-items:flex-start;gap:10px}
  .trow .ttl{font-weight:600;font-size:13px;flex:1}
  .trow .ttl.done{text-decoration:line-through;color:var(--mute)}
  .who{display:flex;gap:4px;margin:7px 0 0}
  .seg{display:inline-flex;border:1px solid var(--line);border-radius:9px;overflow:hidden;flex:0 0 auto}
  .seg button{border:0;background:#fff;padding:6px 12px;font-size:11px;font-weight:600;cursor:pointer;color:var(--mute)}
  .seg button.on.done{background:var(--good);color:#fff}
  .seg button.on.notdone{background:var(--bad);color:#fff}
  .grp{padding:6px 12px;font-size:11px;font-weight:600;color:var(--mute)}
  .grp.on{background:var(--ink);color:#fff}
  .flab{font-size:10px;font-weight:700;letter-spacing:.4px;text-transform:uppercase;color:var(--mute);margin:11px 0 5px}
  .achips{display:flex;flex-wrap:wrap;gap:6px;align-items:center}
  .achip{display:inline-flex;align-items:center;gap:6px;background:#F4F6FA;border:1px solid var(--line);border-radius:30px;padding:3px 6px 3px 3px;font-size:11.5px;font-weight:600}
  .achip b{cursor:pointer;color:#94A3B8;font-weight:700;padding:0 2px}
  .achip b:hover{color:var(--bad)}
  .addbtn{border:1px solid var(--ink);background:var(--ink);color:#fff;border-radius:9px;padding:0 12px;font-size:11.5px;font-weight:600;cursor:pointer;font-family:inherit;white-space:nowrap}
  .daterow{display:flex;align-items:center;gap:10px;flex-wrap:wrap;background:#fff;border:1px solid var(--line);border-radius:12px;padding:10px 12px;margin-bottom:12px}
  .daterow .dlab{font-size:11px;font-weight:700;letter-spacing:.3px;color:var(--ink)}
  .dfield{font-size:11px;color:var(--mute);font-weight:600;display:flex;align-items:center;gap:6px}
  .dfield input[type=date]{font-family:inherit;border:1px solid #CBD5E1;border-radius:8px;padding:6px 9px;font-size:12px;color:var(--ink)}
  .dpresets{display:flex;gap:6px;flex-wrap:wrap;margin-left:auto}
  .dchip{border:1px solid var(--line);background:#fff;border-radius:30px;padding:5px 12px;font-size:11px;font-weight:600;color:var(--mute);cursor:pointer;font-family:inherit}
  .dchip.on{background:var(--orange);color:#fff;border-color:var(--orange)}
  textarea{margin-top:9px;min-height:44px;font-size:12px}
  .saved{font-size:10px;color:var(--good);margin-top:3px;height:12px}
  .meta{font-size:10px;color:var(--mute);margin-top:5px}
  .empty{text-align:center;color:var(--mute);padding:34px;background:#fff;border:1px solid var(--line);border-radius:14px}
  .foot{color:#AEB9C7;font-size:10px;text-align:center;margin:22px 0;font-style:italic}
  /* compact, scannable, expandable task rows (matches the in-app To-Do) */
  .tkgrid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;align-items:start}
  @media (max-width:980px){ .tkgrid{grid-template-columns:repeat(2,minmax(0,1fr))} }
  @media (max-width:640px){ .tkgrid{grid-template-columns:1fr} }
  .tkcard{background:#fff;border:1px solid var(--line);border-radius:13px;overflow:hidden;box-shadow:0 1px 2px rgba(21,32,43,.06);transition:box-shadow .18s,border-color .18s}
  .tkcard:hover{box-shadow:0 8px 20px rgba(21,32,43,.1)}
  .tkcard.open{border-color:#CBD5E1}
  .tkcard.done{opacity:.62}
  .tk-row{display:flex;align-items:center;gap:11px;padding:11px 13px;cursor:pointer}
  .tk-check{flex:0 0 auto;width:22px;height:22px;border-radius:50%;border:2px solid #CBD5E1;display:grid;place-items:center;font-size:13px;font-weight:700;color:#fff;background:#fff;transition:background .15s,border-color .15s}
  .tk-check:hover{border-color:var(--good)}
  .tk-check.on{background:var(--good);border-color:var(--good)}
  .tk-main{flex:1;min-width:0}
  .tk-title{font-weight:600;font-size:13px;line-height:1.3;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
  .tk-title.done{text-decoration:line-through;color:var(--mute)}
  .tk-sub{display:flex;align-items:center;gap:7px;margin-top:3px;flex-wrap:wrap}
  .tk-tag{background:#FFF4EB;color:var(--orange);border:1px solid #F7D9BC;border-radius:20px;padding:1px 9px;font-size:10.5px;font-weight:600;max-width:190px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
  .tk-people{display:flex;align-items:center;flex:0 0 auto}
  .tk-people .av{margin-left:-7px;box-shadow:0 0 0 2px #fff}
  .tk-people .av:first-child{margin-left:0}
  .tk-more{margin-left:-7px;width:24px;height:24px;border-radius:50%;background:#E6EAF0;color:var(--mute);display:grid;place-items:center;font-size:10px;font-weight:700;box-shadow:0 0 0 2px #fff}
  .tk-caret{flex:0 0 auto;color:#9AA7B8;font-size:11px}
  .tk-body{padding:0 13px 13px;border-top:1px solid var(--line);background:#FBFCFE}
  .tk-body .flab:first-child{margin-top:11px}
</style></head>
<body>
  <div class="top"><div class="b">912</div>
    <div><h1>TASK BOARD</h1><div class="sub" id="dateline">Nine One Two Holdings</div></div></div>
  <div class="wrap">
    <div style="font-size:19px;font-weight:800;letter-spacing:-.3px;margin:2px 2px 14px">Team Task Manager</div>
    <div class="kpis">
      <div class="kpi" style="--accent:var(--blue)"><div class="l">Total tasks</div><div class="n" id="kTotal">—</div></div>
      <div class="kpi" style="--accent:var(--orange)"><div class="l">Open</div><div class="n" id="kOpen">—</div></div>
      <div class="kpi" style="--accent:var(--good)"><div class="l">Done</div><div class="n" id="kDone">—</div></div>
    </div>

    <div class="sect"><b>By person</b><span class="ln"></span></div>
    <div class="people" id="people"></div>

    <div class="sect"><b>Tasks</b><span class="ln"></span>
      <span class="seg" style="border-radius:9px">
        <button id="gPerson" class="grp on" onclick="setGroup('person')">By person</button>
        <button id="gDate" class="grp" onclick="setGroup('date')">By date</button>
      </span>
    </div>
    <div class="daterow">
      <div class="dlab">📅 Created</div>
      <label class="dfield">From <input id="dFrom" type="date" onchange="TB.from=this.value;syncPresets();paint()"></label>
      <label class="dfield">To <input id="dTo" type="date" onchange="TB.to=this.value;syncPresets();paint()"></label>
      <span class="dpresets">
        <button class="dchip" data-preset="today" onclick="preset('today')">Today</button>
        <button class="dchip" data-preset="7" onclick="preset('7')">7 days</button>
        <button class="dchip" data-preset="30" onclick="preset('30')">30 days</button>
        <button class="dchip on" data-preset="all" onclick="preset('all')">All</button>
      </span>
    </div>
    <div class="bar" id="filters"></div>
    <input id="q" type="text" placeholder="Search task or person…" oninput="TB.q=this.value;paint()" style="margin-bottom:12px">
    <div id="board"><div class="empty">Loading…</div></div>
    <datalist id="peopleList"></datalist>

    <div class="foot">Prepared by the Waitara Holdings Group of Companies Console.</div>
  </div>

<script>
let TB = { tasks:[], q:'', person:'', group:'person', from:'', to:'', open:{} };
function boardToggleCard(id){ TB.open[id]=!TB.open[id]; paint(); }
function setGroup(g){ TB.group=g; const p=document.getElementById('gPerson'),d=document.getElementById('gDate'); if(p&&d){p.classList.toggle('on',g==='person');d.classList.toggle('on',g==='date');} paint(); }
function ymdLocal(d){ return d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0')+'-'+String(d.getDate()).padStart(2,'0'); }
function preset(p){
  const today=new Date(); const t=ymdLocal(today);
  if(p==='all'){ TB.from=''; TB.to=''; }
  else if(p==='today'){ TB.from=t; TB.to=t; }
  else { const past=new Date(today.getTime()-(Number(p)-1)*86400000); TB.from=ymdLocal(past); TB.to=t; }
  const f=document.getElementById('dFrom'), to=document.getElementById('dTo');
  if(f) f.value=TB.from; if(to) to.value=TB.to;
  syncPresets(); paint();
}
function syncPresets(){
  const today=new Date(); const t=ymdLocal(today);
  let active='';
  if(!TB.from && !TB.to) active='all';
  else if(TB.from===t && TB.to===t) active='today';
  else if(TB.to===t && TB.from){ const days=Math.round((new Date(t)-new Date(TB.from))/86400000)+1; if(days===7)active='7'; else if(days===30)active='30'; }
  document.querySelectorAll('.dchip').forEach(b=>b.classList.toggle('on', b.dataset.preset===active));
}
const esc=s=>String(s==null?'':s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
function bInit(n){ const p=String(n||'?').trim().split(/\s+/); return ((p[0]||'?')[0]+(p[1]?p[1][0]:'')).toUpperCase(); }
function bColor(n){ const pal=['#F56F00','#2350C5','#16A34A','#7C3AED','#DB2777','#0891B2','#CA8A04','#DC2626']; let h=0,s=String(n||''); for(let i=0;i<s.length;i++)h=(h*31+s.charCodeAt(i))>>>0; return pal[h%pal.length]; }
function av(n,z){ z=z||28; const u=(n==='Unassigned'); return `<span class="av" style="width:${z}px;height:${z}px;font-size:${Math.round(z*0.4)}px;background:${u?'#94A3B8':bColor(n)}">${u?'?':bInit(n)}</span>`; }

function load(){
  fetch('api/tasks_public.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'list'})})
  .then(r=>r.json()).then(j=>{ TB.tasks=(j.ok&&j.tasks)?j.tasks:[]; paint(); })
  .catch(()=>{ document.getElementById('board').innerHTML='<div class="empty">Could not load tasks.</div>'; });
}
function toggle(id,done){
  const t=TB.tasks.find(x=>x.id===id); if(t){ t.status=done?'done':'open'; paint(); }
  fetch('api/tasks_public.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'toggle',id,status:done?'done':'open'})});
}
let noteTimers={};
function note(id,val){
  const el=document.getElementById('sv'+id); if(el) el.textContent='';
  clearTimeout(noteTimers[id]);
  noteTimers[id]=setTimeout(()=>{
    fetch('api/tasks_public.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'note',id,notes:val})})
    .then(r=>r.json()).then(()=>{ const e=document.getElementById('sv'+id); if(e){ e.textContent='saved ✓'; setTimeout(()=>{if(e)e.textContent='';},1500);} });
  },700);
}
let subjTimers={};
function subj(id,val){
  const t=TB.tasks.find(x=>x.id===id); if(t) t.subject=val;
  clearTimeout(subjTimers[id]);
  subjTimers[id]=setTimeout(()=>{
    fetch('api/tasks_public.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'subject',id,subject:val})})
    .then(r=>r.json()).then(()=>{ const e=document.getElementById('sj'+id); if(e){ e.textContent='saved ✓'; setTimeout(()=>{if(e)e.textContent='';},1500);} });
  },700);
}
function pickPerson(p){ TB.person=(TB.person===p?'':p); paint(); }

let KNOWN={};
function boardAssignFrom(id){
  const el=document.getElementById('ai'+id); if(!el) return;
  const name=(el.value||'').trim(); if(!name) return;
  const email=KNOWN[name.toLowerCase()]||'';
  boardAssign(id,name,email);
}
function boardAssign(id,name,email){
  const t=TB.tasks.find(x=>x.id===id);
  if(t){ t.assignees=t.assignees||[]; if(!t.assignees.some(a=>((a.name||'')===name)&&((a.email||'')===email))) t.assignees.push({name,email}); paint(); }
  fetch('api/tasks_public.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'assign',id,name,email})});
}
function boardUnassign(id,name,email){
  const t=TB.tasks.find(x=>x.id===id);
  if(t&&t.assignees){ t.assignees=t.assignees.filter(a=>!(((a.name||'')===name)&&((a.email||'')===email))); paint(); }
  fetch('api/tasks_public.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'unassign',id,name,email})});
}

function assigneesOf(t){ return (t.assignees&&t.assignees.length)?t.assignees.map(a=>a.name||a.email):['Unassigned']; }

function paint(){
  const total=TB.tasks.length, open=TB.tasks.filter(t=>t.status!=='done').length, done=total-open;
  document.getElementById('kTotal').textContent=total;
  document.getElementById('kOpen').textContent=open;
  document.getElementById('kDone').textContent=done;
  try{ document.getElementById('dateline').textContent = new Date().toLocaleDateString(undefined,{weekday:'long',year:'numeric',month:'long',day:'numeric'}); }catch(e){}

  // per-person stats
  const stat={};
  TB.tasks.forEach(t=>{ assigneesOf(t).forEach(p=>{ const s=stat[p]||(stat[p]={open:0,done:0,total:0}); s.total++; if(t.status==='done')s.done++; else s.open++; }); });
  const names=Object.keys(stat).sort((a,b)=>{ if(a==='Unassigned')return 1; if(b==='Unassigned')return -1; return stat[b].open-stat[a].open || a.localeCompare(b); });

  // known people (name->email) for the "Add a person" suggestions
  KNOWN={};
  TB.tasks.forEach(t=>(t.assignees||[]).forEach(a=>{ const n=a.name||a.email; if(n && !(n.toLowerCase() in KNOWN)) KNOWN[n.toLowerCase()]=a.email||''; }));
  const dl=document.getElementById('peopleList');
  if(dl) dl.innerHTML=Object.keys(stat).filter(n=>n!=='Unassigned').map(n=>`<option value="${esc(n)}">`).join('');

  // person summary cards
  document.getElementById('people').innerHTML = names.length? names.map(n=>`
    <div class="pcard ${TB.person===n?'sel':''}" onclick="pickPerson('${n.replace(/'/g,'')}')">
      <div class="ph">${av(n,30)}<div class="nm">${esc(n)}</div></div>
      <div class="st">
        <div>Open<b style="color:${stat[n].open?'var(--orange)':'var(--ink)'}">${stat[n].open}</b></div>
        <div>Done<b style="color:var(--good)">${stat[n].done}</b></div>
        <div>Total<b>${stat[n].total}</b></div>
      </div>
    </div>`).join('') : '<div class="empty" style="grid-column:1/-1">No one assigned yet.</div>';

  // filter chips
  document.getElementById('filters').innerHTML =
    `<span class="chip ${TB.person===''?'on':''}" onclick="TB.person='';paint()">All (${total})</span>`
    + names.map(n=>`<span class="chip ${TB.person===n?'on':''}" onclick="pickPerson('${n.replace(/'/g,'')}')">${esc(n)} (${stat[n].open})</span>`).join('');

  // build the visible set (respect person filter + search)
  const q=(TB.q||'').toLowerCase().trim();
  const visible = TB.tasks.filter(t=>{
    const who=assigneesOf(t);
    if(TB.person && !who.includes(TB.person)) return false;
    if(q && !((t.title||'').toLowerCase().includes(q) || who.some(p=>p.toLowerCase().includes(q)))) return false;
    const k=dateKey(t); // YYYY-MM-DD created date
    if(TB.from && (!k || k<TB.from)) return false;
    if(TB.to && (!k || k>TB.to)) return false;
    return true;
  });
  visible.sort((a,b)=> (a.status==='done')-(b.status==='done') || b.id-a.id );

  if(!visible.length){ const filt=(q||TB.person||TB.from||TB.to); document.getElementById('board').innerHTML='<div class="empty">No tasks'+(filt?' match this view':' yet')+'.</div>'; return; }

  if(TB.group==='date'){
    document.getElementById('board').innerHTML = renderByDate(visible);
  } else {
    const heading = TB.person
      ? `<div class="phead" style="border-radius:14px 14px 0 0"><div class="nm">${av(TB.person,28)}${esc(TB.person)}</div><span class="pcount">${visible.filter(t=>t.status!=='done').length} open · ${visible.filter(t=>t.status==='done').length} done</span></div>`
      : '';
    const cards = visible.map(taskCard).join('');
    document.getElementById('board').innerHTML = heading
      ? `<div class="person" style="padding-bottom:10px">${heading}<div class="tkgrid" style="padding:10px">${cards}</div></div>`
      : `<div class="tkgrid">${cards}</div>`;
  }
}

function ymd(d){ return d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0')+'-'+String(d.getDate()).padStart(2,'0'); }
function dateKey(t){ return t.created_at ? String(t.created_at).slice(0,10) : ''; }
function dateLabel(key){
  if(!key) return 'Undated';
  const today=new Date(); const ytd=new Date(today.getTime()-86400000);
  if(key===ymd(today)) return 'Today';
  if(key===ymd(ytd)) return 'Yesterday';
  const dt=new Date(key+'T00:00:00');
  return isNaN(dt)? key : dt.toLocaleDateString(undefined,{weekday:'short',day:'numeric',month:'short',year:'numeric'});
}
function renderByDate(visible){
  const buckets={};
  visible.forEach(t=>{ const k=dateKey(t); (buckets[k]=buckets[k]||[]).push(t); });
  // newest date first; Undated ('') last
  const keys=Object.keys(buckets).sort((a,b)=>{ if(a==='')return 1; if(b==='')return -1; return a<b?1:a>b?-1:0; });
  return keys.map(k=>{
    const items=buckets[k];
    const open=items.filter(t=>t.status!=='done').length, done=items.length-open;
    return `<div class="person" style="padding-bottom:10px">
      <div class="phead" style="border-radius:14px 14px 0 0"><div class="nm">🗓️ ${esc(dateLabel(k))}</div>
        <span class="pcount">${open} open · ${done} done</span></div>
      <div class="tkgrid" style="padding:10px">${items.map(taskCard).join('')}</div>
    </div>`;
  }).join('');
}

function taskCard(t){
  const d=t.status==='done';
  const isOpen=!!TB.open[t.id];
  const real=(t.assignees||[]);
  const avatars = real.length
    ? real.slice(0,4).map(a=>av(a.name||a.email,24)).join('') + (real.length>4?`<span class="tk-more">+${real.length-4}</span>`:'')
    : '';
  const head = `<div class="tk-row" onclick="boardToggleCard(${t.id})">
      <span class="tk-check ${d?'on':''}" title="${d?'Mark not done':'Mark done'}" onclick="event.stopPropagation();toggle(${t.id},${d?'false':'true'})">${d?'✓':''}</span>
      <div class="tk-main">
        <div class="tk-title ${d?'done':''}">${esc(t.title)}</div>
        <div class="tk-sub">${t.subject?`<span class="tk-tag">${esc(t.subject)}</span>`:''}${real.length?'':'<span style="font-size:10.5px;color:var(--bad);font-weight:600">Unassigned</span>'}</div>
      </div>
      <div class="tk-people">${avatars}</div>
      <span class="tk-caret">${isOpen?'▴':'▾'}</span>
    </div>`;
  if(!isOpen) return `<div class="tkcard ${d?'done':''}">${head}</div>`;

  const chips = real.length
    ? real.map(a=>{ const n=a.name||a.email; return `<span class="achip">${av(n,18)}<span>${esc(n)}</span><b onclick="boardUnassign(${t.id},'${(a.name||'').replace(/'/g,'')}','${(a.email||'').replace(/'/g,'')}')">✕</b></span>`; }).join('')
    : `<span style="font-size:11px;color:var(--bad)">No one assigned yet.</span>`;
  const body = `<div class="tk-body">
    <div class="seg" style="margin-top:11px">
      <button class="notdone ${!d?'on':''}" onclick="toggle(${t.id},false)">Not done</button>
      <button class="done ${d?'on':''}" onclick="toggle(${t.id},true)">Done</button>
    </div>
    <div class="flab">Subject</div>
    <input type="text" placeholder="Subject / short label…" value="${esc(t.subject||'')}" oninput="subj(${t.id},this.value)" style="font-size:12px;font-weight:600;color:var(--orange);border-color:#F1D6BD">
    <div class="saved" id="sj${t.id}"></div>
    <div class="flab">Assigned to</div>
    <div class="achips">${chips}</div>
    <div style="display:flex;gap:6px;margin-top:6px">
      <input type="text" list="peopleList" id="ai${t.id}" placeholder="Add a person…" style="flex:1;font-size:12px" onkeydown="if(event.key==='Enter'){boardAssignFrom(${t.id});}">
      <button class="addbtn" onclick="boardAssignFrom(${t.id})">+ Assign</button>
    </div>
    <div class="flab">Notes</div>
    <textarea placeholder="Add a note…" oninput="note(${t.id},this.value)">${esc(t.notes||'')}</textarea>
    <div class="saved" id="sv${t.id}"></div>
  </div>`;
  return `<div class="tkcard open ${d?'done':''}">${head}${body}</div>`;
}
load();
</script>
<div style="text-align:center;padding:18px 12px 22px;border-top:1px solid #E6EAF0;margin-top:24px;line-height:1.7">
  <div style="font-size:11.5px;color:#64748B">This system is designed for <b>912 Holdings</b>, Zone Fibre Limited, Waitara Holdings Limited, Smart Zone Fibre Limited &amp; Global IT Limited</div>
  <div style="font-size:11px;color:#F56F00;margin-top:5px">&#9888; If you are a staff member of any of the companies listed here and can see information of a company you are not associated with, report immediately to <b>Njuguna Waitara — +254 722 974 970</b> at a reward of <b>10,000 KES</b></div>
</div>
</body></html>

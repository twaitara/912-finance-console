
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


const zbInvUrl = id => (CFG.org && id) ? ('https://books.zoho.com/app/'+CFG.org+'#/invoices/'+id) : '';


const fmt = n => CFG.cur + ' ' + Math.round(n||0).toLocaleString('en-KE');
const fmtn = n => Math.round(n||0).toLocaleString('en-KE');
const fmt1 = n => (n||0).toLocaleString('en-KE',{maximumFractionDigits:1});
const today = () => new Date().toISOString().slice(0,10);
/* helpful tooltips for staff (technicians) only — admins don't need them */
const tip = t => ME.admin ? '' : (' title="'+String(t).replace(/"/g,'&quot;')+'"');
const days = (a,b) => Math.max(0, Math.round((new Date(b)-new Date(a))/86400000));
const dailyRate = CFG.rate/365;

let DEPLOYMENTS = [], INVOICES = [], INV_LOADED = false, EXPENSES = [], EXP_LOADED = false, TAB = 'dash';
let LOANS = [], LOANTOT = {lent:0, outstanding:0, repaid:0}, FUNDS = [], PRIMARY_ID = 0;
let LOANFORM = { type:'person', name:'', amount:'', date:'', expected:'', note:'', fundId:0, msg:'', err:false };
let FUNDFORM = { name:'', balance:'', msg:'', err:false };
const AUDREY_URL = location.href.split('#')[0].split('?')[0].replace(/[^/]*$/,'') + 'audrey.php';
const TASKBOARD_URL = location.href.split('#')[0].split('?')[0].replace(/[^/]*$/,'') + 'tasks_board.php';
const BEN_URL = location.href.split('#')[0].split('?')[0].replace(/[^/]*$/,'') + 'index.php?portal=ben';
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
function tabAllowed(t){ if(t==='users'||t==='clientaccess'||t==='activity'||t==='ask'||t==='portals'||t==='report') return !!ME.admin; if(t==='projects') return !!ME.admin || ME.tabs==='*' || (ME.tabs||[]).includes('projects') || (ME.tabs||[]).includes('costcap'); if(ME.admin || ME.tabs==='*') return true; return (ME.tabs||[]).includes(t); }
function firstAllowedTab(){ if(ME.admin||ME.tabs==='*') return 'dash'; const order=Object.keys(ALLTABS).filter(k=>k!=='audrey'&&k!=='taskboard'); return order.find(t=>tabAllowed(t)) || 'dash'; }
function applyPerms(){
  const fb=document.querySelector('.fundbar'); if(fb) fb.style.display = ME.admin? '' : 'none';
  document.querySelectorAll('.tabs button[data-tab]').forEach(b=>{ b.style.display = tabAllowed(b.dataset.tab)?'':'none'; });
  document.querySelectorAll('.tabs button[data-ext]').forEach(b=>{ const k=b.dataset.ext==='audrey.php'?'audrey':(b.dataset.ext==='tasks_board.php'?'taskboard':''); b.style.display = tabAllowed(k)?'':'none'; });
  document.querySelectorAll('.tabs .navgroup').forEach(g=>{ const any=[...g.querySelectorAll('.submenu button')].some(x=>x.style.display!=='none'); g.style.display = any?'':'none'; });
  document.querySelectorAll('#mobDrawer .mob-item[data-tab]').forEach(b=>{ b.style.display=tabAllowed(b.dataset.tab)?'':'none'; });
  updateMobActive();
  if(!ME.admin){
    const navTips={dash:'Your home — a quick view of your quotes and tasks',
      newquote:'Start a new quote for a customer',
      myquotes:'See and manage the quotes you have created',
      jobcards:'View the job cards you have generated',
      todo:'Your tasks and to-do list'};
    document.querySelectorAll('.tabs button[data-tab]').forEach(b=>{ const t=navTips[b.dataset.tab]; if(t) b.title=t; });
    const grpTips={'Create':'Make quotes and job cards','Tasks':'Your tasks'};
    document.querySelectorAll('.tabs .navgroup .grp').forEach(g=>{ const k=(g.textContent||'').trim(); Object.keys(grpTips).forEach(key=>{if(k.includes(key)) g.title=grpTips[key];}); });
    const cal=document.querySelector('a.logout[href*="connect_calendar"]'); if(cal) cal.title='Connect your calendar so task reminders land in it';
    const so=document.querySelector('a.logout[href="?logout=1"]'); if(so) so.title='Sign out of the app';
  }
}

function tabRefresh(){
  const btn=document.getElementById('globalRefreshBtn');
  if(btn){btn.classList.add('spinning');setTimeout(()=>btn.classList.remove('spinning'),1100);}
  switch(TAB){
    case 'report':    REPORT.loaded=false;REPORT.loading=false;REPORT.error=null;render();loadReport();break;
    case 'etr':       ETR.loaded=false;ETR.loading=false;ETR.data=null;render();etrLoad();break;
    case 'invrep':    INVR.loaded=false;INVR.loading=false;INVR.data=null;render();invrLoad();break;
    case 'quotes':    QUOT.loaded=false;QUOT.loading=false;QUOT.data=null;render();quotLoad();break;
    case 'qlist':     QLIST.loaded=false;QLIST.loading=false;QLIST.allItems=[];QLIST.page=1;render();qlistLoad();break;
    case 'ivlist':    IVLIST.loaded=false;IVLIST.loading=false;IVLIST.allItems=[];IVLIST.page=1;render();ivlistLoad();break;
    case 'stmtbuild': SB.loaded=false;SB.loading=false;SB.invoices=[];SB.result=null;SB.msg='';render();sbLoad();break;
    case 'latepay':   LATE.loaded=false;LATE.loading=false;LATE.data=null;render();lateLoad(true);break;
    case 'bulkexp':   render();expLoadAccounts();break;
    case 'emails':    EMAIL.loaded=false;EMAIL.loadingClients=false;EMAIL.clients=[];render();emailLoadClients();break;
    case 'todo':      TASK.loaded=false;TASK.loading=false;TASK.tasks=[];render();todoLoad();break;
    case 'loans':     LOANS=[];render();loadLoans();break;
    case 'myquotes':  MQ.loaded=false;MQ.loading=false;MQ.quotes=[];render();mqLoad();break;
    case 'projects':  PROJ.loaded=false;PROJ.loading=false;render();projLoad();break;
    case 'jobcards':  render();loadJobCards();break;
    case 'clientaccess': render();if(ME.admin)qbAssignLoad();break;
    case 'activity':  render();if(ME.admin)loadActivity();break;
    case 'settings':  render();if(ME.admin)usersLoad();break;
    case 'payments':
      PAY.loaded=false;PAY.loading=false;PAY.accsLoaded=false;
      PAY.clients=[];PAY.accounts=[];PAY.invoices=[];PAY.sel={};
      PAY.picked=false;PAY.clientId='';PAY.clientName='';PAY.q='';
      PAY.msg='';PAY.done=null;
      render();payLoadClients();payLoadAccounts();break;
    case 'bulkpay':
      BULK.loaded=false;BULK.loading=false;BULK.invoices=[];BULK.sel={};BULK.done=[];BULK.msg='';
      render();bulkLoad();if(!PAY.accsLoaded)payLoadAccounts();break;
    case 'dash':      DPAID.loaded=false;DPAID.data=null;loadTasks();checkBackup();loadQuotes();dashPaidLoad();if(!USERS.loaded)usersLoad();if(ME.admin)expLoadAccounts();render();break;
    default:          render();
  }
}

function render(){
  const p = document.getElementById('pane');
  // Preserve text input focus + cursor position across re-renders (fixes search inputs)
  const _ae=document.activeElement;
  const _fid=(_ae&&_ae.id&&(_ae.tagName==='INPUT'||_ae.tagName==='TEXTAREA')&&_ae.type!=='checkbox'&&_ae.type!=='radio')?_ae.id:null;
  const _ss=_fid?_ae.selectionStart:0, _se=_fid?_ae.selectionEnd:0;
  if(!tabAllowed(TAB)) TAB = firstAllowedTab();
  const _tabNames={dash:'Dashboard',deploy:'Deployments',ledger:'Ledger',loans:'Loans',growth:'Growth',report:'Profit Report',etr:'ETR',invrep:'Invoice Report',quotes:'Quotes',payments:'Payments',bulkpay:'Bulk Mark Paid',settings:'Settings',emails:'Email Clients',todo:'To-Do',newquote:'New Quote',myquotes:'My Quotes',projects:'Projects',jobcards:'Job Cards',clientaccess:'Client Access',activity:'Activity',qlist:'Quotes Browser',ivlist:'Invoice Browser',stmtbuild:'Statement Builder',latepay:'Late Payers',bulkexp:'Log Expenses',ask:'Ask your books',portals:'Portals'};
  document.title = (ME.user||'Console') + ' · ' + (_tabNames[TAB]||TAB) + ' · 912';
  if(TAB==='dash') p.innerHTML = vDash();
  if(TAB==='deploy') p.innerHTML = vDeploy();
  if(TAB==='ledger') p.innerHTML = vLedger();
  if(TAB==='loans') p.innerHTML = vLoans();
  if(TAB==='growth'){ p.innerHTML = vGrowth(); drawChart(); }
  if(TAB==='report') p.innerHTML = vReport();
  if(TAB==='etr') p.innerHTML = vETR();
  if(TAB==='invrep') p.innerHTML = vInvRep();
  if(TAB==='quotes') p.innerHTML = vQuotes();
  if(TAB==='qlist') p.innerHTML = vQList();
  if(TAB==='ivlist') p.innerHTML = vIVList();
  if(TAB==='payments'){ p.innerHTML = vPayments(); if(!PAY.loaded) payLoadClients(); if(!PAY.accsLoaded) payLoadAccounts(); }
  if(TAB==='bulkpay'){ p.innerHTML = vBulkPay(); if(!BULK.loaded) bulkLoad(); if(!PAY.accsLoaded) payLoadAccounts(); }
  if(TAB==='stmtbuild'){ p.innerHTML = vStmtBuild(); if(!SB.loaded && !SB.loading) sbLoad(); }
  if(TAB==='latepay'){ p.innerHTML = vLatePay(); if(!LATE.loaded && !LATE.loading) lateLoad(); }
  if(TAB==='bulkexp'){ p.innerHTML = vBulkExp(); expLoadAccounts(); }
  if(TAB==='ask'){ p.innerHTML = vAsk(); askScrollDown(); if(ME.admin&&!ASK.savedLoaded){ ASK.savedLoaded=true; askConvosLoad(); } }
  if(TAB==='settings'){ p.innerHTML = vSettings(); if(ME.admin){ whLoad(); } }
  if(TAB==='portals'){ p.innerHTML = vPortals(); if(ME.admin){ benStatusLoad(); benPrevLoad(); } }
  if(TAB==='emails') p.innerHTML = vEmail();
  if(TAB==='todo') p.innerHTML = vTodo();
  if(TAB==='newquote') p.innerHTML = vNewQuote();
  if(TAB==='myquotes') p.innerHTML = vMyQuotes();
  if(TAB==='projects'){ p.innerHTML = vProjects(); if(!PROJ.loaded && !PROJ.loading) projLoad(); }
  if(TAB==='jobcards') p.innerHTML = vJobCards();
  if(TAB==='clientaccess') p.innerHTML = vClientAccess();
  if(TAB==='activity') p.innerHTML = vActivity();
  // the capital "Fund deployed" bar belongs only to the dashboard + working-capital tabs
  const WC_TABS={dash:1,deploy:1,ledger:1,loans:1,growth:1};
  const fb=document.querySelector('.fundbar'); if(fb) fb.style.display=(ME.admin && WC_TABS[TAB])?'':'none';
  paintFund();
  if(QB.modalOpen){ const mb=document.getElementById('qbModalBody'); if(mb) mb.innerHTML=vNewQuote();
    const mt=document.getElementById('qbModalTitle'); if(mt) mt.textContent=QB.id?'Edit quote':'New quote'; }
  // Restore focus to whichever text input was active before the re-render
  if(_fid){ const re=document.getElementById(_fid); if(re){ re.focus(); try{re.setSelectionRange(_ss,_se);}catch(e){} } }
}

let DPAID = {loaded:false, loading:false, data:null, view:null};
function dashPayView(v){ DPAID.view=v; render(); }
function dashPaidLoad(){
  if(DPAID.loading) return;
  DPAID.loading=true;
  fetch('api/dash_paid.php',{credentials:'same-origin'})
    .then(r=>r.json()).then(j=>{
      DPAID.loading=false; DPAID.loaded=true; DPAID.data=j.ok?j:null;
      if(TAB==='dash') render();
    }).catch(()=>{ DPAID.loading=false; DPAID.loaded=true; });
}
function vDashPaid(){
  const d=DPAID.data;
  if(DPAID.loading) return `<div class="card" style="padding:14px 16px;margin-bottom:10px"><div class="muted" style="font-size:11.5px">Loading recent payments…</div></div>`;
  if(!d) return '';
  const wht=Math.max(0,Math.round(d.gross-d.net));
  const rows=(d.rows||[]).map((r,i)=>`
    <div style="display:grid;grid-template-columns:24px 1fr auto auto;align-items:center;gap:6px 10px;padding:6px 0;border-bottom:1px solid var(--line)">
      <span style="font-size:10px;color:var(--mute);text-align:right">${i+1}</span>
      <span style="font-size:11.5px;font-weight:600;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${r.customer}</span>
      <span style="font-size:10.5px;color:var(--mute);white-space:nowrap">${r.date}</span>
      <span style="font-size:12px;font-weight:700;color:var(--good);white-space:nowrap">KES ${Math.round(r.amount).toLocaleString('en-KE')}</span>
    </div>`).join('');
  return `<div class="card" style="padding:0;margin-bottom:10px;overflow:hidden">
    <div style="background:var(--grad-orange);padding:14px 16px 12px">
      <div style="font-size:9.5px;font-weight:700;text-transform:uppercase;letter-spacing:.7px;color:rgba(255,255,255,.72);margin-bottom:10px">Payments received · ${new Date(d.from+'T00:00:00').toLocaleString('en-KE',{month:'long',year:'numeric'})} · ${d.count} payment${d.count!==1?'s':''}</div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px 20px">
        <div>
          <div style="font-size:9.5px;color:rgba(255,255,255,.65);margin-bottom:2px">Gross billed</div>
          <div style="font-size:21px;font-weight:800;color:#fff;line-height:1">KES ${Math.round(d.gross).toLocaleString('en-KE')}</div>
          ${wht>0?`<div style="font-size:9.5px;color:rgba(255,255,255,.6);margin-top:3px">WHT −KES ${wht.toLocaleString('en-KE')}</div>`:''}
        </div>
        <div>
          <div style="font-size:9.5px;color:rgba(255,255,255,.65);margin-bottom:2px">Net received</div>
          <div style="font-size:21px;font-weight:800;color:#fff;line-height:1">KES ${Math.round(d.net).toLocaleString('en-KE')}</div>
        </div>
        <div>
          <div style="font-size:9.5px;color:rgba(255,255,255,.65);margin-bottom:2px">Expenses</div>
          <div style="font-size:17px;font-weight:700;color:rgba(255,255,255,.85);line-height:1">KES ${Math.round(d.expenses).toLocaleString('en-KE')}</div>
        </div>
        <div style="border-left:2px solid rgba(255,255,255,.25);padding-left:16px">
          <div style="font-size:9.5px;color:rgba(255,255,255,.65);margin-bottom:2px">Profit</div>
          <div style="font-size:22px;font-weight:800;color:#fff;line-height:1">${d.profit<0?'−':''}KES ${Math.abs(Math.round(d.profit)).toLocaleString('en-KE')}</div>
          ${d.profit<0?`<div style="font-size:9.5px;color:rgba(255,200,100,.9);margin-top:3px">expenses exceed revenue</div>`:''}
        </div>
      </div>
    </div>
    ${rows?`<div style="padding:8px 16px 4px">
      <div style="font-size:9.5px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--mute);margin-bottom:2px">${d.count} payments this month — newest first</div>
    </div>
    <div style="padding:0 16px 12px;max-height:420px;overflow-y:auto">${rows}</div>`:''}
  </div>`;
}

function vDashTeamQuotes(){
  const mo=new Date().toLocaleString('en-KE',{month:'long',year:'numeric'});
  const thisMonth=new Date().toISOString().slice(0,7);
  const monthQ=(MQ.quotes||[]).filter(q=>String(q.created_at||q.quote_date||'').slice(0,7)===thisMonth);
  // Build user list: all known users + anyone who created a quote this month but isn't in USERS.list
  const knownUsers=(USERS.list||[]).filter(u=>!u.disabled).map(u=>u.username);
  const quoteCreators=[...new Set(monthQ.map(q=>q.created_by).filter(Boolean))];
  const allNames=[...new Set([...knownUsers,...quoteCreators])].sort((a,b)=>a.localeCompare(b));
  const shortName=n=>{const p=String(n||'').trim().split(/\s+/);return p.length>1?p[0]+' '+p[1][0].toUpperCase()+'.':p[0]||n;};
  const rows=allNames.map(name=>{
    const uq=monthQ.filter(q=>String(q.created_by||'').toLowerCase()===name.toLowerCase());
    const inv=uq.filter(q=>q.status==='invoiced'||q.zoho_invoice_number);
    const genVal=uq.reduce((s,q)=>s+(+q.total||0),0);
    const invVal=inv.reduce((s,q)=>s+(+q.total||0),0);
    const pct=genVal>0?Math.round(invVal/genVal*100):0;
    const barColor=pct>=50?'var(--good)':pct>=25?'var(--orange)':'#E2E8F0';
    const isMe=name.toLowerCase()===String(ME.user||'').toLowerCase();
    return `<tr style="${isMe?'background:rgba(245,111,0,.08)':''}">
      <td style="padding:8px 10px;font-size:12px;font-weight:${isMe?700:500};white-space:nowrap;color:var(--ink)">${shortName(name)}${isMe?' 👤':''}</td>
      <td style="padding:8px 10px;font-size:13px;font-weight:700;text-align:center">${uq.length}</td>
      <td style="padding:8px 10px;font-size:13px;font-weight:700;color:${inv.length?'var(--good)':'var(--mute)'};text-align:center">${inv.length}</td>
      <td style="padding:8px 10px;font-size:11px;color:var(--mute);text-align:right">KES ${Math.round(genVal).toLocaleString('en-KE')}</td>
      <td style="padding:8px 10px;min-width:90px">
        <div style="display:flex;align-items:center;gap:6px">
          <div style="flex:1;height:5px;background:#F1F4F8;border-radius:3px;overflow:hidden">
            <div style="height:100%;width:${pct}%;background:${barColor};border-radius:3px"></div>
          </div>
          <span style="font-size:10.5px;font-weight:700;color:${barColor==='#E2E8F0'?'var(--mute)':barColor};width:32px;text-align:right">${pct}%</span>
        </div>
      </td>
    </tr>`;
  });
  // totals row
  const totGen=monthQ.length, totInv=monthQ.filter(q=>q.status==='invoiced'||q.zoho_invoice_number).length;
  const totVal=monthQ.reduce((s,q)=>s+(+q.total||0),0);
  const totPct=totVal>0?Math.round(totInv/totGen*100*totInv/totInv||0):0; // weighted by count
  const totPct2=totGen>0?Math.round(totInv/totGen*100):0;
  return `<div class="card" style="padding:0;margin-bottom:10px;overflow:hidden">
    <div style="padding:12px 14px 10px;border-bottom:1px solid var(--line);display:flex;justify-content:space-between;align-items:center">
      <div style="font-size:9.5px;font-weight:700;text-transform:uppercase;letter-spacing:.7px;color:var(--mute)">Quotes · ${mo}</div>
      <div style="display:flex;gap:16px">
        <div style="text-align:center"><div style="font-size:9px;color:var(--mute)">Generated</div><div style="font-size:18px;font-weight:800">${totGen}</div></div>
        <div style="text-align:center"><div style="font-size:9px;color:var(--mute)">Converted</div><div style="font-size:18px;font-weight:800;color:var(--good)">${totInv}</div></div>
        <div style="text-align:center"><div style="font-size:9px;color:var(--mute)">Rate</div><div style="font-size:18px;font-weight:800;color:${totPct2>=50?'var(--good)':totPct2>=25?'var(--orange)':'var(--bad)'}">${totPct2}%</div></div>
      </div>
    </div>
    <table style="width:100%;border-collapse:collapse">
      <thead><tr style="background:var(--surface-2)">
        <th style="padding:6px 10px;font-size:9.5px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--mute);text-align:left">Staff</th>
        <th style="padding:6px 10px;font-size:9.5px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--mute);text-align:center">Generated</th>
        <th style="padding:6px 10px;font-size:9.5px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--mute);text-align:center">Converted</th>
        <th style="padding:6px 10px;font-size:9.5px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--mute);text-align:right">Value generated</th>
        <th style="padding:6px 10px;font-size:9.5px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--mute)">Rate</th>
      </tr></thead>
      <tbody style="border-top:1px solid var(--line)">${rows.join('')}</tbody>
    </table>
  </div>`;
}

/* ---- Dashboard quick-expense widget ---- */
let DEXP = { amount:'', desc:'', date:(()=>{const d=new Date();return d.toISOString().slice(0,10);})(), accQ:'', invoice:'', invQ:'', invResults:[], invLoading:false, invFor:'', saving:false, msg:'', err:false };
function dexpField(k,v){ DEXP[k]=v; }
/* first Cost-of-Goods-Sold account in the chart (used when a cost is assigned to an invoice) */
function dexpCogs(){ const a=(EXP.accounts&&EXP.accounts.expense)||[]; return a.find(x=>x.type==='cost_of_goods_sold')||null; }
/* ---- assign-to-invoice search ---- */
let _dexpInvTimer=null;
function dexpInvSearch(v){
  DEXP.invQ=v; DEXP.invoice='';
  const dd=document.getElementById('dexpInvDD'); if(dd){ dd.innerHTML=dexpInvResultsHtml(); dd.style.display='block'; }
  clearTimeout(_dexpInvTimer);
  const q=String(v||'').trim();
  if(q.length<2){ DEXP.invResults=[]; DEXP.invFor=''; return; }
  _dexpInvTimer=setTimeout(()=>dexpInvFetch(q),350);
}
function dexpInvFetch(q){
  DEXP.invLoading=true; const dd=document.getElementById('dexpInvDD'); if(dd) dd.innerHTML=dexpInvResultsHtml();
  fetch('api/invoice_search.php?q='+encodeURIComponent(q),{credentials:'same-origin'}).then(r=>r.json()).then(j=>{
    DEXP.invLoading=false; DEXP.invFor=q; DEXP.invResults=(j&&j.ok)?(j.invoices||[]):[];
    if(String(DEXP.invQ||'').trim()===q){ const e=document.getElementById('dexpInvDD'); if(e){ e.innerHTML=dexpInvResultsHtml(); e.style.display='block'; } }
  }).catch(()=>{ DEXP.invLoading=false; const e=document.getElementById('dexpInvDD'); if(e) e.innerHTML=dexpInvResultsHtml(); });
}
function dexpInvResultsHtml(){
  const q=String(DEXP.invQ||'').trim();
  if(q.length<2) return '';
  if(DEXP.invLoading && DEXP.invFor!==q) return `<div style="padding:8px 11px;color:var(--mute);font-size:11.5px">Searching Zoho…</div>`;
  const res=DEXP.invResults||[];
  if(!res.length) return `<div style="padding:8px 11px;color:var(--mute);font-size:11.5px">No match. <span style="color:var(--blue);cursor:pointer;font-weight:600" onmousedown="event.preventDefault();dexpInvUseTyped()">Use “${qesc(q)}” anyway</span></div>`;
  return res.map(iv=>`<div data-num="${qesc(iv.number)}" onmousedown="event.preventDefault();dexpInvPickEl(this)" style="padding:8px 11px;font-size:12px;cursor:pointer;border-bottom:1px solid var(--line);background:#fff" onmouseover="this.style.background='#F1F4F8'" onmouseout="this.style.background='#fff'"><b style="color:var(--orange)">${qesc(iv.number)}</b> · ${qesc(iv.client||'')}</div>`).join('');
}
function dexpInvPickEl(el){ DEXP.invoice=el.getAttribute('data-num'); DEXP.invQ=DEXP.invoice; const dd=document.getElementById('dexpInvDD'); if(dd) dd.style.display='none'; render(); }
function dexpInvUseTyped(){ const q=String(DEXP.invQ||'').trim(); if(q){ DEXP.invoice=q; const dd=document.getElementById('dexpInvDD'); if(dd) dd.style.display='none'; render(); } }
function dexpInvClear(){ DEXP.invoice=''; DEXP.invQ=''; DEXP.invResults=[]; render(); }
function dexpInvBlur(){ setTimeout(()=>{ const dd=document.getElementById('dexpInvDD'); if(dd) dd.style.display='none'; },160); }
function dexpFmt(){ const a=String(DEXP.amount||''); const dot=a.indexOf('.'); const intp=dot>=0?a.slice(0,dot):a; const decp=dot>=0?a.slice(dot+1):null; return intp.replace(/\B(?=(\d{3})+(?!\d))/g,',') + (decp!==null?'.'+decp:''); }
function dexpAmount(el){
  const v=el.value, pos=el.selectionStart||0;
  const digitsBefore=(v.slice(0,pos).match(/\d/g)||[]).length;     // remember cursor by digits to its left
  const raw=v.replace(/[^0-9.]/g,'');
  const dot=raw.indexOf('.');
  const intp=(dot>=0?raw.slice(0,dot):raw).replace(/\./g,'');
  const decp=dot>=0?raw.slice(dot+1).replace(/\./g,'').slice(0,2):null;   // max 2 decimals
  DEXP.amount = intp + (decp!==null?'.'+decp:'');
  const formatted = intp.replace(/\B(?=(\d{3})+(?!\d))/g,',') + (decp!==null?'.'+decp:'');
  el.value=formatted;
  let np=0,seen=0; while(np<formatted.length && seen<digitsBefore){ if(/\d/.test(formatted[np])) seen++; np++; }
  el.setSelectionRange(np,np);
}
function dexpAccMatches(){
  const acc=EXP.accounts; if(!acc) return [];
  const q=String(DEXP.accQ||'').trim().toLowerCase();
  let list=acc.expense||[];
  if(q) list=list.filter(a=>(a.name||'').toLowerCase().includes(q));
  return list.slice(0,50);
}
function dexpAccDDHtml(){
  const list=dexpAccMatches();
  if(!list.length) return `<div style="padding:9px 11px;color:var(--mute);font-size:12px">No expense account matches.</div>`;
  return list.map(a=>{
    const on=a.id===EXP.acc;
    return `<div onmousedown="event.preventDefault();dexpAccChoose('${a.id}')" style="padding:8px 11px;font-size:12.5px;cursor:pointer;border-bottom:1px solid var(--line);background:${on?'#FFF4EB':'#fff'};${on?'font-weight:600':''}" onmouseover="this.style.background='#F1F4F8'" onmouseout="this.style.background='${on?'#FFF4EB':'#fff'}'">${(a.name||'').replace(/</g,'&lt;')}</div>`;
  }).join('');
}
function dexpAccSearch(v){ DEXP.accQ=v; EXP.acc=''; const dd=document.getElementById('dexpAccDD'); if(dd){ dd.innerHTML=dexpAccDDHtml(); dd.style.display='block'; } const inp=document.getElementById('dexpAccInp'); if(inp) inp.style.borderColor='#F7C99A'; }
function dexpAccFocus(){ const dd=document.getElementById('dexpAccDD'); if(dd){ dd.innerHTML=dexpAccDDHtml(); dd.style.display='block'; } }
function dexpAccChoose(id){
  const acc=EXP.accounts; if(!acc) return;
  const a=(acc.expense||[]).find(x=>x.id===id); if(!a) return;
  EXP.acc=id; DEXP.accQ=a.name;
  const inp=document.getElementById('dexpAccInp'); if(inp){ inp.value=a.name; inp.style.borderColor='var(--line)'; }
  const dd=document.getElementById('dexpAccDD'); if(dd) dd.style.display='none';
}
function dexpAccBlur(){ setTimeout(()=>{ const dd=document.getElementById('dexpAccDD'); if(dd) dd.style.display='none'; },150); }
async function dexpSave(){
  const amt=parseFloat(DEXP.amount)||0;
  if(amt<=0){ DEXP.msg='Enter an amount.'; DEXP.err=true; render(); return; }
  const toInvoice = !!String(DEXP.invoice||'').trim();
  let endpoint, body;
  if(toInvoice){
    // cost tied to an invoice always books as Cost of Goods Sold
    const cogs=dexpCogs();
    if(!cogs){ DEXP.msg='No “Cost of Goods Sold” account found in Zoho — create one to book invoice costs.'; DEXP.err=true; render(); return; }
    endpoint='api/expense_add.php';
    body={ invoice_number:DEXP.invoice.trim(), amount:amt, account_id:cogs.id, paid_through_account_id:EXP.paid, date:DEXP.date||today(), description:DEXP.desc||('Cost for '+DEXP.invoice.trim()) };
  } else {
    if(!EXP.acc){ DEXP.msg='Pick an expense account (or assign the cost to an invoice).'; DEXP.err=true; render(); return; }
    endpoint='api/expense_quick.php';
    body={ amount:amt, description:DEXP.desc, account_id:EXP.acc, paid_through_account_id:EXP.paid, date:DEXP.date||today() };
  }
  DEXP.saving=true; DEXP.msg=''; render();
  try{
    const r=await fetch(endpoint,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)});
    const j=await r.json();
    DEXP.saving=false;
    if(j.ok){ DEXP.msg = toInvoice ? ('Cost booked to '+DEXP.invoice.trim()+' as Cost of Goods Sold ✓') : 'Expense logged in Zoho ✓'; DEXP.err=false;
      DEXP.amount=''; DEXP.desc=''; DEXP.invoice=''; DEXP.invQ=''; DEXP.invResults=[]; DPAID.loaded=false; DPAID.data=null; dashPaidLoad(); render(); }
    else { DEXP.msg='Error: '+(j.error||'failed'); DEXP.err=true; render(); }
  }catch(e){ DEXP.saving=false; DEXP.msg='Error: '+e; DEXP.err=true; render(); }
}
function vDashQuickExpense(){
  const acc=EXP.accounts;
  if(acc && EXP.acc && !DEXP.accQ){ const sa=(acc.expense||[]).find(x=>x.id===EXP.acc); if(sa) DEXP.accQ=sa.name; }
  const paidOpts=acc?['<option value="">— paid through (optional) —</option>'].concat((acc.paid||[]).map(a=>`<option value="${a.id}" ${a.id===EXP.paid?'selected':''}>${(a.name||'').replace(/</g,'&lt;')}</option>`)).join(''):'';
  return `<div class="card" style="padding:14px 16px;margin-bottom:0">
    <div style="display:flex;align-items:center;gap:7px;margin-bottom:10px">
      <span style="font-size:15px">🧾</span>
      <b style="font-size:12.5px;letter-spacing:.2px">Log an expense</b>
      <span class="muted" style="font-size:10px;margin-left:auto">straight to Zoho</span>
    </div>
    ${!acc?`<div class="muted" style="font-size:11.5px;padding:6px 0">${EXP.loadingAcc?'Loading accounts…':'<span style="color:var(--orange);cursor:pointer" onclick="expLoadAccounts();render()">Load accounts →</span>'}</div>`:`
    <input id="dexpAmt" type="text" inputmode="decimal" placeholder="Amount (KES)" value="${dexpFmt()}" oninput="dexpAmount(this)" style="width:100%;box-sizing:border-box;padding:9px 11px;border:1px solid var(--line);border-radius:9px;font-size:13px;font-family:inherit;margin-bottom:8px">
    <input type="text" placeholder="What for? (description)" value="${(DEXP.desc||'').replace(/"/g,'&quot;')}" oninput="dexpField('desc',this.value)" style="width:100%;box-sizing:border-box;padding:9px 11px;border:1px solid var(--line);border-radius:9px;font-size:13px;font-family:inherit;margin-bottom:8px">
    <input type="date" value="${DEXP.date}" onchange="dexpField('date',this.value)" style="width:100%;box-sizing:border-box;padding:9px 11px;border:1px solid var(--line);border-radius:9px;font-size:12.5px;font-family:inherit;margin-bottom:8px">
    <div style="position:relative;margin-bottom:8px">
      <input id="dexpInvInp" type="text" autocomplete="off" placeholder="🔍 Assign to invoice # (optional)" value="${qesc(DEXP.invoice||DEXP.invQ||'')}" oninput="dexpInvSearch(this.value)" onblur="dexpInvBlur()" style="width:100%;box-sizing:border-box;padding:9px 11px;border:1px solid ${DEXP.invoice?'#C7D2FE':'var(--line)'};border-radius:9px;font-size:12.5px;font-family:inherit">
      <div id="dexpInvDD" style="display:none;position:absolute;left:0;right:0;top:calc(100% + 2px);background:#fff;border:1px solid var(--line);border-radius:9px;box-shadow:0 10px 26px rgba(21,32,43,.16);max-height:200px;overflow-y:auto;z-index:41"></div>
    </div>
    ${DEXP.invoice
      ? `<div style="display:flex;align-items:center;gap:8px;background:#EEF2FE;border:1px solid #C7D2FE;border-radius:9px;padding:8px 11px;margin-bottom:8px">
           <span style="font-size:11.5px;color:var(--ink)">→ Books as <b>Cost of Goods Sold</b> on <b>${qesc(DEXP.invoice)}</b></span>
           <span style="margin-left:auto;cursor:pointer;color:var(--bad);font-weight:700" onclick="dexpInvClear()" title="Remove invoice">×</span>
         </div>`
      : `<div style="position:relative;margin-bottom:8px">
           <input id="dexpAccInp" type="text" autocomplete="off" placeholder="🔍 Search expense account…" value="${(DEXP.accQ||'').replace(/"/g,'&quot;')}" oninput="dexpAccSearch(this.value)" onfocus="dexpAccFocus()" onblur="dexpAccBlur()" style="width:100%;box-sizing:border-box;padding:9px 11px;border:1px solid ${EXP.acc?'var(--line)':'#F7C99A'};border-radius:9px;font-size:12.5px;font-family:inherit">
           <div id="dexpAccDD" style="display:none;position:absolute;left:0;right:0;top:calc(100% + 2px);background:#fff;border:1px solid var(--line);border-radius:9px;box-shadow:0 10px 26px rgba(21,32,43,.16);max-height:200px;overflow-y:auto;z-index:40"></div>
         </div>`}
    <select onchange="EXP.paid=this.value;render()" style="width:100%;box-sizing:border-box;padding:9px 11px;border:1px solid var(--line);border-radius:9px;font-size:12.5px;font-family:inherit;margin-bottom:10px">${paidOpts}</select>
    <button class="btn" style="width:100%" onclick="dexpSave()" ${DEXP.saving?'disabled':''}>${DEXP.saving?'Saving…':'＋ Log expense'}</button>
    ${DEXP.msg?`<div class="${DEXP.err?'warn':'ok'}" style="margin-top:8px;font-size:11.5px">${DEXP.msg}</div>`:''}`}
  </div>`;
}

function vDashMyQuotes(){
  if(!MQ.loaded) return `<div class="card" style="padding:14px 16px;margin-bottom:10px"><span class="muted" style="font-size:11.5px">Loading quote stats…</span></div>`;
  // admin: filter to own quotes; non-admin: server already scopes to their quotes
  const all=ME.admin
    ?(MQ.quotes||[]).filter(q=>String(q.created_by||'').toLowerCase()===String(ME.user||'').toLowerCase())
    :(MQ.quotes||[]);
  if(!all.length) return '';
  const invoiced =all.filter(q=>q.status==='invoiced'||q.zoho_invoice_number);
  const approved =all.filter(q=>q.status==='approved'||q.status==='accepted');
  const pending  =all.filter(q=>['pending_approval','sent','draft'].includes(q.status));
  const declined =all.filter(q=>q.status==='declined'||q.status==='rejected');
  const totalVal  =all.reduce((s,q)=>s+(+q.total||0),0);
  const invVal    =invoiced.reduce((s,q)=>s+(+q.total||0),0);
  const appVal    =approved.reduce((s,q)=>s+(+q.total||0),0);
  const pct=totalVal>0?Math.round(invVal/totalVal*100):0;
  const barColor=pct>=50?'var(--good)':pct>=25?'var(--orange)':'var(--bad)';
  return `<div class="card" style="padding:14px 16px;margin-bottom:10px">
    <div style="font-size:9.5px;font-weight:700;text-transform:uppercase;letter-spacing:.7px;color:var(--mute);margin-bottom:12px">My conversion performance</div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px 20px;margin-bottom:14px">
      <div>
        <div style="font-size:9.5px;color:var(--mute);margin-bottom:3px">Quotes generated</div>
        <div style="font-size:26px;font-weight:800;line-height:1">${all.length}</div>
        <div style="font-size:11.5px;color:var(--mute);margin-top:4px">KES ${Math.round(totalVal).toLocaleString('en-KE')}</div>
      </div>
      <div>
        <div style="font-size:9.5px;color:var(--mute);margin-bottom:3px">Converted to invoice</div>
        <div style="font-size:26px;font-weight:800;color:var(--good);line-height:1">${invoiced.length}</div>
        <div style="font-size:11.5px;color:var(--good);margin-top:4px">KES ${Math.round(invVal).toLocaleString('en-KE')}</div>
      </div>
    </div>
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:5px">
      <span style="font-size:10.5px;color:var(--mute)">Conversion rate</span>
      <span style="font-size:13px;font-weight:800;color:${barColor}">${pct}%</span>
    </div>
    <div style="height:7px;background:var(--line);border-radius:4px;overflow:hidden;margin-bottom:${appVal>0||declined.length?'12':'0'}px">
      <div style="height:100%;width:${pct}%;background:${barColor};border-radius:4px"></div>
    </div>
    ${appVal>0?`<div style="padding:8px 12px;background:#F0FDF4;border:1px solid #BBF7D0;border-radius:8px;font-size:11px;font-weight:600;color:#16A34A;margin-bottom:6px">Pipeline (approved, not yet invoiced) · KES ${Math.round(appVal).toLocaleString('en-KE')}</div>`:''}
    ${pending.length||declined.length?`<div style="font-size:10.5px;color:var(--mute)">${pending.length?`${pending.length} pending/sent`:''} ${pending.length&&declined.length?'·':''} ${declined.length?`${declined.length} declined`:''}</div>`:''}
  </div>`;
}

function loadDashTasks(){
  fetch('api/tasks.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'list'})})
  .then(r=>r.json()).then(j=>{ if(j.ok){ TASK.tasks=j.tasks||[]; if(TAB==='dash') render(); } }).catch(()=>{});
}
function loadDashQuotes(){
  fetch('api/quotes.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'list'})})
  .then(r=>r.json()).then(j=>{ if(j.ok){ MQ.quotes=mqNormalize(j.quotes||[]); MQ.loaded=true; if(TAB==='dash') render(); } }).catch(()=>{});
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
  const list = assigned.length? `<div class="dashtkgrid">${assigned.map(t=>{
    const av=(t.assignees||[]).map(a=>dshAvatar(a.name||a.email,20)).join('');
    return `<div class="dtk">
      <div class="dtk-av">${av}</div>
      <div class="dtk-title">${esc(t.title)}</div>
      <div class="dtk-who">${(t.assignees||[]).map(a=>esc(a.name||a.email)).join(', ')}</div>
    </div>`; }).join('')}</div>` : `<div style="color:rgba(255,255,255,.5);font-size:11.5px;padding:4px 0">No tasks assigned to anyone yet${unassigned?` — ${unassigned} unassigned.`:''}.</div>`;
  return `<div class="teambox">
    <div class="teambox-head">
      <div class="title">Team &amp; Tasks</div>
      <button class="tbtn" onclick="gotoTodo()">Open To-Do →</button>
    </div>
    ${(people.length||unassigned)?`<div class="wbar">${chips}</div>`:''}
    ${list}
  </div>`;
}
function vDashLite(){
  const showQuotes = tabAllowed('myquotes') || tabAllowed('newquote');
  const showTasks = tabAllowed('todo');
  const showAudrey = tabAllowed('audrey');
  const showBoard = tabAllowed('taskboard');
  const mine=MQ.quotes||[];
  const pend=mine.filter(q=>q.status==='pending_approval').length;
  const appr=mine.filter(q=>q.status==='approved'||q.status==='accepted').length;
  const decl=mine.filter(q=>q.status==='declined'||q.status==='rejected').length;
  const myTasks=(TASK.tasks||[]).filter(t=>t.status!=='done');
  let html = `
  <div class="dsh-hero">
    <div class="ey">Welcome</div>
    <div style="font-size:22px;font-weight:800;letter-spacing:-.3px;margin-top:6px">${String(ME.user||'').replace(/</g,'&lt;')}</div>
    <div class="sub">
      <div><div class="l">My quotes</div><div class="v">${mine.length}</div></div>
      <div><div class="l">Open tasks</div><div class="v">${myTasks.length}</div></div>
    </div>
  </div>`;
  if(showQuotes){
    html += `<div class="kpis">
      <div class="kpi" style="--accent:var(--orange)"><div class="l">Awaiting approval</div><div class="n">${pend}</div><div class="h">Pending in Zoho</div></div>
      <div class="kpi" style="--accent:var(--good)"><div class="l">Approved</div><div class="n">${appr}</div><div class="h">Good to go</div></div>
      <div class="kpi" style="--accent:${decl?'var(--bad)':'var(--mute)'}"><div class="l">Declined</div><div class="n" style="${decl?'color:var(--bad)':''}">${decl}</div><div class="h">${decl?'Needs a rethink':'None'}</div></div>
      <div class="kpi" style="--accent:var(--blue)"><div class="l">Open tasks</div><div class="n">${myTasks.length}</div><div class="h">Assigned to you</div></div>
    </div>
    ${vDashMyQuotes()}
    <div class="sect"><b>My quotes</b><span class="ln"></span>
      <button class="btn" style="width:auto;padding:5px 12px;font-size:11px" onclick="navTo('newquote')">+ New quote</button></div>
    ${mine.length? mine.slice(0,5).map(q=>`<div class="invrow" onclick="navTo('myquotes')">
        <div><div style="font-size:12.5px;font-weight:600">${String(q.customer_name||'(no customer)').replace(/</g,'&lt;')}</div>
          <div class="muted">${q.zoho_estimate_number?('Zoho '+q.zoho_estimate_number+' · '):''}${(q.currency||'KES')} ${fmtn(q.total)}</div></div>
        ${quoteBadge(q.status)}</div>`).join('')
      : `<div class="card muted" style="text-align:center;padding:18px">No quotes yet. <span style="color:var(--orange);cursor:pointer" onclick="navTo('newquote')">Create your first →</span></div>`}`;
  }
  if(showTasks){
    html += `<div class="sect"><b>My tasks</b><span class="ln"></span>${myTasks.length?`<span class="pill" style="background:#EEF2FE;color:var(--blue)">${myTasks.length} open</span>`:''}</div>
    ${myTasks.length? myTasks.slice(0,6).map(t=>`<div class="invrow" onclick="navTo('todo')">
        <div><div style="font-size:12.5px;font-weight:600">${String(t.title||'').replace(/</g,'&lt;')}</div>
          ${t.subject?`<div class="muted">${String(t.subject).replace(/</g,'&lt;')}</div>`:''}</div>
        <span class="muted" style="font-size:11px">open ›</span></div>`).join('')
      : `<div class="card muted" style="text-align:center;padding:16px">No open tasks assigned to you. 🎉</div>`}`;
  }
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
  if(!showQuotes && !showTasks && !showAudrey && !showBoard){
    html += `<div class="card muted" style="text-align:center;padding:22px">Open one of your tabs from the menu above to get started.</div>`;
  }
  return html;
}

function vDash(){
  if(!ME.admin) return vDashLite();
  const s = summary(), r = rows(), overdue = r.filter(x=>x.overdue);
  const openTasks=(TASK.tasks||[]).filter(t=>t.status!=='done').length;
  const d=DPAID.data;

  const paidPanel=(()=>{
    if(!d) return `<div class="card" style="padding:16px;display:flex;align-items:center;justify-content:center;min-height:90px"><span class="muted" style="font-size:11.5px">${DPAID.loading?'Loading payments…':'—'}</span></div>`;
    const wht=Math.max(0,Math.round(d.gross-d.net));
    const mo=new Date(d.from+'T00:00:00').toLocaleString('en-KE',{month:'long',year:'numeric'});
    const payRows=(d.rows||[]).map(row=>{
      const invNums=(row.invoices||[]).join(', ');
      const sub=[row.number,invNums,row.ref].filter(Boolean).join(' · ');
      return `<div class="dsh-pay-row" data-client="${((row.customer||'')+' '+invNums).toLowerCase().replace(/"/g,'')}" style="display:flex;align-items:center;gap:12px;padding:8px 0;border-bottom:1px solid var(--line)">
        <div style="flex:1;min-width:0">
          <div style="font-size:11.5px;font-weight:600;color:var(--ink);overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${row.customer}</div>
          ${sub?`<div style="font-size:9.5px;color:var(--mute);margin-top:2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${sub}</div>`:''}
        </div>
        <div style="flex-shrink:0;text-align:right">
          <div style="font-size:12px;font-weight:700;color:var(--good);white-space:nowrap">KES ${Math.round(row.amount).toLocaleString('en-KE')}</div>
          <div style="font-size:9.5px;color:var(--mute);margin-top:2px;white-space:nowrap">${row.date}</div>
        </div>
      </div>`;
    }).join('');
    const dueList=(d.unpaid&&d.unpaid.list)||[];
    const dueRows=dueList.map(iv=>{
      const od=iv.overdue>0;
      const sub=iv.number+(od?` · <span style="color:var(--bad);font-weight:600">${iv.overdue}d overdue</span>`:(iv.due?` · due ${iv.due}`:''));
      return `<div class="dsh-pay-row" data-client="${((iv.customer||'')+' '+(iv.number||'')).toLowerCase().replace(/"/g,'')}" style="display:flex;align-items:center;gap:12px;padding:8px 0;border-bottom:1px solid var(--line)">
        <div style="flex:1;min-width:0">
          <div style="font-size:11.5px;font-weight:600;color:var(--ink);overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${iv.customer||'—'}</div>
          <div style="font-size:9.5px;color:var(--mute);margin-top:2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${sub}</div>
        </div>
        <div style="flex-shrink:0;text-align:right">
          <div style="font-size:12px;font-weight:700;color:${od?'var(--bad)':'var(--ink)'};white-space:nowrap">${iv.currency} ${Math.round(iv.balance).toLocaleString('en-KE')}</div>
        </div>
      </div>`;
    }).join('');
    const dueCount=(d.unpaid&&d.unpaid.count)||0;
    // default to Outstanding when there are no payments this month yet
    const view = DPAID.view || ((d.count===0 && dueCount>0) ? 'due' : 'pay');
    const activeRows = view==='due' ? dueRows : payRows;
    const emptyMsg = view==='due' ? 'No outstanding invoices. 🎉' : 'No payments received yet this month.';
    return `<div class="card" style="padding:0;overflow:hidden">
      <div style="background:var(--grad-orange);padding:12px 14px 11px">
        <div style="font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.7px;color:rgba(255,255,255,.72);margin-bottom:9px">Payments · ${mo} · ${d.count}</div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px 10px">
          <div><div style="font-size:9px;color:rgba(255,255,255,.6)">Gross billed</div><div style="font-size:13px;font-weight:800;color:#15202B;line-height:1.2">KES ${Math.round(d.gross).toLocaleString('en-KE')}</div></div>
          <div><div style="font-size:9px;color:rgba(255,255,255,.6)">Net received</div><div style="font-size:13px;font-weight:800;color:#15202B;line-height:1.2">KES ${Math.round(d.net).toLocaleString('en-KE')}</div>${wht>0?`<div style="font-size:9px;color:rgba(255,255,255,.55);margin-top:2px">WHT −KES ${wht.toLocaleString('en-KE')}</div>`:''}</div>
          <div><div style="font-size:9px;color:rgba(255,255,255,.6)">Expenses</div><div style="font-size:13px;font-weight:800;color:#15202B;line-height:1.2">KES ${Math.round(d.expenses).toLocaleString('en-KE')}</div></div>
          <div style="border-left:2px solid rgba(255,255,255,.25);padding-left:10px"><div style="font-size:9px;color:rgba(255,255,255,.6)">Profit</div><div style="font-size:13px;font-weight:800;color:#15202B;line-height:1.2">${d.profit<0?'−':''}KES ${Math.abs(Math.round(d.profit)).toLocaleString('en-KE')}</div></div>
        </div>
      </div>
      ${d.unpaid?`<div onclick="dashPayView('due')" title="View outstanding invoices" style="cursor:pointer;background:#15202B;padding:9px 14px;display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap">
        <div style="font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:#94A3B8">Outstanding · ${d.unpaid.count} unpaid<div style="font-size:8.5px;font-weight:500;text-transform:none;letter-spacing:0;color:#5B6B7D;margin-top:1px">@ 1 USD = KES ${(+d.unpaid.rate).toLocaleString('en-KE',{maximumFractionDigits:2})}${d.unpaid.rateSrc==='fallback'?' (set)':''}</div></div>
        <div style="text-align:right;display:flex;gap:14px;align-items:baseline">
          <div><span style="font-size:13.5px;font-weight:800;color:#fff">KES ${Math.round(d.unpaid.totalKES).toLocaleString('en-KE')}</span></div>
          <div><span style="font-size:13.5px;font-weight:800;color:#5BD68A">USD ${Math.round(d.unpaid.totalUSD).toLocaleString('en-US')}</span></div>
        </div>
      </div>`:''}
      <div style="display:flex;border-bottom:1px solid var(--line)">
        <button onclick="dashPayView('pay')" style="flex:1;background:none;border:none;cursor:pointer;font-family:inherit;font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;padding:8px 6px;color:${view==='pay'?'var(--orange)':'var(--mute)'};border-bottom:2px solid ${view==='pay'?'var(--orange)':'transparent'}">Received (${d.count})</button>
        <button onclick="dashPayView('due')" style="flex:1;background:none;border:none;cursor:pointer;font-family:inherit;font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;padding:8px 6px;color:${view==='due'?'var(--orange)':'var(--mute)'};border-bottom:2px solid ${view==='due'?'var(--orange)':'transparent'}">Outstanding (${dueCount})</button>
      </div>
      <div style="padding:6px 10px 4px 14px;border-bottom:1px solid var(--line)">
        <input id="dshPayQ" type="text" placeholder="${view==='due'?'Search outstanding invoice or client…':'Search payment…'}" autocomplete="off"
          style="width:100%;box-sizing:border-box;border:none;outline:none;font-size:11.5px;padding:5px 0;background:transparent;font-family:inherit;color:var(--ink)"
          oninput="(function(v){const q=v.toLowerCase();document.querySelectorAll('.dsh-pay-row').forEach(r=>{r.style.display=r.dataset.client.includes(q)?'flex':'none'});})(this.value)">
      </div>
      <div style="max-height:260px;overflow-y:auto;padding:0 14px 10px">${activeRows||`<div class="muted" style="text-align:center;padding:20px 10px;font-size:11.5px">${emptyMsg}</div>`}</div>
    </div>`;
  })();

  return `
  <div class="dsh-hero" style="padding:18px 22px;margin-bottom:10px">
    <div class="ey">Net profit \xb7 excl. VAT</div>
    <div class="big" style="font-size:30px;margin-top:4px">${fmt(s.netProfit)}</div>
    <div class="sub" style="margin-top:10px;gap:16px">
      <div><div class="l">VAT collected</div><div class="v" style="color:#AEB9C7;font-size:13px">${fmt(s.totalVat)}</div></div>
      <div><div class="l">Loaned out</div><div class="v" style="font-size:13px">${fmt(s.loanOut)}</div></div>
      <div><div class="l">Restored</div><div class="v" style="font-size:13px">${s.restored.length}</div></div>
      <div><div class="l">Open bridges</div><div class="v" style="font-size:13px">${s.open.length}</div></div>
    </div>
  </div>

  <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:6px;margin-bottom:10px">
    <div class="kpi" style="--accent:var(--orange)"><div class="l">Open bridges</div><div class="n" style="font-size:24px">${s.open.length}</div><div class="h" title="Capital deployed">Capital deployed</div></div>
    <div class="kpi" style="--accent:${overdue.length?'var(--bad)':'var(--good)'}"><div class="l">Overdue</div><div class="n" style="font-size:24px;color:${overdue.length?'var(--bad)':'var(--ink)'}">${overdue.length}</div><div class="h" title="${overdue.length?'Needs chasing':'All on track'}">${overdue.length?'Needs chasing':'All on track'}</div></div>
    <div class="kpi" style="--accent:var(--blue)"><div class="l">Open tasks</div><div class="n" style="font-size:24px">${openTasks}</div><div class="h" title="On the to-do list">On the to-do</div></div>
    <div class="kpi" style="--accent:var(--good)"><div class="l">Restored</div><div class="n" style="font-size:24px">${s.restored.length}</div><div class="h" title="Bridges repaid">Bridges repaid</div></div>
  </div>

  <div class="dsh-side" style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px;align-items:start">
    ${paidPanel}
    <div style="display:flex;flex-direction:column;gap:10px">${vDashTeamQuotes()}${vDashQuickExpense()}</div>
  </div>

  ${overdue.length?`<div style="margin-bottom:10px">
    <div class="sect" style="margin-bottom:8px"><b>Chase list</b><span class="ln"></span><span class="pill" style="background:#FDECEA;color:var(--bad)">${overdue.length} overdue</span></div>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:8px">
      ${overdue.map(x=>`<div class="card" style="border-left:4px solid var(--bad);padding:10px 13px;margin-bottom:0">
        <div style="font-size:11.5px;font-weight:700;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${x.client||x.purpose||''}</div>
        <div style="font-size:10.5px;color:var(--mute);margin-top:2px">${x.invoice_number||''} \xb7 due ${x.expected_date||'—'}</div>
        <div style="font-size:14px;font-weight:800;color:var(--bad);margin-top:4px">${fmt(x.amount)} <span style="font-size:10px;font-weight:600;color:var(--mute)">${x.dd}d late</span></div>
      </div>`).join('')}
    </div>
  </div>`:''}

  <div class="sect"><b>Working capital</b><span class="ln"></span>
    <button class="btn sec" style="width:auto;padding:5px 11px;font-size:11px" onclick="gotoLoans()">Manage loans →</button></div>
  <div class="wcgrid" style="margin-bottom:10px">
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
          <div style="flex:1;min-width:0"><div class="t">Audrey Report</div><div class="s">Unpaid-invoice tracker \xb7 public</div></div>
          <button class="btn sec" style="width:auto;padding:5px 9px;font-size:10.5px" onclick="event.stopPropagation();copyAudrey(this)">Copy</button>
        </div>
        <div class="linktile" onclick="window.open(TASKBOARD_URL,'_blank')">
          <div class="ic" style="background:#EEF2FE;color:var(--blue)">✅</div>
          <div style="flex:1;min-width:0"><div class="t">Task Board</div><div class="s">Who's doing what \xb7 public</div></div>
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
                :BK.pick.err?`<div class="warn" style="font-size:11px">${BK.pick.err}</div>`
                :`${(BK.pick.current?BK.pick.folders:BK.pick.roots).length?(BK.pick.current?BK.pick.folders:BK.pick.roots).map(f=>`
                    <div class="invrow" onclick="bkOpenFolder('${f.id}')"><div style="font-size:12px">📁 ${(f.name||'(folder)').replace(/</g,'&lt;')}</div><div class="muted" style="font-size:11px">open ›</div></div>`).join('')
                  :`<div class="muted" style="font-size:11px;text-align:center;padding:8px">No sub-folders here.</div>`}`}
              ${BK.pick.current?`<button class="btn" style="width:100%;margin-top:8px;font-size:12px" onclick="bkUseFolder()">✓ Use "${(BK.pick.current.name||'this folder').replace(/"/g,'')}"</button>`:''}</div>`:''}
            <div class="row" style="gap:8px;margin-top:8px">
              <button class="btn" style="flex:1" onclick="runBackup()" ${BK.running?'disabled':''}>${BK.running?'Backing up…':'⬆ Back up now'}</button>
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
            <div style="font-size:11.5px;margin-bottom:8px">🕐 Last full cache from Zoho: <b>${cacheWhen()}</b></div>
            <button class="btn" onclick="preloadCache()" ${PRELOAD.running?'disabled':''}>${PRELOAD.running?'Pulling…':'⟳ Pull all data into cache'}</button>
            ${PRELOAD.log.length?`<div style="margin-top:8px;font-size:11px;line-height:1.6">${PRELOAD.log.map(l=>`<div>${l}</div>`).join('')}</div>`:''}
          </div>
        </details>
      </div>
    </div>
  </div>`;
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
    ${N.newAdmin?'':`<div class="row" style="margin:4px 0"><label style="margin:0">Allowed tabs <span class="muted" style="font-weight:400">(tap to toggle)</span></label>
      <button class="btn sec" style="width:auto;padding:4px 10px;font-size:11px" onclick="uTechPreset('new')">⚡ Technician preset</button></div>
    <div style="display:flex;flex-wrap:wrap;gap:6px;margin:4px 0 6px">${uTabsBoxes(N.newTabs,'uNewToggle')}</div>`}
    <button class="btn" style="margin-top:8px" onclick="userCreate()">Create user</button>
    ${N.msg?`<div class="${N.err?'warn':'ok'}" style="margin-top:8px;font-size:12px">${N.msg}</div>`:''}
  </div>`;

  const rows = (N.list||[]).map(u=>{
    const tabs = (u.tabs||'').split(',').filter(Boolean);
    const chips = u.is_admin? '<span class="pill" style="background:var(--surface-2);color:var(--blue);border:1px solid var(--line)">Full access</span>'
      : (tabs.length? tabs.map(t=>`<span class="pill" style="background:var(--surface-2);color:var(--ink);border:1px solid var(--line)">${ALLTABS[t]||t}</span>`).join(' ') : '<span class="muted" style="font-size:11px">No tabs</span>');
    const editing = N.editId===u.id;
    return `<div class="card" style="${u.disabled?'opacity:.62':''}">
      <div class="row"><b style="font-size:14px">${esc(u.username)}${u.is_admin?' 👑':''} ${u.disabled?'<span class="pill" style="background:var(--surface-2);color:var(--bad);border:1px solid var(--line)">Disabled</span>':''}</b>
        <span class="muted" style="font-size:11px">#${u.id}</span></div>
      <div class="muted" style="font-size:11px;margin-top:2px">${u.email?('✉ '+esc(u.email)):'<span style="color:var(--bad)">no email — cannot see their tasks</span>'}${u.calendar?' · <span style="color:var(--good)">📅 calendar connected</span>':''}</div>
      <div style="margin-top:6px;display:flex;flex-wrap:wrap;gap:5px">${chips}</div>
      ${!u.is_admin&&editing?`<div style="margin-top:10px"><div class="row"><label style="margin:0">Allowed tabs</label>
        <button class="btn sec" style="width:auto;padding:4px 10px;font-size:11px" onclick="uTechPreset('edit')">⚡ Technician preset</button></div>
        <div style="display:flex;flex-wrap:wrap;gap:6px;margin:4px 0 8px">${uTabsBoxes(N.editTabs,'uEditToggle')}</div>
        <button class="btn" style="width:auto;padding:7px 13px;font-size:12px" onclick="userSaveTabs(${u.id})">Save tabs</button>
        <button class="btn sec" style="width:auto;padding:7px 13px;font-size:12px" onclick="USERS.editId=0;render()">Cancel</button></div>`
      : `<div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:10px">
          ${u.is_admin?'':`<button class="btn sec" style="width:auto;padding:5px 11px;font-size:11px" onclick="userEdit(${u.id})">Edit tabs</button>`}
          <button class="btn sec" style="width:auto;padding:5px 11px;font-size:11px" onclick="userSetEmail(${u.id},'${esc(u.email||'').replace(/'/g,'')}')">Set email</button>
          <button class="btn sec" style="width:auto;padding:5px 11px;font-size:11px" onclick="userPasswd(${u.id},'${esc(u.username).replace(/'/g,'')}')">Reset password</button>
          <button class="btn sec" style="width:auto;padding:5px 11px;font-size:11px;${u.disabled?'color:var(--good)':'color:var(--bad)'}" onclick="userToggleActive(${u.id},${u.disabled?0:1},'${esc(u.username).replace(/'/g,'')}')">${u.disabled?'Enable':'Disable'}</button>
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
const TECH_TABS=['dash','newquote','myquotes','todo'];
function uTechPreset(which){ if(which==='edit') USERS.editTabs=TECH_TABS.slice(); else USERS.newTabs=TECH_TABS.slice(); render(); }
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
async function userToggleActive(id,disabled,name){
  if(disabled && !confirm('Disable '+name+'? They will be signed out and unable to log in, but their quotes and tasks are kept.')) return;
  const j = await api('api/users.php?action=set_disabled',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({id,disabled})});
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
    if(TAB==='report'||TAB==='dash'||TAB==='bulkexp') render();
  }).catch(e=>{ EXP.loadingAcc=false; EXP.msg='Accounts: '+e; EXP.err=true; if(TAB==='report'||TAB==='dash'||TAB==='bulkexp') render(); });
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
function rptSearch(v){ window._rptSearch=v; REPORT.dp=1; const el=document.getElementById('rptBody'); if(el) el.innerHTML=rptBodyHtml(); }
function rptRepaint(){ const el=document.getElementById('rptBody'); if(el) el.innerHTML=rptBodyHtml(); }
function rptGoPage(n){ REPORT.dp=n; rptRepaint(); }
function rptPerPage(v){ REPORT.perPage=parseInt(v,10)||25; REPORT.dp=1; rptRepaint(); }
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
  const _rst={perPage:REPORT.perPage||25, page:REPORT.dp||1}; const _rp=pgSlice(list,_rst); REPORT.dp=_rst.page;   // paginate rows; totals stay over the full set
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
    <div class="row" style="margin:0 2px 6px;align-items:center;gap:8px;flex-wrap:wrap">
      <span class="muted" style="flex:1;min-width:140px">${list.length} invoice${list.length===1?'':'s'} · ${CFG.cur}${_rp.pages>1?(' · page '+REPORT.dp+' of '+_rp.pages):''}</span>
      ${pgSel(REPORT.perPage||25,'rptPerPage(this.value)')}
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
          ${_rp.slice.map(x=>{const usd=x.currency==='USD';const g='#1a7f37';return `<tr${usd?' style="background:#eafbf0"':''}>
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
    </div>
    ${pgBar({page:REPORT.dp},_rp.pages,'rptGoPage')}`
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
      <b style="font-size:13px;color:var(--bad)">${esc(g.number)} — ${g.count} files</b>${files}</div>`;
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

/* ================= Quote Browser (qlist) ================= */
let QLIST = {loaded:false,loading:false,allItems:[],hasMore:false,page:1,status:'',preset:'month',from:'',to:'',search:'',error:''};

function qlistDates(){
  const now=new Date(),y=now.getFullYear(),m=now.getMonth();
  const fmt=d=>d.toISOString().slice(0,10);
  if(QLIST.preset==='month')     return {from:fmt(new Date(y,m,1)),     to:fmt(new Date(y,m+1,0))};
  if(QLIST.preset==='lastmonth') return {from:fmt(new Date(y,m-1,1)),   to:fmt(new Date(y,m,0))};
  if(QLIST.preset==='quarter')   {const q=Math.floor(m/3);return{from:fmt(new Date(y,q*3,1)),to:fmt(new Date(y,q*3+3,0))};}
  if(QLIST.preset==='year')      return {from:`${y}-01-01`,             to:`${y}-12-31`};
  if(QLIST.preset==='custom')    return {from:QLIST.from,               to:QLIST.to};
  return {from:'',to:''};
}
var QLIMPORTED={};   // estimate_id -> already imported into the app (this session)
function qlImport(eid){
  fetch('api/quote_import.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'import',estimate_id:eid})})
  .then(r=>r.json()).then(j=>{ if(j.ok){ QLIMPORTED[eid]=true; MQ.loaded=false; mqLoad();
      const w=j.warnings||[]; if(w.length) alert('Imported with a note:\n\n'+w.join('\n'));
      if(TAB==='qlist') render();
    } else alert(j.error||'Import failed'); }).catch(e=>alert(''+e));
}
function qlistGoPage(n){ QLIST.dp=n; render(); }
function qlistPerPage(v){ QLIST.perPage=parseInt(v,10)||25; QLIST.dp=1; render(); }
function qlistLoad(more=false){
  if(QLIST.loading) return;
  if(!more){QLIST.page=1;QLIST.dp=1;QLIST.allItems=[];QLIST.hasMore=false;QLIST.error='';}
  QLIST.loading=true; render();
  const {from,to}=qlistDates();
  const p=new URLSearchParams({status:QLIST.status,from,to,page:QLIST.page,sort:'date',order:'D'});
  fetch('api/zoho_quotes.php?'+p,{credentials:'same-origin'})
    .then(r=>r.json()).then(j=>{
      QLIST.loading=false;QLIST.loaded=true;
      if(j.ok){QLIST.allItems=[...QLIST.allItems,...(j.items||[])];QLIST.hasMore=j.hasMore;QLIST.page++;}
      else{QLIST.error=j.error||'Error loading quotes';}
      render();
    }).catch(e=>{QLIST.loading=false;QLIST.loaded=true;QLIST.error='Request failed: '+e;render();});
}
function vQList(){
  const SC={draft:'#94A3B8',sent:'#3B82F6',accepted:'#10B981',declined:'#EF4444',invoiced:'#8B5CF6',expired:'#F59E0B'};
  const statusOpts=['','Draft','Sent','Accepted','Declined','Invoiced','Expired'];
  const statusBar=statusOpts.map(s=>{
    const on=QLIST.status===(s.toLowerCase()||'')&&!(s==='')&&QLIST.status!==''||(!s&&!QLIST.status);
    return `<button class="btn${on?'':' sec'}" style="padding:4px 10px;font-size:11px;width:auto"
      onclick="QLIST.status='${s.toLowerCase()}';QLIST.page=1;QLIST.allItems=[];qlistLoad()">${s||'All'}</button>`;
  }).join('');
  const presets=[['month','This Month'],['lastmonth','Last Month'],['quarter','Quarter'],['year','This Year'],['all','All Time'],['custom','Custom']];
  const presetBar=presets.map(([k,l])=>`<button class="btn${QLIST.preset===k?'':' sec'}" style="padding:4px 9px;font-size:11px;width:auto"
    onclick="QLIST.preset='${k}';QLIST.page=1;QLIST.allItems=[];${k!=='custom'?'qlistLoad()':'render()'}">${l}</button>`).join('');
  const customInputs=QLIST.preset==='custom'?`<div style="display:flex;gap:8px;align-items:center;margin-top:8px;flex-wrap:wrap">
    <label style="font-size:11px;color:var(--mute)">From</label>
    <input type="date" id="qlFrom" value="${QLIST.from}" style="font-size:11px;padding:4px 6px" onchange="QLIST.from=this.value">
    <label style="font-size:11px;color:var(--mute)">To</label>
    <input type="date" id="qlTo" value="${QLIST.to}" style="font-size:11px;padding:4px 6px" onchange="QLIST.to=this.value">
    <button class="btn" style="padding:4px 12px;font-size:11px;width:auto" onclick="QLIST.page=1;QLIST.allItems=[];qlistLoad()">Apply</button>
  </div>`:'';
  const q=QLIST.search.toLowerCase();
  const items=QLIST.allItems.filter(x=>!q||x.customer.toLowerCase().includes(q)||x.number.toLowerCase().includes(q));
  const total=items.reduce((s,x)=>s+(+x.total||0),0);
  const _st={perPage:QLIST.perPage||25, page:QLIST.dp||1}; const _qp=pgSlice(items,_st); QLIST.dp=_st.page;
  const badge=s=>`<span style="display:inline-block;font-size:9.5px;font-weight:700;padding:2px 7px;border-radius:9px;color:#fff;background:${SC[s]||'#94A3B8'};text-transform:capitalize">${s||'—'}</span>`;
  const rows=_qp.slice.map(x=>`<tr>
    <td style="padding:7px 10px;font-size:11.5px;font-weight:600;white-space:nowrap;color:var(--orange)">${x.number}</td>
    <td style="padding:7px 10px;font-size:11.5px">${x.customer}</td>
    <td style="padding:7px 10px;font-size:11px;color:var(--mute);white-space:nowrap">${x.date}</td>
    <td style="padding:7px 10px;font-size:11px;color:var(--mute);white-space:nowrap">${x.expiry||'—'}</td>
    <td style="padding:7px 10px">${badge(x.status)}</td>
    <td style="padding:7px 10px;font-size:11.5px;font-weight:700;text-align:right;white-space:nowrap">KES ${Math.round(x.total).toLocaleString('en-KE')}</td>
    ${x.ref?`<td style="padding:7px 10px;font-size:10.5px;color:var(--mute)">${x.ref}</td>`:'<td></td>'}
    ${ME.admin?`<td style="padding:7px 10px;white-space:nowrap">${QLIMPORTED[x.id]?'<span class="pill" style="background:#E7F6EC;color:#0F7A34;padding:3px 8px;font-size:10.5px">✓ In app</span>':(x.status==='invoiced'?'':`<button class="btn sec" style="width:auto;padding:4px 10px;font-size:11px" onclick="qlImport('${x.id}')">Import</button>`)}</td>`:''}
  </tr>`).join('');
  return `
  <h2>Quotes</h2>
  <div class="card" style="margin-bottom:12px">
    <div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:10px">${statusBar}</div>
    <div style="display:flex;flex-wrap:wrap;gap:6px">${presetBar}</div>
    ${customInputs}
    <div style="display:flex;gap:8px;margin-top:10px;align-items:center">
      <input id="qlSearch" type="text" placeholder="Search client or quote #…" value="${QLIST.search}"
        style="flex:1;font-size:12px;padding:6px 10px"
        oninput="QLIST.search=this.value;QLIST.dp=1;render()">
      ${pgSel(QLIST.perPage||25,'qlistPerPage(this.value)')}
      <button class="btn sec" style="width:auto;padding:6px 14px;font-size:11.5px;flex-shrink:0" onclick="QLIST.page=1;QLIST.allItems=[];qlistLoad()">↺ Refresh</button>
    </div>
  </div>
  ${QLIST.error?`<div class="warn">${QLIST.error}</div>`:''}
  ${!QLIST.loaded&&QLIST.loading?`<div class="card muted" style="text-align:center;padding:20px">Loading quotes from Zoho…</div>`:''}
  ${QLIST.loaded||QLIST.allItems.length?`
  <div class="card" style="padding:0;overflow:hidden">
    <div style="padding:10px 14px;border-bottom:1px solid var(--line);display:flex;align-items:center;justify-content:space-between">
      <span style="font-size:12px;font-weight:600">${items.length} quote${items.length!==1?'s':''}${_qp.pages>1?(' · page '+QLIST.dp+' of '+_qp.pages):''}</span>
      <span style="font-size:12px;font-weight:700;color:var(--orange)">KES ${Math.round(total).toLocaleString('en-KE')}</span>
    </div>
    <div style="overflow-x:auto">
    <table style="width:100%;border-collapse:collapse">
      <thead><tr style="background:var(--bg-card)">
        <th style="padding:7px 10px;font-size:10px;text-align:left;color:var(--mute);font-weight:600;white-space:nowrap">Quote #</th>
        <th style="padding:7px 10px;font-size:10px;text-align:left;color:var(--mute);font-weight:600">Client</th>
        <th style="padding:7px 10px;font-size:10px;text-align:left;color:var(--mute);font-weight:600">Date</th>
        <th style="padding:7px 10px;font-size:10px;text-align:left;color:var(--mute);font-weight:600">Expiry</th>
        <th style="padding:7px 10px;font-size:10px;text-align:left;color:var(--mute);font-weight:600">Status</th>
        <th style="padding:7px 10px;font-size:10px;text-align:right;color:var(--mute);font-weight:600">Amount</th>
        <th style="padding:7px 10px;font-size:10px;text-align:left;color:var(--mute);font-weight:600">Ref</th>
        ${ME.admin?'<th style="padding:7px 10px;font-size:10px;text-align:left;color:var(--mute);font-weight:600">Import</th>':''}
      </tr></thead>
      <tbody style="divide-y">${rows||`<tr><td colspan="${ME.admin?8:7}" style="text-align:center;padding:20px;color:var(--mute);font-size:12px">No quotes found</td></tr>`}</tbody>
    </table>
    </div>
    ${_qp.pages>1?`<div style="padding:6px 14px 12px">${pgBar({page:QLIST.dp},_qp.pages,'qlistGoPage')}</div>`:''}
    ${QLIST.hasMore?`<div style="padding:10px 14px;border-top:1px solid var(--line);text-align:center">
      <button class="btn sec" style="width:auto;padding:6px 20px;font-size:12px" onclick="qlistLoad(true)">${QLIST.loading?'Loading…':'Load next 200 →'}</button>
    </div>`:''}
  </div>`:''}`;
}
/* ================= end Quote Browser ================= */

/* ================= Invoice Browser (ivlist) ================= */
let IVLIST = {loaded:false,loading:false,allItems:[],hasMore:false,page:1,status:'',preset:'month',from:'',to:'',search:'',error:''};

function ivlistDates(){
  const now=new Date(),y=now.getFullYear(),m=now.getMonth();
  const fmt=d=>d.toISOString().slice(0,10);
  if(IVLIST.preset==='month')     return {from:fmt(new Date(y,m,1)),     to:fmt(new Date(y,m+1,0))};
  if(IVLIST.preset==='lastmonth') return {from:fmt(new Date(y,m-1,1)),   to:fmt(new Date(y,m,0))};
  if(IVLIST.preset==='quarter')   {const q=Math.floor(m/3);return{from:fmt(new Date(y,q*3,1)),to:fmt(new Date(y,q*3+3,0))};}
  if(IVLIST.preset==='year')      return {from:`${y}-01-01`,             to:`${y}-12-31`};
  if(IVLIST.preset==='custom')    return {from:IVLIST.from,              to:IVLIST.to};
  return {from:'',to:''};
}
function ivlistGoPage(n){ IVLIST.dp=n; render(); }
function ivlistPerPage(v){ IVLIST.perPage=parseInt(v,10)||25; IVLIST.dp=1; render(); }
function ivlistLoad(more=false){
  if(IVLIST.loading) return;
  if(!more){IVLIST.page=1;IVLIST.dp=1;IVLIST.allItems=[];IVLIST.hasMore=false;IVLIST.error='';}
  IVLIST.loading=true; render();
  const {from,to}=ivlistDates();
  const p=new URLSearchParams({status:IVLIST.status,from,to,page:IVLIST.page,sort:'date',order:'D'});
  fetch('api/zoho_invoices.php?'+p,{credentials:'same-origin'})
    .then(r=>r.json()).then(j=>{
      IVLIST.loading=false;IVLIST.loaded=true;
      if(j.ok){IVLIST.allItems=[...IVLIST.allItems,...(j.items||[])];IVLIST.hasMore=j.hasMore;IVLIST.page++;}
      else{IVLIST.error=j.error||'Error loading invoices';}
      render();
    }).catch(e=>{IVLIST.loading=false;IVLIST.loaded=true;IVLIST.error='Request failed: '+e;render();});
}
function vIVList(){
  const SC={draft:'#94A3B8',sent:'#3B82F6',overdue:'#EF4444',paid:'#10B981',partiallypaid:'#F59E0B',void:'#94A3B8',unpaid:'#F59E0B'};
  const statusOpts=['','Overdue','Unpaid','Sent','Paid','PartiallyPaid','Draft','Void'];
  const statusLabels={'':'All','Overdue':'Overdue','Unpaid':'Unpaid','Sent':'Sent','Paid':'Paid','PartiallyPaid':'Partial','Draft':'Draft','Void':'Void'};
  const statusBar=statusOpts.map(s=>{
    const key=s.toLowerCase();
    const on=(!s&&!IVLIST.status)||(s&&IVLIST.status===key);
    return `<button class="btn${on?'':' sec'}" style="padding:4px 10px;font-size:11px;width:auto"
      onclick="IVLIST.status='${key}';IVLIST.page=1;IVLIST.allItems=[];ivlistLoad()">${statusLabels[s]}</button>`;
  }).join('');
  const presets=[['month','This Month'],['lastmonth','Last Month'],['quarter','Quarter'],['year','This Year'],['all','All Time'],['custom','Custom']];
  const presetBar=presets.map(([k,l])=>`<button class="btn${IVLIST.preset===k?'':' sec'}" style="padding:4px 9px;font-size:11px;width:auto"
    onclick="IVLIST.preset='${k}';IVLIST.page=1;IVLIST.allItems=[];${k!=='custom'?'ivlistLoad()':'render()'}">${l}</button>`).join('');
  const customInputs=IVLIST.preset==='custom'?`<div style="display:flex;gap:8px;align-items:center;margin-top:8px;flex-wrap:wrap">
    <label style="font-size:11px;color:var(--mute)">From</label>
    <input type="date" id="ivFrom" value="${IVLIST.from}" style="font-size:11px;padding:4px 6px" onchange="IVLIST.from=this.value">
    <label style="font-size:11px;color:var(--mute)">To</label>
    <input type="date" id="ivTo" value="${IVLIST.to}" style="font-size:11px;padding:4px 6px" onchange="IVLIST.to=this.value">
    <button class="btn" style="padding:4px 12px;font-size:11px;width:auto" onclick="IVLIST.page=1;IVLIST.allItems=[];ivlistLoad()">Apply</button>
  </div>`:'';
  const q=IVLIST.search.toLowerCase();
  const items=IVLIST.allItems.filter(x=>!q||x.customer.toLowerCase().includes(q)||x.number.toLowerCase().includes(q));
  const totalAmt=items.reduce((s,x)=>s+(+x.total||0),0);
  const totalBal=items.reduce((s,x)=>s+(+x.balance||0),0);
  const overdue=items.filter(x=>x.status==='overdue');
  const _st={perPage:IVLIST.perPage||25, page:IVLIST.dp||1}; const _ip=pgSlice(items,_st); IVLIST.dp=_st.page;
  const badge=s=>`<span style="display:inline-block;font-size:9.5px;font-weight:700;padding:2px 7px;border-radius:9px;color:#fff;background:${SC[s]||'#94A3B8'};text-transform:capitalize">${s==='partiallypaid'?'Partial':s||'—'}</span>`;
  const rows=_ip.slice.map(x=>`<tr>
    <td style="padding:7px 10px;font-size:11.5px;font-weight:600;white-space:nowrap;color:var(--orange)">${x.number}</td>
    <td style="padding:7px 10px;font-size:11.5px">${x.customer}</td>
    <td style="padding:7px 10px;font-size:11px;color:var(--mute);white-space:nowrap">${x.date}</td>
    <td style="padding:7px 10px;font-size:11px;color:${x.status==='overdue'?'#EF4444':'var(--mute)'};white-space:nowrap;font-weight:${x.status==='overdue'?700:400}">${x.dueDate||'—'}</td>
    <td style="padding:7px 10px">${badge(x.status)}</td>
    <td style="padding:7px 10px;font-size:11.5px;font-weight:700;text-align:right;white-space:nowrap">KES ${Math.round(x.total).toLocaleString('en-KE')}</td>
    <td style="padding:7px 10px;font-size:11.5px;font-weight:700;text-align:right;white-space:nowrap;color:${x.balance>0?'#EF4444':'var(--mute)'}">${x.balance>0?'KES '+Math.round(x.balance).toLocaleString('en-KE'):'—'}</td>
  </tr>`).join('');
  return `
  <h2>Invoices</h2>
  <div class="card" style="margin-bottom:12px">
    <div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:10px">${statusBar}</div>
    <div style="display:flex;flex-wrap:wrap;gap:6px">${presetBar}</div>
    ${customInputs}
    <div style="display:flex;gap:8px;margin-top:10px;align-items:center">
      <input id="ivSearch" type="text" placeholder="Search client or invoice #…" value="${IVLIST.search}"
        style="flex:1;font-size:12px;padding:6px 10px"
        oninput="IVLIST.search=this.value;IVLIST.dp=1;render()">
      ${pgSel(IVLIST.perPage||25,'ivlistPerPage(this.value)')}
      <button class="btn sec" style="width:auto;padding:6px 14px;font-size:11.5px;flex-shrink:0" onclick="IVLIST.page=1;IVLIST.allItems=[];ivlistLoad()">↺ Refresh</button>
    </div>
  </div>
  ${IVLIST.error?`<div class="warn">${IVLIST.error}</div>`:''}
  ${!IVLIST.loaded&&IVLIST.loading?`<div class="card muted" style="text-align:center;padding:20px">Loading invoices from Zoho…</div>`:''}
  ${IVLIST.loaded||IVLIST.allItems.length?`
  <div class="card" style="padding:0;overflow:hidden">
    <div style="padding:10px 14px;border-bottom:1px solid var(--line);display:flex;align-items:center;gap:16px;flex-wrap:wrap">
      <span style="font-size:12px;font-weight:600">${items.length} invoice${items.length!==1?'s':''}${_ip.pages>1?(' · page '+IVLIST.dp+' of '+_ip.pages):''}</span>
      <span style="font-size:12px">Billed <b>KES ${Math.round(totalAmt).toLocaleString('en-KE')}</b></span>
      ${totalBal>0?`<span style="font-size:12px">Outstanding <b style="color:#EF4444">KES ${Math.round(totalBal).toLocaleString('en-KE')}</b></span>`:''}
      ${overdue.length?`<span style="font-size:12px;color:#EF4444;font-weight:700">${overdue.length} overdue</span>`:''}
    </div>
    <div style="overflow-x:auto">
    <table style="width:100%;border-collapse:collapse">
      <thead><tr style="background:var(--bg-card)">
        <th style="padding:7px 10px;font-size:10px;text-align:left;color:var(--mute);font-weight:600;white-space:nowrap">Invoice #</th>
        <th style="padding:7px 10px;font-size:10px;text-align:left;color:var(--mute);font-weight:600">Client</th>
        <th style="padding:7px 10px;font-size:10px;text-align:left;color:var(--mute);font-weight:600">Date</th>
        <th style="padding:7px 10px;font-size:10px;text-align:left;color:var(--mute);font-weight:600">Due</th>
        <th style="padding:7px 10px;font-size:10px;text-align:left;color:var(--mute);font-weight:600">Status</th>
        <th style="padding:7px 10px;font-size:10px;text-align:right;color:var(--mute);font-weight:600">Total</th>
        <th style="padding:7px 10px;font-size:10px;text-align:right;color:var(--mute);font-weight:600">Balance</th>
      </tr></thead>
      <tbody>${rows||'<tr><td colspan="7" style="text-align:center;padding:20px;color:var(--mute);font-size:12px">No invoices found</td></tr>'}</tbody>
    </table>
    </div>
    ${_ip.pages>1?`<div style="padding:6px 14px 12px">${pgBar({page:IVLIST.dp},_ip.pages,'ivlistGoPage')}</div>`:''}
    ${IVLIST.hasMore?`<div style="padding:10px 14px;border-top:1px solid var(--line);text-align:center">
      <button class="btn sec" style="width:auto;padding:6px 20px;font-size:12px" onclick="ivlistLoad(true)">${IVLIST.loading?'Loading…':'Load next 200 →'}</button>
    </div>`:''}
  </div>`:''}`;
}
/* ================= end Invoice Browser ================= */

/* ================= Statement Builder (consolidate unpaid → draft invoice) ================= */
let SB = { loaded:false, loading:false, invoices:[], sel:{}, q:'', cur:'', billTo:'',
           to_email:'', toEdited:false, subject:'', intro:'', introEdited:false, book:[], newEmail:'',
           saving:false, msg:'', msgErr:false, result:null };

function sbLoad(){
  if(SB.loading) return;
  SB.loading=true; SB.msg=''; render();
  fetch('api/unpaid_invoices.php',{credentials:'same-origin'})
    .then(r=>r.json()).then(j=>{
      SB.loading=false; SB.loaded=true;
      if(j.ok){ SB.invoices=(j.invoices||[]).filter(iv=>(+iv.balance||0)>0); }
      else { SB.msg=j.error||'Failed to load unpaid invoices'; SB.msgErr=true; }
      render();
    }).catch(e=>{ SB.loading=false; SB.loaded=true; SB.msg='Request failed: '+e; SB.msgErr=true; render(); });
}
function sbCurrencies(){
  const seen={}; SB.invoices.forEach(iv=>{ const c=String(iv.currency||'KES').toUpperCase(); seen[c]=1; });
  return Object.keys(seen).sort();
}
function sbClients(){
  // unique bill-to options = the companies that hold the selected invoices, else all companies in the list
  const selIds=Object.keys(SB.sel).filter(k=>SB.sel[k]);
  const src = selIds.length ? SB.invoices.filter(iv=>SB.sel[iv.id]) : SB.invoices;
  const seen={}, out=[];
  src.forEach(iv=>{ if(iv.customer_id && !seen[iv.customer_id]){ seen[iv.customer_id]=1; out.push({id:iv.customer_id,name:iv.customer_name||'(no name)'}); } });
  out.sort((a,b)=>a.name.localeCompare(b.name));
  return out;
}
function sbToggle(id){ SB.sel[id]=!SB.sel[id]; sbSyncBillTo(); sbRefreshUI(); }
function sbSelAll(on){ sbFiltered().forEach(iv=>{ SB.sel[iv.id]=on; }); sbSyncBillTo(); sbRefreshUI(); }
function sbSyncBillTo(){
  // default the statement client to the first selected invoice's company if not yet chosen / no longer valid
  const clients=sbClients();
  if(!SB.billTo || !clients.some(c=>c.id===SB.billTo)){ const id = clients.length? clients[0].id : ''; if(id!==SB.billTo) sbPickBill(id, true); }
}
function sbBillName(){ return (sbClients().find(c=>c.id===SB.billTo)||{}).name||''; }
function sbDefaults(){
  const name=sbBillName();
  if(!SB.subject) SB.subject = (CFG.stmtSubject||'Pending invoices and Statement');
  if(!SB.introEdited) SB.intro = 'Dear '+(name||'Client')+',\n\nPlease find the unpaid invoices attached to this email, together with your statement of account below, for payment.';
}
function sbPickBill(id, silent){
  SB.billTo=id;
  SB.book=[]; if(!SB.toEdited) SB.to_email='';
  sbDefaults();
  if(id){
    fetch('api/email_book.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},
      body:JSON.stringify({ customer_id:id })})
      .then(r=>r.json()).then(j=>{
        SB.book=(j.ok && j.emails)?j.emails:[];
        if(!SB.toEdited && !SB.to_email && SB.book.length) SB.to_email=SB.book[0];
        sbRefreshUI();
      }).catch(()=>{});
  }
  if(!silent) sbRefreshUI();
}
function sbAddEmail(){
  const em=(SB.newEmail||'').trim()||(SB.to_email||'').trim();
  if(!em){ SB.msg='Type an email to save first.'; SB.msgErr=true; render(); return; }
  if(!SB.billTo){ SB.msg='Pick a client first.'; SB.msgErr=true; render(); return; }
  fetch('api/email_book.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},
    body:JSON.stringify({ customer_id:SB.billTo, add:em })})
    .then(r=>r.json()).then(j=>{
      if(j.ok){ SB.book=j.emails||[]; SB.newEmail=''; if(!SB.to_email){ SB.to_email=em; SB.toEdited=true; } SB.msg=''; }
      else { SB.msg=j.error||'Could not save email.'; SB.msgErr=true; }
      sbRefreshUI();
    }).catch(e=>{ SB.msg='Error: '+e; SB.msgErr=true; render(); });
}
function sbRemoveEmail(em){
  fetch('api/email_book.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},
    body:JSON.stringify({ customer_id:SB.billTo, remove:em })})
    .then(r=>r.json()).then(j=>{ if(j.ok){ SB.book=j.emails||[]; sbRefreshUI(); } }).catch(()=>{});
}
function sbUseEmail(em){ SB.to_email=em; SB.toEdited=true; sbRefreshUI(); }
function sbField(k,v){ SB[k]=v; if(k==='intro') SB.introEdited=true; if(k==='to_email') SB.toEdited=true; }
function sbSearch(v){ SB.q=v; sbRefreshUI(); }
function sbSetCur(c){ SB.cur=c; sbRefreshUI(); }
function sbRefreshUI(){
  const f=document.getElementById('sbFilterBox'); if(f) f.innerHTML=sbFilterHtml();
  const t=document.getElementById('sbTableBox'); if(t) t.innerHTML=sbTableHtml();
  const s=document.getElementById('sbActionBox'); if(s) s.innerHTML=sbActionHtml();
}
function sbFiltered(){
  const q=(SB.q||'').trim().toLowerCase();
  let list=SB.invoices.slice();
  if(SB.cur) list=list.filter(iv=>String(iv.currency||'KES').toUpperCase()===SB.cur);
  if(q) list=list.filter(iv=>((iv.number||'')+' '+(iv.customer_name||'')).toLowerCase().includes(q));
  return list.sort((a,b)=>(a.customer_name||'').localeCompare(b.customer_name||'') || strcmpDesc(a.date,b.date));
}
function strcmpDesc(a,b){ return String(b||'').localeCompare(String(a||'')); }
function sbSelected(){ return SB.invoices.filter(iv=>SB.sel[iv.id]); }

function sbFilterHtml(){
  const curs=sbCurrencies();
  if(curs.length<2) return '';
  const chip=(val,label)=>`<button class="btn${SB.cur===val?'':' sec'}" style="width:auto;flex:0 0 auto;padding:5px 12px;font-size:11px" onclick="sbSetCur('${val}')">${label}</button>`;
  return `<div style="display:flex;flex-wrap:wrap;gap:6px;margin:8px 0 4px">
    <span class="muted" style="font-size:11px;align-self:center;margin-right:2px">Currency:</span>
    ${chip('','All')}${curs.map(c=>chip(c,c)).join('')}
  </div>`;
}

function sbTableHtml(){
  const list=sbFiltered();
  if(!list.length) return `<div class="muted" style="padding:16px;text-align:center">${(SB.q||SB.cur)?`No unpaid invoice matches your filter.`:'No unpaid invoices found.'}</div>`;
  const allSel = list.length && list.every(iv=>SB.sel[iv.id]);
  const rows=list.map(iv=>{
    const on=!!SB.sel[iv.id];
    const usd=String(iv.currency||'KES').toUpperCase()!=='KES';
    return `<tr${on?' style="background:#FFF8F1"':''}>
      <td class="l" style="width:30px"><input type="checkbox" ${on?'checked':''} onclick="sbToggle('${iv.id}')"></td>
      <td class="l" style="font-weight:600">${(iv.customer_name||'(no name)')}</td>
      <td class="l" style="white-space:nowrap">${zbInvUrl(iv.id)?`<a href="${zbInvUrl(iv.id)}" target="_blank" rel="noopener" onclick="event.stopPropagation()" style="color:var(--orange);font-weight:600;text-decoration:none" title="Open ${iv.number||''} in Zoho Books">${iv.number||''} ↗</a>`:`<span style="color:var(--orange);font-weight:600">${iv.number||''}</span>`}</td>
      <td style="color:var(--mute);white-space:nowrap">${iv.date||''}${(()=>{const n=sbDaysOverdue(iv.due_date);return n>0?`<div style="color:var(--bad);font-size:9.5px;font-weight:600">${n}d overdue</div>`:'';})()}</td>
      <td>${usd?`<span class="pill" style="background:#EEF2FE;color:var(--blue)">${iv.currency}</span>`:`<span class="muted">KES</span>`}</td>
      <td style="text-align:right;font-weight:700;white-space:nowrap">${iv.currency||'KES'} ${fmtn(iv.balance)}</td>
    </tr>`;
  }).join('');
  return `<div class="rptwrap"><table class="rpt" style="font-size:11.5px">
    <thead><tr>
      <th class="l"><input type="checkbox" ${allSel?'checked':''} onclick="sbSelAll(this.checked)" title="Select all shown"></th>
      <th class="l">Client</th><th class="l">Invoice #</th><th>Date</th><th>Cur</th><th style="text-align:right">Balance</th>
    </tr></thead>
    <tbody>${rows}</tbody>
  </table></div>`;
}

/* days overdue relative to today; null if no due date, 0/negative = not yet due */
function sbDaysOverdue(due){ if(!due) return null; const d=new Date(due+'T00:00:00'); if(isNaN(d)) return null; const now=new Date(); now.setHours(0,0,0,0); return Math.floor((now-d)/86400000); }
function sbOverdueCell(due){
  const n=sbDaysOverdue(due);
  if(n===null) return `<span style="color:#94A3B8">—</span>`;
  if(n>0)  return `<span style="color:#D64933;font-weight:700">${n} day${n===1?'':'s'} overdue</span>`;
  if(n===0) return `<span style="color:#D97706;font-weight:600">due today</span>`;
  return `<span style="color:#64748B">due in ${-n} day${n===-1?'':'s'}</span>`;
}

/* Branded consolidated-statement HTML — mirrors the Emails-tab statement look. Used for both the
   on-screen preview and the Zoho Mail draft body. */
function sbPreviewHtml(sel, billName, intro){
  const esc=s=>String(s==null?'':s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
  const introHtml = esc(intro||('Consolidated statement of outstanding invoices.')).replace(/\n/g,'<br>');
  const byCur={};
  sel.forEach(iv=>{ const c=String(iv.currency||'KES').toUpperCase(); (byCur[c]=byCur[c]||[]).push(iv); });
  const curKeys=Object.keys(byCur).sort();
  const block=curKeys.map(cur=>{
    const rows=byCur[cur]; let due=0;
    const body=rows.map((iv,i)=>{ due+=(+iv.balance||0); const zebra=i%2?'#FAFBFD':'#FFFFFF';
      return `<tr style="background:${zebra}">
        <td style="padding:9px 12px;border-bottom:1px solid #ECEFF3;color:#15202B">${esc(iv.number)}</td>
        <td style="padding:9px 12px;border-bottom:1px solid #ECEFF3;color:#475569">${esc(iv.customer_name||'')}</td>
        <td style="padding:9px 12px;border-bottom:1px solid #ECEFF3;color:#475569;white-space:nowrap">${esc(iv.date||'')}</td>
        <td style="padding:9px 12px;border-bottom:1px solid #ECEFF3;white-space:nowrap">${sbOverdueCell(iv.due_date)}</td>
        <td style="padding:9px 12px;border-bottom:1px solid #ECEFF3;text-align:right;color:#15202B;font-weight:600;white-space:nowrap">${cur} ${fmtn(iv.balance)}</td></tr>`; }).join('');
    return `<table style="border-collapse:collapse;width:100%;font-size:13px;margin:0 0 14px;border:1px solid #E6EAF0;border-radius:8px;overflow:hidden">
      <thead><tr style="background:#F56F00;color:#fff">
        <th style="padding:10px 12px;text-align:left;font-size:11px;letter-spacing:.4px;text-transform:uppercase">Invoice</th>
        <th style="padding:10px 12px;text-align:left;font-size:11px;letter-spacing:.4px;text-transform:uppercase">Company</th>
        <th style="padding:10px 12px;text-align:left;font-size:11px;letter-spacing:.4px;text-transform:uppercase">Date</th>
        <th style="padding:10px 12px;text-align:left;font-size:11px;letter-spacing:.4px;text-transform:uppercase">Overdue</th>
        <th style="padding:10px 12px;text-align:right;font-size:11px;letter-spacing:.4px;text-transform:uppercase">Balance</th></tr></thead>
      <tbody>${body}</tbody>
      <tfoot><tr style="background:#15202B;color:#fff">
        <td colspan="4" style="padding:11px 12px;text-align:right;font-weight:700;letter-spacing:.3px">TOTAL OUTSTANDING (${cur})</td>
        <td style="padding:11px 12px;text-align:right;font-weight:700;color:#F8B26A;white-space:nowrap">${cur} ${fmtn(due)}</td></tr></tfoot>
    </table>`;
  }).join('');
  return `<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#15202B">
    <div style="background:#15202B;border-radius:10px 10px 0 0;padding:18px 22px;color:#fff">
      <table width="100%" style="border-collapse:collapse"><tr>
        <td style="vertical-align:middle"><span style="display:inline-block;background:#F56F00;color:#fff;font-weight:700;font-size:15px;padding:7px 10px;border-radius:8px;letter-spacing:.5px">912</span></td>
        <td style="vertical-align:middle;text-align:right">
          <div style="font-size:16px;font-weight:700;letter-spacing:.5px">STATEMENT OF ACCOUNT</div>
          <div style="font-size:12px;color:#AEB9C7">${esc(billName||'')}</div>
        </td></tr></table>
    </div>
    <div style="border:1px solid #E6EAF0;border-top:0;border-radius:0 0 10px 10px;padding:20px">
      <p style="margin:0 0 16px;line-height:1.55">${introHtml}</p>
      ${block}
      <p style="margin:0;color:#64748B;font-size:12px;line-height:1.5">For any queries on this statement, please reply to this email.</p>
      <p style="margin:14px 0 0;padding-top:12px;border-top:1px solid #EEF1F5;color:#AEB9C7;font-size:10px;line-height:1.5;font-style:italic">${esc(CFG.stmtFooter||"Prepared and reconciled by the Waitara Holdings Group of Companies Console — Nine One Two Holdings' intelligent finance engine, delivering precise, automated account statements in real time.")}</p>
    </div>
  </div>`;
}

function sbActionHtml(){
  const sel=sbSelected();
  if(!sel.length) return `<div class="muted" style="font-size:12px;padding:10px 2px">Tick the unpaid invoices you want to consolidate — a statement preview will appear here.</div>`;
  sbDefaults();
  const clients=sbClients();
  const billName=sbBillName();
  const opts=clients.map(c=>`<option value="${c.id}" ${c.id===SB.billTo?'selected':''}>${c.name}</option>`).join('');
  return `<div class="card" style="margin-top:10px">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-bottom:10px">
      <b style="font-size:12.5px">Statement preview — ${sel.length} invoice${sel.length===1?'':'s'}</b>
      <button class="btn sec" style="width:auto;padding:5px 12px;font-size:11px" onclick="sbDownloadPdf()">⤓ Download PDF</button>
    </div>
    <div style="border:1px solid var(--line);border-radius:10px;overflow:hidden;margin-bottom:14px">${sbPreviewHtml(sel, billName, SB.intro)}</div>

    <label>Statement for</label>
    <select onchange="sbPickBill(this.value)">${opts}</select>

    <label style="margin-top:12px">Send to</label>
    <div style="display:flex;gap:8px">
      <input type="email" list="sbBookList" style="flex:1" value="${qesc(SB.to_email)}" oninput="sbField('to_email',this.value)" placeholder="client@email.com">
      <button class="btn sec" style="flex:0 0 auto;width:auto;padding:10px 14px" onclick="sbAddEmail()">＋ Save</button>
    </div>
    <datalist id="sbBookList">${(SB.book||[]).map(e=>`<option value="${qesc(e)}">`).join('')}</datalist>
    ${(SB.book||[]).length?`<div style="margin:-2px 0 12px;display:flex;flex-wrap:wrap;gap:6px">
      ${SB.book.map(e=>`<span style="display:inline-flex;align-items:center;gap:6px;background:#F1F4F8;border:1px solid var(--line);border-radius:20px;padding:4px 10px;font-size:11.5px">
        <span style="cursor:pointer;color:var(--blue)" onclick="sbUseEmail('${e.replace(/'/g,"")}')">${e}</span>
        <span style="cursor:pointer;color:var(--bad);font-weight:700" title="Remove" onclick="sbRemoveEmail('${e.replace(/'/g,"")}')">×</span></span>`).join('')}
    </div>`:`<div class="muted" style="margin:-4px 0 12px;font-size:11px">Tip: type an email and tap “Save” to remember it for this client.</div>`}

    <label>Subject</label>
    <input type="text" value="${qesc(SB.subject)}" oninput="sbField('subject',this.value)">

    <label>Message</label>
    <textarea rows="4" oninput="sbField('intro',this.value)" style="width:100%;box-sizing:border-box;padding:10px 12px;border:1px solid var(--line);border-radius:9px;font-size:13px;font-family:inherit;margin-bottom:12px">${SB.intro||''}</textarea>

    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <button class="btn" style="flex:1;min-width:200px" onclick="sbSaveDraft()" ${SB.saving?'disabled':''}>${SB.saving?'Saving…':'Save to Zoho Drafts'}</button>
    </div>
    <div class="muted" style="font-size:11px;margin-top:8px">Creates a draft in your Zoho Mail — it does not send. Review and send it from Zoho Mail. The full consolidated statement (all selected invoices) is in the email body.</div>
    ${SB.msg?`<div class="${SB.msgErr?'warn':'ok'}" style="margin-top:10px">${SB.msg}</div>`:''}
  </div>`;
}

function sbDownloadPdf(){
  const sel=sbSelected(); if(!sel.length) return;
  const billName=sbBillName();
  const inner=sbPreviewHtml(sel, billName, SB.intro);
  const w=window.open('', '_blank');
  if(!w){ alert('Please allow pop-ups for this site to download the PDF.'); return; }
  w.document.write('<!doctype html><html><head><meta charset="utf-8"><title>Consolidated statement - '+billName.replace(/[<>&]/g,'')+'</title>'
    + '<style>@page{margin:14mm} body{font-family:Arial,Helvetica,sans-serif;margin:0;padding:18px;color:#15202B}</style>'
    + '</head><body>' + inner
    + '<scr'+'ipt>window.onload=function(){setTimeout(function(){window.print();},250);};</scr'+'ipt>'
    + '</body></html>');
  w.document.close();
}

function sbSaveDraft(){
  const sel=sbSelected();
  if(!sel.length || SB.saving) return;
  if(!(SB.to_email||'').trim()){ SB.msg='Add a recipient email first.'; SB.msgErr=true; render(); return; }
  SB.saving=true; SB.msg=''; render();
  const html=sbPreviewHtml(sel, sbBillName(), SB.intro);
  fetch('api/email_draft.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},
    body:JSON.stringify({ to:SB.to_email, subject:SB.subject, html:html })})
    .then(r=>r.json()).then(j=>{
      SB.saving=false;
      if(j.ok){ SB.msg='Draft saved to Zoho Mail'+(j.from?(' ('+j.from+')'):'')+'. Open Zoho Mail → Drafts to review and send.'; SB.msgErr=false; }
      else { SB.msg='Could not save draft: '+(j.error||'failed'); SB.msgErr=true; }
      render();
    }).catch(e=>{ SB.saving=false; SB.msg='Request failed: '+e; SB.msgErr=true; render(); });
}

function vStmtBuild(){
  if(SB.loading && !SB.loaded) return `<h2>Statement Builder</h2><div class="card muted">Loading unpaid invoices from Zoho…</div>`;
  return `<div class="em-compact">
  <h2>Statement Builder</h2>
  <div class="card" style="margin-bottom:10px">
    <div class="muted" style="font-size:12px;line-height:1.5">Tick any unpaid invoices — across any client or currency — preview the statement, then save a <b>consolidated statement draft to your Zoho Mail</b> (nothing is sent). A client with several companies? Pick the invoices, choose who it's addressed to, and send one statement.</div>
  </div>
  ${SB.msg && !SB.result ? `<div class="${SB.msgErr?'warn':'ok'}">${SB.msg}</div>`:''}
  <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-bottom:6px">
    <label style="margin:0">Unpaid invoices (${SB.invoices.length})</label>
    <button class="btn sec" style="width:auto;padding:5px 12px;font-size:11px" onclick="sbLoad()">↺ Refresh</button>
  </div>
  <input id="sbSearch" type="text" autocomplete="off" placeholder="🔍 Search by invoice number or client…" value="${qesc(SB.q)}" oninput="sbSearch(this.value)">
  <div id="sbFilterBox">${sbFilterHtml()}</div>
  <div id="sbTableBox">${sbTableHtml()}</div>
  <div id="sbActionBox">${sbActionHtml()}</div>
  </div>`;
}
/* ================= end Statement Builder ================= */

/* ================= Late Payers (consistent late payers, last 52 weeks) ================= */
let LATE = { loaded:false, loading:false, data:null, err:'' };

function lateLoad(force){
  if(LATE.loading) return;
  LATE.loading=true; LATE.err=''; render();
  fetch('api/late_payers.php',{credentials:'same-origin'})
    .then(r=>r.json()).then(j=>{
      LATE.loading=false; LATE.loaded=true;
      if(j.ok){ LATE.data=j; } else { LATE.err=j.error||'Failed to load'; }
      render();
    }).catch(e=>{ LATE.loading=false; LATE.loaded=true; LATE.err='Request failed: '+e; render(); });
}

function lateCard(r, rank){
  const medals=['🥇','🥈','🥉'];
  const accent=['#D64933','#F56F00','#D97706'][rank]||'#64748B';
  return `<div class="card" style="margin:0;border-top:4px solid ${accent};padding:16px 16px 14px">
    <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px">
      <span style="font-size:22px">${medals[rank]||('#'+(rank+1))}</span>
      <div style="min-width:0">
        <div style="font-weight:700;font-size:13.5px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${(r.customer_name||'(no name)')}</div>
        <div class="muted" style="font-size:10.5px">${r.lateCount} of ${r.considered} invoices late · ${r.latePct}% of the time</div>
      </div>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px 12px">
      <div><div class="muted" style="font-size:9.5px;text-transform:uppercase;letter-spacing:.4px">Avg days late</div><div style="font-size:22px;font-weight:800;color:${accent};line-height:1.1">${r.avgDaysLate}</div></div>
      <div><div class="muted" style="font-size:9.5px;text-transform:uppercase;letter-spacing:.4px">Worst</div><div style="font-size:22px;font-weight:800;color:var(--ink);line-height:1.1">${r.maxDaysLate}d</div></div>
      <div><div class="muted" style="font-size:9.5px;text-transform:uppercase;letter-spacing:.4px">Late rate</div><div style="font-size:14px;font-weight:700;line-height:1.3">${r.latePct}%</div></div>
      <div><div class="muted" style="font-size:9.5px;text-transform:uppercase;letter-spacing:.4px">Open overdue</div><div style="font-size:14px;font-weight:700;color:${r.overdueValueKES>0?'var(--bad)':'var(--mute)'};line-height:1.3">${r.overdueValueKES>0?('KES '+fmtn(r.overdueValueKES)):'—'}</div></div>
    </div>
  </div>`;
}

function vLatePay(){
  if(LATE.loading && !LATE.data) return `<h2>Late Payers</h2><div class="card muted">Analysing 52 weeks of invoices &amp; payments from Zoho…</div>`;
  if(LATE.err) return `<h2>Late Payers</h2><div class="warn">${LATE.err}</div><button class="btn sec" style="width:auto;padding:6px 14px" onclick="lateLoad(true)">↺ Retry</button>`;
  const d=LATE.data;
  if(!d) return `<h2>Late Payers</h2><div class="card muted">No data.</div>`;
  const top=d.top||[], all=d.all||[];
  const head=`<div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-bottom:4px">
    <h2 style="margin:0">Top 3 consistent late payers</h2>
    <button class="btn sec" style="width:auto;padding:5px 12px;font-size:11px" onclick="lateLoad(true)">↺ Refresh</button>
  </div>
  <div class="muted" style="font-size:12px;margin:2px 0 14px;line-height:1.5">Based on invoices raised in the last 52 weeks (${d.from} → ${d.to}). An invoice counts as late when it was <b>paid after its due date</b>, or is <b>still unpaid past due</b>. Ranked by how often <i>and</i> how badly each client pays late.</div>`;

  if(!top.length) return `${head}<div class="card" style="text-align:center;padding:24px">🎉 No consistent late payers — every client with a track record is paying on time.</div>`;

  const cards=`<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:16px" class="late-cards">${top.map((r,i)=>lateCard(r,i)).join('')}</div>`;

  const rest=all.length>3?`
    <div class="sect"><b>Full ranking (${all.length})</b><span class="ln"></span></div>
    <div class="rptwrap"><table class="rpt" style="font-size:11.5px">
      <thead><tr><th class="l">#</th><th class="l">Client</th><th>Late / total</th><th>Late rate</th><th>Avg days</th><th>Worst</th><th style="text-align:right">Open overdue</th></tr></thead>
      <tbody>${all.map((r,i)=>`<tr>
        <td class="l">${i+1}</td>
        <td class="l" style="font-weight:600">${(r.customer_name||'(no name)')}</td>
        <td>${r.lateCount}/${r.considered}</td>
        <td style="font-weight:700;color:${r.latePct>=66?'var(--bad)':r.latePct>=33?'var(--orange)':'var(--mute)'}">${r.latePct}%</td>
        <td style="font-weight:700">${r.avgDaysLate}</td>
        <td>${r.maxDaysLate}d</td>
        <td style="text-align:right;color:${r.overdueValueKES>0?'var(--bad)':'var(--mute)'}">${r.overdueValueKES>0?('KES '+fmtn(r.overdueValueKES)):'—'}</td>
      </tr>`).join('')}</tbody>
    </table></div>`:'';

  return `${head}${cards}${rest}
    <div class="muted" style="font-size:10.5px;margin-top:10px">Generated ${new Date(d.generatedAt).toLocaleString()} · only clients with ≥2 invoices and ≥2 late payments are ranked. USD overdue converted at KES ${(+d.rate).toLocaleString('en-KE',{maximumFractionDigits:2})}.</div>`;
}
/* ================= end Late Payers ================= */

/* ================= Bulk Expenses (log many at once) ================= */
let BEXP = { date:(()=>{const d=new Date();return d.toISOString().slice(0,10);})(), paid:'',
             rows:[{amount:'',desc:'',acc:'',accQ:''},{amount:'',desc:'',acc:'',accQ:''},{amount:'',desc:'',acc:'',accQ:''}],
             running:false, msg:'', err:false };
function bexpAddRow(){ BEXP.rows.push({amount:'',desc:'',acc:'',accQ:''}); render(); }
function bexpDelRow(i){ BEXP.rows.splice(i,1); if(!BEXP.rows.length) BEXP.rows.push({amount:'',desc:'',acc:'',accQ:''}); render(); }
function bexpClearLogged(){ BEXP.rows=BEXP.rows.filter(r=>!r.done); if(!BEXP.rows.length) BEXP.rows.push({amount:'',desc:'',acc:'',accQ:''}); BEXP.msg=''; render(); }
function bexpField(i,k,v){ if(BEXP.rows[i]) BEXP.rows[i][k]=v; }
function bexpFmt(i){ const a=String((BEXP.rows[i]||{}).amount||''); const dot=a.indexOf('.'); const intp=dot>=0?a.slice(0,dot):a; const decp=dot>=0?a.slice(dot+1):null; return intp.replace(/\B(?=(\d{3})+(?!\d))/g,',')+(decp!==null?'.'+decp:''); }
function bexpAmount(i,el){
  const v=el.value,pos=el.selectionStart||0; const db=(v.slice(0,pos).match(/\d/g)||[]).length;
  const raw=v.replace(/[^0-9.]/g,''); const dot=raw.indexOf('.');
  const intp=(dot>=0?raw.slice(0,dot):raw).replace(/\./g,''); const decp=dot>=0?raw.slice(dot+1).replace(/\./g,'').slice(0,2):null;
  BEXP.rows[i].amount=intp+(decp!==null?'.'+decp:'');
  const f=intp.replace(/\B(?=(\d{3})+(?!\d))/g,',')+(decp!==null?'.'+decp:''); el.value=f;
  let np=0,seen=0; while(np<f.length&&seen<db){if(/\d/.test(f[np]))seen++;np++;} el.setSelectionRange(np,np);
}
function bexpAccMatches(i){ const acc=EXP.accounts; if(!acc) return []; const q=String((BEXP.rows[i]||{}).accQ||'').trim().toLowerCase(); let l=acc.expense||[]; if(q) l=l.filter(a=>(a.name||'').toLowerCase().includes(q)); return l.slice(0,50); }
function bexpAccDDHtml(i){
  const list=bexpAccMatches(i); const sel=(BEXP.rows[i]||{}).acc;
  if(!list.length) return `<div style="padding:7px 10px;color:var(--mute);font-size:11.5px">No match.</div>`;
  return list.map(a=>{ const on=a.id===sel; return `<div onmousedown="event.preventDefault();bexpAccChoose(${i},'${a.id}')" style="padding:7px 10px;font-size:12px;cursor:pointer;border-bottom:1px solid var(--line);background:${on?'#FFF4EB':'#fff'}" onmouseover="this.style.background='#F1F4F8'" onmouseout="this.style.background='${on?'#FFF4EB':'#fff'}'">${(a.name||'').replace(/</g,'&lt;')}</div>`; }).join('');
}
function bexpAccSearch(i,v){ BEXP.rows[i].accQ=v; BEXP.rows[i].acc=''; const dd=document.getElementById('bexpDD'+i); if(dd){dd.innerHTML=bexpAccDDHtml(i);dd.style.display='block';} const inp=document.getElementById('bexpAcc'+i); if(inp)inp.style.borderColor='#F7C99A'; }
function bexpAccFocus(i){ const dd=document.getElementById('bexpDD'+i); if(dd){dd.innerHTML=bexpAccDDHtml(i);dd.style.display='block';} }
function bexpAccChoose(i,id){ const acc=EXP.accounts; if(!acc) return; const a=(acc.expense||[]).find(x=>x.id===id); if(!a) return; BEXP.rows[i].acc=id; BEXP.rows[i].accQ=a.name; const inp=document.getElementById('bexpAcc'+i); if(inp){inp.value=a.name;inp.style.borderColor='var(--line)';} const dd=document.getElementById('bexpDD'+i); if(dd)dd.style.display='none'; }
function bexpAccBlur(i){ setTimeout(()=>{const dd=document.getElementById('bexpDD'+i); if(dd)dd.style.display='none';},150); }
async function bexpRun(){
  if(BEXP.running) return;
  if(!BEXP.date){ BEXP.msg='Pick a date.'; BEXP.err=true; render(); return; }
  const todo=BEXP.rows.filter(r=>!r.done && (parseFloat(r.amount)||0)>0 && r.acc);
  if(!todo.length){ BEXP.msg='Add at least one row with an amount and an expense account.'; BEXP.err=true; render(); return; }
  BEXP.running=true; BEXP.msg=''; BEXP.err=false; render();
  let ok=0, fail=0;
  for(const r of todo){
    r.status='busy'; render();
    try{
      const res=await fetch('api/expense_quick.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},
        body:JSON.stringify({ amount:parseFloat(r.amount)||0, description:r.desc, account_id:r.acc, paid_through_account_id:BEXP.paid, date:BEXP.date })});
      const j=await res.json();
      if(j.ok){ r.status='ok'; r.done=true; ok++; } else { r.status='fail'; r.err=j.error||'failed'; fail++; }
    }catch(e){ r.status='fail'; r.err=String(e); fail++; }
    render();
  }
  BEXP.running=false; BEXP.err=(ok===0);
  BEXP.msg=`Logged ${ok} expense${ok===1?'':'s'} in Zoho${fail?`, ${fail} failed`:''}.`;
  render();
}
function vBulkExp(){
  const acc=EXP.accounts;
  const paidOpts=acc?['<option value="">— paid through (optional) —</option>'].concat((acc.paid||[]).map(a=>`<option value="${a.id}" ${a.id===BEXP.paid?'selected':''}>${(a.name||'').replace(/</g,'&lt;')}</option>`)).join(''):'';
  const inp='box-sizing:border-box;padding:7px 9px;border:1px solid var(--line);border-radius:8px;font-size:12px;font-family:inherit;width:100%';
  const pending=BEXP.rows.filter(r=>!r.done && (parseFloat(r.amount)||0)>0 && r.acc);
  const total=pending.reduce((s,r)=>s+(parseFloat(r.amount)||0),0);
  const doneCount=BEXP.rows.filter(r=>r.done).length;

  const rowsHtml=BEXP.rows.map((r,i)=>{
    const stat = r.status==='busy'?`<span style="color:var(--blue);font-size:10.5px">saving…</span>`
      : r.status==='ok'?`<span style="color:var(--good);font-size:10.5px;font-weight:700">✓ logged</span>`
      : r.status==='fail'?`<span style="color:var(--bad);font-size:10.5px">✗ ${(r.err||'').slice(0,40)}</span>`:'';
    const ro=r.done?'readonly tabindex="-1"':'';
    return `<div style="display:grid;grid-template-columns:118px 1fr 1.05fr 24px;gap:6px;align-items:center;padding:5px 0;border-bottom:1px solid var(--line);${r.done?'opacity:.55':''}">
      <input id="bexpAmt${i}" type="text" inputmode="decimal" placeholder="Amount" value="${bexpFmt(i)}" oninput="bexpAmount(${i},this)" ${ro} style="${inp};text-align:right;font-weight:600">
      <input type="text" placeholder="Description" value="${(r.desc||'').replace(/"/g,'&quot;')}" oninput="bexpField(${i},'desc',this.value)" ${ro} style="${inp}">
      <div style="position:relative">
        <input id="bexpAcc${i}" type="text" autocomplete="off" placeholder="🔍 account" value="${(r.accQ||'').replace(/"/g,'&quot;')}" oninput="bexpAccSearch(${i},this.value)" onfocus="bexpAccFocus(${i})" onblur="bexpAccBlur(${i})" ${ro} style="${inp};border-color:${r.acc?'var(--line)':'#F7C99A'}">
        <div id="bexpDD${i}" style="display:none;position:absolute;left:0;right:0;top:calc(100% + 2px);background:#fff;border:1px solid var(--line);border-radius:8px;box-shadow:0 10px 26px rgba(21,32,43,.16);max-height:190px;overflow-y:auto;z-index:${50-i}"></div>
      </div>
      ${r.done?`<span style="text-align:center;color:var(--good);font-weight:700">✓</span>`:`<button onclick="bexpDelRow(${i})" title="Remove row" style="background:none;border:none;color:var(--mute);font-size:16px;cursor:pointer;line-height:1;padding:0">×</button>`}
      ${stat?`<div style="grid-column:1/-1;padding:1px 0 2px">${stat}</div>`:''}
    </div>`;
  }).join('');

  return `<div class="em-compact">
  <h2>Log expenses</h2>
  ${!acc?`<div class="card muted" style="text-align:center;padding:18px">${EXP.loadingAcc?'Loading accounts from Zoho…':'<span style="color:var(--orange);cursor:pointer" onclick="expLoadAccounts();render()">Load accounts →</span>'}</div>`:`
  <div class="card" style="padding:12px 14px;margin-bottom:10px">
    <div style="display:grid;grid-template-columns:1fr 1.4fr;gap:10px;align-items:end">
      <div><label style="font-size:11px">Date (applies to all)</label><input type="date" value="${BEXP.date}" onchange="BEXP.date=this.value" style="${inp}"></div>
      <div><label style="font-size:11px">Paid through (applies to all)</label><select onchange="BEXP.paid=this.value" style="${inp}">${paidOpts}</select></div>
    </div>
  </div>

  <div class="card" style="padding:10px 14px 12px;margin-bottom:10px">
    <div style="display:grid;grid-template-columns:118px 1fr 1.05fr 24px;gap:6px;padding-bottom:4px;border-bottom:2px solid var(--ink)">
      <div style="font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:var(--mute);text-align:right">Amount</div>
      <div style="font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:var(--mute)">Description</div>
      <div style="font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:var(--mute)">Expense account</div>
      <div></div>
    </div>
    ${rowsHtml}
    <div style="display:flex;gap:10px;align-items:center;margin-top:10px;flex-wrap:wrap">
      <button class="btn sec" style="width:auto;padding:6px 14px;font-size:12px" onclick="bexpAddRow()">＋ Add row</button>
      ${doneCount?`<button class="btn sec" style="width:auto;padding:6px 14px;font-size:12px" onclick="bexpClearLogged()">Clear ${doneCount} logged</button>`:''}
      <span style="margin-left:auto;font-size:12px;color:var(--mute)">To log: <b style="color:var(--ink)">${pending.length}</b> · <b style="color:var(--ink)">KES ${fmtn(total)}</b></span>
    </div>
  </div>

  <button class="btn" onclick="bexpRun()" ${BEXP.running||!pending.length?'disabled':''}>${BEXP.running?'Logging…':`Log ${pending.length} expense${pending.length===1?'':'s'} to Zoho`}</button>
  ${BEXP.msg?`<div class="${BEXP.err?'warn':'ok'}" style="margin-top:10px">${BEXP.msg}</div>`:''}
  <div class="muted" style="font-size:11px;margin-top:8px">Each row posts a separate expense to Zoho Books. Amount + expense account are required per row; date &amp; paid-through apply to the whole batch.</div>`}
  </div>`;
}
/* ================= end Bulk Expenses ================= */

/* ================= Ask your books (admin AI advisor) ================= */
let ASK = { msgs:[], input:'', busy:false, err:'', saved:[], savedOpen:false, savedLoaded:false, currentId:null, saveMsg:'' };
const ASK_SUGGESTIONS = [
  'Why did my profit fall recently?',
  'Which invoices lost me money and why?',
  'What are my biggest expenses this period?',
  'Who owes me the most and how overdue are they?',
  'How is this month compared to last month?'
];
function askEsc(s){ return String(s==null?'':s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
function askFmt(t){
  // light markdown → HTML: **bold**, bullet lines, paragraphs
  let h=askEsc(t).replace(/\*\*(.+?)\*\*/g,'<b>$1</b>');
  const lines=h.split('\n'); let out='', inList=false;
  lines.forEach(l=>{
    if(/^\s*[-•]\s+/.test(l)){ if(!inList){out+='<ul style="margin:6px 0;padding-left:18px">';inList=true;} out+='<li style="margin:2px 0">'+l.replace(/^\s*[-•]\s+/,'')+'</li>'; }
    else { if(inList){out+='</ul>';inList=false;} if(l.trim()==='') out+='<div style="height:6px"></div>'; else out+='<div>'+l+'</div>'; }
  });
  if(inList) out+='</ul>';
  return out;
}
function askScrollDown(){ setTimeout(()=>{ const b=document.getElementById('askScroll'); if(b) b.scrollTop=b.scrollHeight; },30); }
function askInput(v){ ASK.input=v; }
function askPick(q){ ASK.input=q; askSend(); }
async function askSend(){
  const q=(ASK.input||'').trim();
  if(!q || ASK.busy) return;
  ASK.msgs.push({role:'user',content:q});
  ASK.input=''; ASK.busy=true; ASK.err=''; render(); askScrollDown();
  const history=ASK.msgs.slice(0,-1).map(m=>({role:m.role,content:m.content}));
  try{
    const r=await fetch('api/ask.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({question:q,history})});
    const j=await r.json();
    ASK.busy=false;
    if(j.ok){ ASK.msgs.push({role:'assistant',content:j.answer}); }
    else { ASK.err=j.error||'Something went wrong.'; }
    render(); askScrollDown();
  }catch(e){ ASK.busy=false; ASK.err='Request failed: '+e; render(); }
}
function askNewChat(){ ASK.msgs=[]; ASK.currentId=null; ASK.err=''; ASK.savedOpen=false; ASK.saveMsg=''; render(); }
function askConvosLoad(){
  fetch('api/ask_convos.php?action=list',{credentials:'same-origin'}).then(r=>r.json()).then(j=>{ if(j&&j.ok){ ASK.saved=j.convos||[]; if(TAB==='ask') render(); } }).catch(()=>{});
}
function askToggleSaved(){ ASK.savedOpen=!ASK.savedOpen; if(ASK.savedOpen) askConvosLoad(); render(); }
async function askSaveConvo(){
  if(!ASK.msgs.length){ ASK.saveMsg='Nothing to save yet.'; render(); return; }
  ASK.saveMsg='Saving…'; render();
  try{
    const r=await fetch('api/ask_convos.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'save',id:ASK.currentId,messages:ASK.msgs})});
    const j=await r.json();
    if(j&&j.ok){ ASK.currentId=j.id; ASK.saveMsg='Saved ✓ — kept for 7 days'; askConvosLoad(); }
    else ASK.saveMsg='Save failed: '+((j&&j.error)||'error');
    render();
    setTimeout(()=>{ ASK.saveMsg=''; if(TAB==='ask') render(); },3000);
  }catch(e){ ASK.saveMsg='Error saving: '+e; render(); }
}
function askOpenConvo(id){
  fetch('api/ask_convos.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'get',id})}).then(r=>r.json()).then(j=>{
    if(j&&j.ok){ ASK.msgs=(j.convo.messages||[]).map(m=>({role:m.role,content:m.content})); ASK.currentId=id; ASK.savedOpen=false; ASK.err=''; ASK.saveMsg=''; render(); askScrollDown(); }
    else { ASK.err=(j&&j.error)||'Could not open that conversation.'; render(); }
  }).catch(e=>{ ASK.err='Could not open: '+e; render(); });
}
function askDeleteConvo(id){
  fetch('api/ask_convos.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'delete',id})}).then(r=>r.json()).then(()=>{ if(ASK.currentId===id) ASK.currentId=null; askConvosLoad(); }).catch(()=>{});
}
function askExpiryTxt(ts){ try{ const d=new Date(ts*1000); const days=Math.max(0,Math.ceil((ts*1000-Date.now())/86400000)); return 'expires in '+days+'d ('+d.toLocaleDateString()+')'; }catch(e){ return ''; } }
function vAsk(){
  if(!ME.admin) return `<div class="card muted" style="text-align:center">Admins only.</div>`;
  const bubbles=ASK.msgs.map(m=>{
    if(m.role==='user') return `<div style="display:flex;justify-content:flex-end;margin:8px 0"><div style="max-width:82%;background:var(--grad-orange);color:#fff;padding:9px 13px;border-radius:14px 14px 4px 14px;font-size:13px;line-height:1.5">${askEsc(m.content)}</div></div>`;
    return `<div style="display:flex;justify-content:flex-start;margin:8px 0"><div style="max-width:88%;background:var(--surface-2);border:1px solid var(--hair);padding:11px 14px;border-radius:14px 14px 14px 4px;font-size:13px;line-height:1.55;color:var(--ink)">${askFmt(m.content)}</div></div>`;
  }).join('');
  const empty = !ASK.msgs.length ? `
    <div style="text-align:center;padding:24px 10px">
      <div style="font-size:34px">🤖</div>
      <div style="font-weight:700;font-size:15px;margin-top:6px">Ask your books anything</div>
      <div class="muted" style="font-size:12px;margin-top:4px;max-width:440px;margin-left:auto;margin-right:auto">Plain-English answers grounded in your full Zoho history — revenue, costs, profit, expenses and receivables across every year on record.</div>
      <div style="display:flex;flex-wrap:wrap;gap:7px;justify-content:center;margin-top:14px">
        ${ASK_SUGGESTIONS.map(s=>`<button class="btn sec" style="width:auto;padding:7px 12px;font-size:11.5px" onclick="askPick('${s.replace(/'/g,"\\'")}')">${s}</button>`).join('')}
      </div>
    </div>` : '';
  const savedPanel = ASK.savedOpen ? `
    <div class="card" style="padding:10px 12px;margin-bottom:10px">
      <div style="font-size:11px;color:var(--orange);font-weight:600;margin-bottom:8px">⚠️ Saved chats are kept for 7 days, then automatically deleted.</div>
      ${ASK.saved.length ? ASK.saved.map(c=>`
        <div style="display:flex;align-items:center;gap:8px;padding:7px 0;border-bottom:1px solid var(--hair)">
          <div style="flex:1;min-width:0;cursor:pointer" onclick="askOpenConvo('${c.id}')">
            <div style="font-size:12.5px;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${askEsc(c.title)}</div>
            <div class="muted" style="font-size:10px;margin-top:1px">${c.count} message${c.count===1?'':'s'} · ${askExpiryTxt(c.expires)}</div>
          </div>
          <button class="btn sec" style="width:auto;padding:4px 11px;font-size:11px" onclick="askOpenConvo('${c.id}')">Open</button>
          <span title="Delete" onclick="askDeleteConvo('${c.id}')" style="cursor:pointer;color:var(--bad);font-weight:800;font-size:16px;line-height:1;padding:0 4px">×</span>
        </div>`).join('') : `<div class="muted" style="font-size:11.5px;padding:6px 0">No saved conversations yet — start chatting, then hit <b>💾 Save</b>.</div>`}
    </div>` : '';
  return `<div class="em-compact" style="display:flex;flex-direction:column;height:calc(100vh - 180px);min-height:420px">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-bottom:8px">
      <h2 style="margin:0">Ask your books 🤖</h2>
      <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">
        ${ASK.saveMsg?`<span class="muted" style="font-size:11px">${askEsc(ASK.saveMsg)}</span>`:''}
        <button class="btn sec" style="width:auto;padding:5px 11px;font-size:11px" onclick="askSaveConvo()" ${ASK.msgs.length?'':'disabled'}>💾 Save</button>
        <button class="btn sec" style="width:auto;padding:5px 11px;font-size:11px" onclick="askToggleSaved()">📁 Saved${ASK.saved.length?` (${ASK.saved.length})`:''}</button>
        <button class="btn sec" style="width:auto;padding:5px 11px;font-size:11px" onclick="askNewChat()">＋ New</button>
      </div>
    </div>
    ${savedPanel}
    <div id="askScroll" class="card" style="flex:1;overflow-y:auto;padding:12px 14px;margin-bottom:10px">
      ${empty}${bubbles}
      ${ASK.busy?`<div style="display:flex;justify-content:flex-start;margin:8px 0"><div style="background:var(--surface-2);border:1px solid var(--hair);padding:10px 14px;border-radius:14px;font-size:12.5px;color:var(--mute)">Analysing your books…</div></div>`:''}
      ${ASK.err?`<div class="warn" style="margin:8px 0">${askEsc(ASK.err)}</div>`:''}
    </div>
    <div style="display:flex;gap:8px;align-items:flex-end">
      <textarea id="askInput" rows="1" placeholder="Ask about profit, costs, invoices, who owes you…" oninput="askInput(this.value)" onkeydown="if(event.key==='Enter'&&!event.shiftKey){event.preventDefault();askSend();}" style="flex:1;box-sizing:border-box;padding:11px 13px;border:1px solid var(--line);border-radius:11px;font-size:13px;font-family:inherit;resize:none;max-height:120px">${askEsc(ASK.input)}</textarea>
      <button class="btn" style="width:auto;padding:11px 18px;flex-shrink:0" onclick="askSend()" ${ASK.busy?'disabled':''}>${ASK.busy?'…':'Ask'}</button>
    </div>
    <div class="muted" style="font-size:10.5px;margin-top:6px">Answers are AI-generated from a summary of your full Zoho history and may contain mistakes — verify before acting. Admin only.</div>
  </div>`;
}
/* ================= end Ask your books ================= */

/* ================= Settings ================= */
function whAgo(iso){
  if(!iso) return '';
  const t=new Date(iso).getTime(); if(isNaN(t)) return '';
  const s=Math.max(0,Math.floor((Date.now()-t)/1000));
  if(s<60) return s+'s ago';
  if(s<3600) return Math.floor(s/60)+'m ago';
  if(s<86400) return Math.floor(s/3600)+'h ago';
  return Math.floor(s/86400)+'d ago';
}
function whLoad(){
  const box=document.getElementById('whStatusBox'); if(!box) return;
  fetch('?whstatus=1',{credentials:'same-origin'}).then(r=>r.json()).then(j=>{
    const b=document.getElementById('whStatusBox'); if(!b) return;
    if(!j||!j.ok){ b.innerHTML='<span style="color:var(--bad)">Could not read status.</span>'; return; }
    const dot=(ok,txt)=>`<span style="display:inline-flex;align-items:center;gap:6px"><span style="width:9px;height:9px;border-radius:50%;background:${ok?'var(--good)':'var(--bad)'};flex-shrink:0"></span>${txt}</span>`;
    let live;
    if(!j.configured) live=dot(false,'<b>Secret not set</b> — add <code>webhook_secret</code> to config.php on the server');
    else if(!j.lastAt) live=dot(false,'Secret set — <b>waiting for the first ping from Zoho</b>');
    else live=dot(true,`Live — last ping <b>${whAgo(j.lastAt)}</b>${j.lastEvt?` (<code>${askEsc(j.lastEvt)}</code>)`:''}`);
    const recent=(j.recent||[]).slice(0,5).map(l=>`<div style="font-family:monospace;font-size:10.5px;color:var(--mute);white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${askEsc(l)}</div>`).join('');
    b.innerHTML=`<div style="font-size:12.5px;margin-bottom:6px">${live}</div>
      <div style="display:flex;gap:16px;flex-wrap:wrap;font-size:12px;margin-bottom:${recent?'8px':'0'}">
        <span>Pings today: <b>${j.today||0}</b></span><span>Total logged: <b>${j.total||0}</b></span>
      </div>
      ${recent?`<div style="border-top:1px dashed var(--line);padding-top:6px">${recent}</div>`:''}`;
  }).catch(()=>{ const b=document.getElementById('whStatusBox'); if(b) b.innerHTML='<span style="color:var(--bad)">Status check failed.</span>'; });
}
function benStatusLoad(){
  const el=document.getElementById('benStatus'); if(!el) return;
  fetch('?bencreds=1',{credentials:'same-origin'}).then(r=>r.json()).then(j=>{
    const e=document.getElementById('benStatus'); if(!e) return;
    if(j&&j.ok&&j.configured){ e.innerHTML='<span style="color:var(--good)">● Active</span> — username <b>'+askEsc(j.user)+'</b>. Enter a new password to change it.'; const u=document.getElementById('benUser'); if(u&&!u.value) u.value=j.user; }
    else e.innerHTML='<span style="color:var(--bad)">● Not set up yet</span> — choose a username &amp; password to enable the portal.';
  }).catch(()=>{});
}
function benSave(){
  const u=(document.getElementById('benUser')||{}).value||'';
  const p=(document.getElementById('benPass')||{}).value||'';
  if(!u.trim()){ alert('Enter a username.'); return; }
  fetch('?bencreds=1',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'save',user:u,pass:p})}).then(r=>r.json()).then(j=>{
    if(j&&j.ok){ const pw=document.getElementById('benPass'); if(pw) pw.value=''; benStatusLoad(); alert('Ben Portal access saved.'); }
    else alert((j&&j.error)||'Could not save.');
  }).catch(e=>alert('Error: '+e));
}
function benCopy(btn){ try{ navigator.clipboard.writeText(BEN_URL); const t=btn.textContent; btn.textContent='Copied ✓'; setTimeout(()=>btn.textContent=t,1400);}catch(e){} }
function benPrevLoad(){
  fetch('?benpref=1',{credentials:'same-origin'}).then(r=>r.json()).then(j=>{
    if(!j||!j.ok) return;
    const el=document.getElementById('benPrevChk'); if(el) el.checked=!!j.preview;
    const box=document.getElementById('benAccessBox');
    if(box){ box.innerHTML = j.disabled
      ? `<span style="color:var(--bad);font-weight:700;font-size:12.5px">⛔ Ben's access is DISABLED.</span> <button class="btn" style="width:auto;padding:6px 13px;margin-left:6px" onclick="benSetDisabled(false)">Enable access</button>`
      : `<button class="btn sec" style="width:auto;padding:8px 14px;border-color:var(--bad);color:var(--bad)" onclick="benSetDisabled(true)">⛔ Disable Ben's access</button>`; }
  }).catch(()=>{});
}
function benPrevSave(on){ fetch('?benpref=1',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'save',preview:on?1:0})}).then(r=>r.json()).catch(()=>{}); }
function benSetDisabled(v){ fetch('?benpref=1',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'save',disabled:v?1:0})}).then(r=>r.json()).then(()=>benPrevLoad()).catch(()=>{}); }
function benAiWhen(iso){ try{ return new Date(iso).toLocaleString('en-GB'); }catch(e){ return iso||''; } }
function benAiOpen(){ const m=document.getElementById('benAiModal'); if(m) m.classList.add('open'); benAiFetch(); }
function benAiClose(){ const m=document.getElementById('benAiModal'); if(m) m.classList.remove('open'); }
function benAiFetch(){
  const b=document.getElementById('benAiBody'); if(b) b.innerHTML='<div class="muted" style="padding:8px">Loading…</div>';
  fetch('?benailog=1',{credentials:'same-origin'}).then(r=>r.json()).then(j=>{
    const bb=document.getElementById('benAiBody'); if(!bb) return;
    if(!j||!j.ok){ bb.innerHTML='<div class="muted" style="padding:8px">Could not load the log.</div>'; return; }
    const list=j.log||[];
    if(!list.length){ bb.innerHTML='<div class="muted" style="padding:8px">Ben hasn’t asked the assistant anything yet.</div>'; return; }
    bb.innerHTML=`<div class="muted" style="font-size:10.5px;margin-bottom:8px">${list.length} question${list.length===1?'':'s'}, newest first.</div>`+
      list.map(e=>`<div style="padding:9px 0;border-bottom:1px solid var(--line)">
        <div style="font-size:10px;color:var(--mute)">${askEsc(benAiWhen(e.at))}</div>
        <div style="font-size:13px;font-weight:600;margin-top:2px">${askEsc(e.q)}</div>
        <div style="font-size:12px;color:var(--mute);margin-top:3px;white-space:pre-wrap">${askEsc(e.a)}</div>
      </div>`).join('');
  }).catch(()=>{ const bb=document.getElementById('benAiBody'); if(bb) bb.innerHTML='<div class="muted" style="padding:8px">Could not load the log.</div>'; });
}
let BENDESC = { open:false, loaded:false, loading:false, list:[], msg:'' };
function benAttr(s){ return askEsc(s).replace(/"/g,'&quot;'); }
function benJs(s){ return String(s==null?'':s).replace(/\\/g,'\\\\').replace(/'/g,"\\'"); }
function benDescToggle(){ BENDESC.open=!BENDESC.open; if(BENDESC.open && !BENDESC.loaded && !BENDESC.loading) benDescLoad(); render(); }
function benDescLoad(){
  BENDESC.loading=true; BENDESC.msg=''; if(TAB==='settings') render();
  fetch('?bendesc=1',{credentials:'same-origin'}).then(r=>r.json()).then(j=>{
    BENDESC.loading=false;
    if(j&&j.ok){ BENDESC.list=j.invoices||[]; BENDESC.loaded=true; if(!BENDESC.list.length) BENDESC.msg='No invoices found yet — open the portal once so it loads, or check the company names.'; }
    else BENDESC.msg=(j&&j.error)||'Failed to load.';
    if(TAB==='settings') render();
  }).catch(e=>{ BENDESC.loading=false; BENDESC.msg='Failed to load: '+e; if(TAB==='settings') render(); });
}
function benDescRow(iv){
  return `<div class="bd-row" data-k="${benAttr((iv.number+' '+iv.company+' '+iv.auto+' '+iv.override).toLowerCase())}" style="display:flex;gap:8px;align-items:center;padding:6px 2px;border-bottom:1px solid var(--hair)">
    <div style="flex:0 0 128px;min-width:0">
      <div style="font-weight:600;font-size:11.5px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${askEsc(iv.number)}</div>
      <div class="muted" style="font-size:9.5px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${askEsc(iv.company)} · ${askEsc(iv.year)}</div>
    </div>
    <input type="text" value="${benAttr(iv.override)}" placeholder="${benAttr(iv.auto||'—')}" onchange="benDescSave('${benJs(iv.number)}',this.value,this)" style="flex:1;margin-bottom:0;font-size:12px;padding:6px 9px">
    <div style="flex:0 0 auto;min-width:78px;text-align:right;font-weight:600;font-size:12px;font-variant-numeric:tabular-nums" title="Invoice total">${Math.round(iv.total||0).toLocaleString('en-US')}</div>
    ${iv.id?`<button class="btn sec" style="width:auto;padding:5px 9px;font-size:11px" title="Preview invoice PDF" onclick="benDescPreview('${benJs(iv.id)}','${benJs(iv.number)}')">👁</button>`:''}
    <button class="btn sec" style="width:auto;padding:5px 9px;font-size:11px" title="Use the automatic label" onclick="benDescReset('${benJs(iv.number)}',this)">↺</button>
  </div>`;
}
function benDescPanel(){
  if(BENDESC.loading) return `<div class="muted" style="font-size:12px;margin-top:10px">Loading invoices…</div>`;
  if(!BENDESC.list.length) return `<div class="muted" style="font-size:12px;margin-top:10px">${askEsc(BENDESC.msg||'No invoices.')}</div>`;
  return `<div style="margin-top:10px">
    <input type="text" placeholder="🔍 Filter by invoice #, company or text…" oninput="benDescFilterDom(this.value)" style="margin-bottom:8px">
    <div style="max-height:360px;overflow-y:auto;border:1px solid var(--hair);border-radius:8px;padding:4px 8px">${BENDESC.list.map(benDescRow).join('')}</div>
    <div class="muted" style="font-size:10.5px;margin-top:6px">Type a description and click away to save. The grey text is the automatic label; leave the box blank (or press ↺) to use it. Changes show on Ben's portal immediately.</div>
  </div>`;
}
function benDescFilterDom(v){ v=(v||'').toLowerCase().trim(); document.querySelectorAll('.bd-row').forEach(el=>{ const k=el.getAttribute('data-k')||''; el.style.display=(!v||k.indexOf(v)>=0)?'flex':'none'; }); }
function benDescSave(num,label,el){
  if(el) el.disabled=true;
  fetch('?bendesc=1',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'save',number:num,label:label})}).then(r=>r.json()).then(j=>{
    if(el) el.disabled=false;
    if(j&&j.ok){ const row=(BENDESC.list||[]).find(x=>x.number===num); if(row) row.override=label; if(el){ el.style.borderColor='var(--good)'; setTimeout(()=>{el.style.borderColor='';},1000); } }
    else alert((j&&j.error)||'Could not save.');
  }).catch(e=>{ if(el) el.disabled=false; alert('Error: '+e); });
}
function benDescReset(num,btn){ const el=(btn&&btn.parentNode)?btn.parentNode.querySelector('input'):null; if(el) el.value=''; benDescSave(num,'',el); }
function benDescPreview(id,number){ if(!id) return; window.open('?invpdf='+encodeURIComponent(id),'_blank','noopener'); }
function vPortals(){
  if(!ME.admin) return `<div class="card muted" style="text-align:center">Admins only.</div>`;
  const sec = (t)=>`<div style="font-weight:700;font-size:12.5px;letter-spacing:.3px;margin:2px 0 10px">${t}</div>`;
  return `
  <h2>Portals</h2>
  <div class="muted" style="margin:-6px 0 14px;font-size:12px">Manage the external, login-protected report portals you share with clients.</div>

  <div class="card" style="border-left:4px solid #7C3AED">
    ${sec('🔐 Ben Portal')}
    <div class="muted" style="font-size:12px;margin-bottom:10px">A private, login-protected report for <b>Ben</b> showing <b>all 2025–2026 invoices</b> for the Fabrimetal / CIMMETAL / SteelRwa group. Set the username &amp; password here — leave the password blank to keep the current one.</div>
    <div id="benStatus" class="muted" style="font-size:11.5px;margin-bottom:8px">Checking…</div>
    <div id="benAccessBox" style="margin:0 0 10px"></div>
    <div class="grid2" style="gap:10px">
      <div><label>Username</label><input id="benUser" type="text" autocomplete="off" placeholder="e.g. ben" style="margin-bottom:0"></div>
      <div><label>Password</label><input id="benPass" type="password" autocomplete="new-password" placeholder="leave blank = unchanged" style="margin-bottom:0"></div>
    </div>
    <div style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap;align-items:center">
      <button class="btn" style="width:auto;padding:9px 16px" onclick="benSave()">Save access</button>
      <a class="btn sec" style="width:auto;padding:9px 15px;text-decoration:none" href="index.php?portal=ben" target="_blank" rel="noopener">Open portal ↗</a>
      <button class="btn sec" style="width:auto;padding:9px 14px" onclick="benCopy(this)">Copy link</button>
      <button class="btn sec" style="width:auto;padding:9px 14px" onclick="benAiOpen()">💬 Ask AI log</button>
    </div>
    <label style="display:flex;align-items:center;gap:9px;margin-top:12px;font-size:12.5px;cursor:pointer">
      <input type="checkbox" id="benPrevChk" onchange="benPrevSave(this.checked)" style="width:auto;margin:0"> Let Ben preview invoice PDFs (click an invoice to open it)
    </label>
    <div style="margin-top:12px;border-top:1px dashed var(--line);padding-top:12px">
      <button class="btn sec" style="width:auto;padding:8px 14px" onclick="benDescToggle()">✏️ ${BENDESC.open?'Hide':'Edit'} invoice descriptions</button>
      ${BENDESC.open?benDescPanel():''}
    </div>
  </div>

  <div class="card" style="border-left:4px solid var(--orange)">
    ${sec('📊 Audrey Report')}
    <div class="muted" style="font-size:12px;margin-bottom:10px">A public unpaid-invoice tracker for the Dunhill client list. No password — share the link with Audrey; she can mark items as followed-up without it affecting Zoho.</div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
      <a class="btn" style="width:auto;padding:9px 16px;text-decoration:none" href="audrey.php" target="_blank" rel="noopener">Open Audrey report ↗</a>
      <button class="btn sec" style="width:auto;padding:9px 14px" onclick="copyAudrey(this)">Copy link</button>
    </div>
  </div>`;
}
function vSettings(){
  const fund = CFG.fund, ratePct = +(CFG.rate*100).toFixed(2), vatPct = +((CFG.vat||0)*100).toFixed(2);
  const esc = s=>String(s==null?'':s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  const sec = (t)=>`<div style="font-weight:700;font-size:12.5px;letter-spacing:.3px;margin:2px 0 10px">${t}</div>`;
  return `
  <h2>Settings</h2>
  <div class="muted" style="margin:-6px 0 14px;font-size:12px">Everything here saves to <b>data/settings.json</b> and applies across the app instantly. <b>config.php is never touched.</b></div>

  ${ME.admin?`<div class="card" style="border-left:4px solid var(--orange)">
    ${sec('🔔 Zoho webhooks')}
    <div class="muted" style="font-size:12px;margin-bottom:10px">When Zoho Books changes (payment, invoice, estimate, expense) it pings the app and the caches refresh instantly — so reports and <b>Ask your books</b> are always live. This panel shows whether pings are arriving.</div>
    <div id="whStatusBox" class="muted" style="font-size:12px">Checking…</div>
    <div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap;align-items:center">
      <button class="btn sec" style="width:auto;padding:7px 13px;font-size:12px" onclick="whLoad()">↻ Refresh status</button>
      <span class="muted" style="font-size:10.5px">Endpoint: <code>index.php?hook=zoho</code> (secret lives in config.php)</span>
    </div>
  </div>`:''}

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
              clientSearch:'', clientPicked:false, msg:'', msgErr:false, saving:false, unpaidQ:'',
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

  return `<div class="em-compact">
  <h2>Email a client</h2>
  ${EMAIL.msg && !EMAIL.data && !EMAIL.clientId ? `<div class="warn">${EMAIL.msg}</div>`:''}
  ${(EMAIL.clients||[]).some(c=>c.unpaid)?`<input id="emUnpaidSearch" type="text" autocomplete="off" placeholder="🔍 Quick search clients below…" value="${(EMAIL.unpaidQ||'').replace(/"/g,'&quot;')}" oninput="emailUnpaidSearch(this.value)">`:''}
  <div id="emUnpaidBox">${emUnpaidTableHtml()}</div>
  <label>Search a client</label>
  <input id="emClientSearch" type="text" autocomplete="off" placeholder="Search client name or email…" value="${(EMAIL.clientSearch||'').replace(/"/g,'&quot;')}" oninput="emailClientSearch(this.value)">
  <div id="emClientList">${emClientListHtml()}</div>
  ${body}</div>`;
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
function emailUnpaidSearch(v){ EMAIL.unpaidQ=v; const b=document.getElementById('emUnpaidBox'); if(b) b.innerHTML=emUnpaidTableHtml(); }
function emUnpaidTableHtml(){
  const q=(EMAIL.unpaidQ||'').trim().toLowerCase();
  let list=(EMAIL.clients||[]).filter(c=>c.unpaid);
  if(q) list=list.filter(c=>((c.name||'')+' '+(c.email||'')).toLowerCase().includes(q));
  list=list.slice().sort((a,b)=>{ const sa=a.sent?1:0, sb=b.sent?1:0; if(sa!==sb) return sa-sb; return (b.unpaidTotal||0)-(a.unpaidTotal||0); });
  if(!list.length) return q?`<div class="muted" style="padding:14px;text-align:center;margin-bottom:10px">No unpaid client matches “${qesc(EMAIL.unpaidQ)}”.</div>`:'';
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
    else tag=`<span style="color:var(--orange);font-weight:600;cursor:pointer" onclick="emailSelectClient('${c.id}')">Build →</span>&nbsp;<button class="btn sec" style="white-space:nowrap" title="Mark as sent — strikes row red for 2 weeks" onclick="event.stopPropagation();emailMarkSent('${c.id}','${(c.name||'').replace(/'/g,"")}')">Sent ✓</button>`;
    const usd=!!c.hasUsd;
    const usdBadge = usd ? `<span class="pill" title="${c.usdCount||0} ${c.usdCur||'USD'} invoice${(c.usdCount===1)?'':'s'} · $${fmtn(c.usdTotal||0)}" style="background:#EEF2FE;color:var(--blue);margin-right:7px;vertical-align:middle">${c.usdCur||'USD'}</span>` : '';
    return `<tr${usd?' style="box-shadow:inset 4px 0 0 var(--blue);background:#F4F7FE"':''}>
      <td class="l" onclick="event.stopPropagation()"><input type="checkbox" ${on?'checked':''} onclick="emailBulkToggle('${c.id}')"></td>
      <td class="l cl" style="cursor:pointer;${ss}" onclick="emailSelectClient('${c.id}')">${usdBadge}${c.name||'(no name)'}</td>
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
      <p style="margin:14px 0 0;padding-top:12px;border-top:1px solid #EEF1F5;color:#AEB9C7;font-size:10px;line-height:1.5;font-style:italic">${esc(CFG.stmtFooter||"Prepared and reconciled by the Waitara Holdings Group of Companies Console — Nine One Two Holdings' intelligent finance engine, delivering precise, automated account statements in real time.")}</p>
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

/* ================= Payments (record payment → Zoho Books) ================= */
let PAY = {
  clients:[], loaded:false, loading:false,
  q:'', picked:false, clientId:'', clientName:'',
  invLoading:false, invoices:[], sel:{},
  amount:'', gross:'', whtSel:{}, date:(()=>{ const d=new Date(); return d.toISOString().slice(0,10); })(),
  mode:'bankremittance', ref:'', notes:'', bankCharges:'',
  depositId:'', accounts:[], accsLoaded:false,
  msg:'', msgErr:false, saving:false, done:null
};

function payLoadClients(){
  if(PAY.loaded||PAY.loading) return;
  PAY.loading=true;
  fetch('api/email_clients.php',{credentials:'same-origin'}).then(r=>r.json()).then(j=>{
    PAY.loading=false; PAY.loaded=true;
    PAY.clients = j.ok ? j.clients : [];
    render();
  }).catch(e=>{ PAY.loading=false; PAY.loaded=true; render(); });
}
function payLoadAccounts(){
  if(PAY.accsLoaded) return;
  fetch('api/payment_accounts.php',{credentials:'same-origin'}).then(r=>r.json()).then(j=>{
    PAY.accsLoaded=true;
    PAY.accounts = j.ok ? j.accounts : [];
    if(!PAY.depositId && PAY.accounts.length) PAY.depositId=PAY.accounts[0].id;
    if(TAB==='payments') render();
  }).catch(()=>{ PAY.accsLoaded=true; });
}
function paySearch(v){ PAY.q=v; PAY.picked=false; const el=document.getElementById('payCList'); if(el) el.innerHTML=payCListHtml(); }
function payPickClient(id){
  const c=(PAY.clients||[]).find(x=>x.id===id); if(!c) return;
  PAY.q=c.name; PAY.picked=true; PAY.clientId=id; PAY.clientName=c.name;
  PAY.invoices=[]; PAY.sel={}; PAY.invLoading=true; PAY.done=null; PAY.msg=''; render();
  fetch('api/client_account.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},
    body:JSON.stringify({customer_id:id,from:'',to:''})}).then(r=>r.json()).then(j=>{
    PAY.invLoading=false;
    if(j.ok){ PAY.invoices=(j.invoices||[]).filter(iv=>iv.unpaid); PAY.sel={}; PAY.invoices.forEach(iv=>{ PAY.sel[iv.id||iv.number]=true; }); }
    else { PAY.msg='Could not load invoices: '+(j.error||'failed'); PAY.msgErr=true; }
    payLoadAccounts(); render();
  }).catch(e=>{ PAY.invLoading=false; PAY.msg='Error: '+e; PAY.msgErr=true; render(); });
}
function payCListHtml(){
  const q=(PAY.q||'').trim().toLowerCase();
  if(PAY.picked||!q) return '';
  const matches=(PAY.clients||[]).filter(c=>(c.name||'').toLowerCase().includes(q)).slice(0,12);
  if(!matches.length) return `<div class="muted" style="padding:8px 4px">No client found.</div>`;
  return `<div style="border:1px solid var(--line);border-radius:9px;overflow:hidden;margin-bottom:8px">`
    +matches.map(c=>`<div style="padding:8px 11px;cursor:pointer;border-bottom:1px solid var(--line);font-size:12.5px;font-weight:600;text-transform:uppercase" onmousedown="payPickClient('${c.id}')">${c.name||'(no name)'}</div>`).join('')+'</div>';
}
function paySelTotal(){
  return (PAY.invoices||[]).filter(iv=>PAY.sel[iv.id||iv.number]).reduce((s,iv)=>s+(iv.balance||0),0);
}
const WHT_RATES=[
  {key:'5',  r:0.05, label:'5% — Client generated'},
  {key:'2',  r:0.02, label:'2% — Client generated'},
  {key:'7',  r:0.07, label:'7% — Client generated'},
];
function payWhtToggle(key){
  PAY.whtSel[key]=!PAY.whtSel[key];
  const el=document.getElementById('payWht');
  if(el) el.innerHTML=payWhtHtml(parseFloat(PAY.gross)||paySelTotal());
}
function payApplyWht(net){ PAY.amount=String(net); const el=document.getElementById('payAmount'); if(el) el.value=net; }
function payGrossChange(v){
  PAY.gross=v;
  const el=document.getElementById('payWht'); if(el) el.innerHTML=payWhtHtml(parseFloat(v)||0);
}
function payAmountChange(v){ PAY.amount=v; }
function payWhtHtml(gross){
  if(!gross||gross<=0) return '';
  const vat = (CFG.vat!=null ? CFG.vat : 0.16);
  const base = gross/(1+vat);                 // WHT is levied on the VAT-exclusive value
  let totalWht=0;
  WHT_RATES.forEach(({key,r})=>{ if(PAY.whtSel[key]) totalWht+=base*r; });
  const net=gross-totalWht;
  const rows=WHT_RATES.map(({key,r,label})=>{
    const wht=Math.round(base*r); const on=!!PAY.whtSel[key];
    return `<tr style="${on?'background:#FFF4EB':''}">
      <td style="padding:5px 8px;width:28px"><input type="checkbox" ${on?'checked':''} onchange="payWhtToggle('${key}')"></td>
      <td class="l" style="padding:5px 8px;font-size:11px">${label}</td>
      <td style="padding:5px 8px;text-align:right;font-weight:700;color:var(--bad);font-size:11px;white-space:nowrap">KES ${wht.toLocaleString('en-KE')}</td>
    </tr>`;}).join('');
  const summary=totalWht>0
    ?`<div style="margin-top:6px;padding:8px 11px;background:#FFF4EB;border:1.5px solid #F7C99A;border-radius:8px;display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:8px">
        <span style="font-size:11.5px"><b>Combined WHT:</b> <span style="color:var(--bad);font-weight:700">−KES ${Math.round(totalWht).toLocaleString('en-KE')}</span>&nbsp;&nbsp;<span style="color:var(--mute)">→ client pays:</span> <b style="font-size:13px">KES ${Math.round(net).toLocaleString('en-KE')}</b></span>
        <button onclick="payApplyWht(${Math.round(net)})" style="border:1.5px solid var(--orange);color:var(--orange);background:#fff;border-radius:7px;padding:4px 12px;font-size:11px;font-weight:700;cursor:pointer;font-family:inherit">↓ Use KES ${Math.round(net).toLocaleString('en-KE')}</button>
      </div>`
    :`<div class="muted" style="margin-top:4px;font-size:10.5px">Tick one or more WHT types — combined deduction and net amount appear here.</div>`;
  return `<div style="margin:6px 0">
    <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--mute);margin-bottom:4px">KRA Withholding Tax — tick all that apply (combinations allowed)</div>
    <div class="rptwrap"><table class="rpt" style="font-size:11px">
      <thead><tr><th style="padding:5px 8px;width:28px"></th><th class="l" style="padding:5px 8px">WHT type</th><th style="padding:5px 8px;text-align:right">Deduction on ex-VAT KES ${Math.round(base).toLocaleString('en-KE')}</th></tr></thead>
      <tbody>${rows}</tbody>
    </table></div>
    <div class="muted" style="font-size:10px;margin-top:4px">WHT is calculated on the VAT-exclusive value (gross ÷ ${(1+vat).toLocaleString('en-KE',{maximumFractionDigits:2})}) = KES ${Math.round(base).toLocaleString('en-KE')}, per KRA rules.</div>
    ${summary}
  </div>`;
}

function payWhtTotal(){
  const gross=parseFloat(PAY.gross)||0;
  if(gross<=0) return 0;
  const vat=(CFG.vat!=null?CFG.vat:0.16);
  const base=gross/(1+vat);
  let t=0; WHT_RATES.forEach(({key,r})=>{ if(PAY.whtSel[key]) t+=base*r; });
  return Math.round(t);
}
async function paySubmit(){
  if(!PAY.clientId){ PAY.msg='Select a client first.'; PAY.msgErr=true; render(); return; }
  const amt=parseFloat(PAY.amount||PAY.gross)||0;
  if(amt<=0){ PAY.msg='Enter the amount received.'; PAY.msgErr=true; render(); return; }
  const selInvs=(PAY.invoices||[]).filter(iv=>PAY.sel[iv.id||iv.number]);
  if(!selInvs.length){ PAY.msg='Select at least one invoice to apply the payment to.'; PAY.msgErr=true; render(); return; }
  PAY.saving=true; PAY.msg=''; render();
  // Per invoice Zoho clears the balance via amount_applied (cash) + tax_amount_withheld (WHT).
  // Allocate WHT first, then cash, across the selected invoices up to each balance.
  const wht=payWhtTotal();
  let rCash=amt, rWht=wht;
  const invoices=[];
  selInvs.forEach(iv=>{
    const bal=iv.balance||0;
    if(rCash<=0.005 && rWht<=0.005) return;
    const applyTax=Math.min(rWht, bal);
    const applyCash=Math.min(rCash, Math.max(0, bal-applyTax));
    rWht-=applyTax; rCash-=applyCash;
    const row={ invoice_id:iv.id, amount_applied:Math.round(applyCash*100)/100 };
    if(applyTax>0) row.tax_amount_withheld=Math.round(applyTax*100)/100;
    invoices.push(row);
  });
  try{
    const r=await fetch('api/payment_record.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},
      body:JSON.stringify({ customer_id:PAY.clientId, amount:amt, date:PAY.date, mode:PAY.mode,
        reference:PAY.ref, notes:PAY.notes, bank_charges:parseFloat(PAY.bankCharges)||0,
        deposit_account_id:PAY.depositId, invoices })});
    const j=await r.json();
    PAY.saving=false;
    if(j.ok){ PAY.done=j.payment; PAY.msg='Payment recorded in Zoho Books — #'+j.payment.payment_number; PAY.msgErr=false; PAY.amount=''; PAY.gross=''; PAY.invoices=[]; PAY.sel={}; PAY.picked=false; PAY.q=''; PAY.clientId=''; }
    else { PAY.msg='Error: '+(j.error||'failed'); PAY.msgErr=true; }
  }catch(e){ PAY.saving=false; PAY.msg='Error: '+e; PAY.msgErr=true; }
  render();
}

function payUnpaidTableHtml(){
  const list=(PAY.clients||[]).filter(c=>c.unpaid).sort((a,b)=>(b.unpaidTotal||0)-(a.unpaidTotal||0));
  if(!list.length) return PAY.loaded?`<div class="muted" style="padding:8px 4px">No clients with unpaid invoices.</div>`:'';
  let tot=0, cnt=0;
  const rows=list.map(c=>{
    tot+=(c.unpaidTotal||0); cnt+=(c.unpaidCount||0);
    const active=PAY.clientId===c.id;
    const usd=!!c.hasUsd;
    const usdBadge=usd?`<span style="background:#EEF2FE;color:var(--blue);border-radius:4px;padding:1px 5px;font-size:9px;font-weight:700;margin-right:5px">${c.usdCur||'USD'}</span>`:'';
    return `<tr style="${active?'background:#FFF4EB;box-shadow:inset 3px 0 0 var(--orange)':''}" onclick="payPickClient('${c.id}')" style="cursor:pointer">
      <td class="l cl" style="padding:5px 8px;cursor:pointer">${usdBadge}${c.name||''}</td>
      <td style="padding:5px 8px;text-align:right">${c.unpaidCount||0}</td>
      <td style="padding:5px 8px;text-align:right;color:var(--bad);font-weight:700;white-space:nowrap">KES ${Math.round(c.unpaidTotal||0).toLocaleString('en-KE')}</td>
      <td style="padding:5px 8px;text-align:right"><button class="btn sec" onclick="event.stopPropagation();payPickClient('${c.id}')" style="white-space:nowrap">Pay →</button></td>
    </tr>`;}).join('');
  return `<div class="rptwrap" style="margin-bottom:10px;max-height:220px;overflow-y:auto">
    <table class="rpt" style="font-size:11px">
      <thead><tr>
        <th class="l" style="padding:5px 8px">Client</th>
        <th style="padding:5px 8px;text-align:right">Invoices</th>
        <th style="padding:5px 8px;text-align:right">Amount due</th>
        <th style="padding:5px 8px"></th>
      </tr></thead>
      <tbody>${rows}</tbody>
      <tfoot><tr class="tot">
        <td style="padding:5px 8px">Total · ${list.length} clients</td>
        <td style="padding:5px 8px;text-align:right">${cnt}</td>
        <td style="padding:5px 8px;text-align:right;color:var(--bad)">KES ${Math.round(tot).toLocaleString('en-KE')}</td>
        <td></td>
      </tr></tfoot>
    </table>
  </div>`;
}

function vPayments(){
  const today=new Date().toISOString().slice(0,10);
  const selTotal=paySelTotal();
  const gross=parseFloat(PAY.gross)||selTotal;

  let invoiceBlock='';
  if(PAY.invLoading){
    invoiceBlock=`<div class="card muted" style="padding:10px 13px">Loading invoices for ${PAY.clientName}…</div>`;
  } else if(PAY.clientId && PAY.invoices.length===0 && !PAY.invLoading){
    invoiceBlock=`<div class="card muted" style="padding:10px 13px">No unpaid invoices for ${PAY.clientName}.</div>`;
  } else if(PAY.invoices.length){
    const invRows=PAY.invoices.map(iv=>{
      const key=iv.id||iv.number;
      const on=!!PAY.sel[key];
      const bal=iv.balance||0;
      const sent=on?'text-decoration:line-through;color:#aaa':'';
      return `<tr style="${on?'background:#F7FDF9':''}">
        <td class="l" style="padding:5px 8px"><input type="checkbox" ${on?'checked':''} onchange="PAY.sel['${key}']=this.checked;payGrossChange(document.getElementById('payGross')?.value||'');render()"></td>
        <td class="l" style="padding:5px 8px;font-weight:700;font-size:11.5px">${iv.number||key}</td>
        <td style="padding:5px 8px;font-size:10.5px;color:var(--mute)">${iv.date||''}</td>
        <td style="padding:5px 8px;font-size:10.5px;color:var(--mute)">${iv.dueDate||iv.due_date||''}</td>
        <td style="padding:5px 8px;text-align:right;font-weight:700;font-size:11.5px;color:var(--bad)">KES ${Math.round(bal).toLocaleString('en-KE')}</td>
      </tr>`;}).join('');
    invoiceBlock=`<div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--mute);margin:10px 0 4px">Unpaid invoices — tick those this payment covers</div>
      <div class="rptwrap" style="margin-bottom:8px">
        <table class="rpt" style="font-size:11px">
          <thead><tr>
            <th class="l" style="padding:5px 8px"><input type="checkbox" title="Select all" onchange="PAY.invoices.forEach(iv=>{PAY.sel[iv.id||iv.number]=this.checked});render()"></th>
            <th class="l" style="padding:5px 8px">Invoice</th><th class="l" style="padding:5px 8px">Date</th><th class="l" style="padding:5px 8px">Due</th><th style="padding:5px 8px;text-align:right">Balance</th>
          </tr></thead>
          <tbody>${invRows}</tbody>
          <tfoot><tr class="tot"><td colspan="4" style="padding:5px 8px;text-align:right">Selected total</td><td style="padding:5px 8px;text-align:right;color:var(--bad)">KES ${Math.round(selTotal).toLocaleString('en-KE')}</td></tr></tfoot>
        </table>
      </div>`;
  }

  const modeOpts=[['bankremittance','MPESA / Bank Transfer'],['cash','Cash'],['check','Cheque'],['creditcard','Credit Card'],['others','Other']];
  const accountSel=PAY.accounts.length
    ? `<select id="payDepositSel" onchange="PAY.depositId=this.value" style="margin-bottom:0">${PAY.accounts.map(a=>`<option value="${a.id}" ${a.id===PAY.depositId?'selected':''}>${a.name}</option>`).join('')}</select>`
    : `<select id="payDepositSel" style="margin-bottom:0"><option value="">Loading accounts…</option></select>`;

  return `<div class="em-compact">
  <h2>💳 Record a Payment</h2>
  ${PAY.done?`<div class="ok" style="padding:10px 13px;margin-bottom:10px;border-radius:9px">
    ✓ Payment <b>#${PAY.done.payment_number}</b> recorded in Zoho Books — KES ${Math.round(PAY.done.amount||0).toLocaleString('en-KE')} on ${PAY.done.date||''}
    <span style="display:block;font-size:10.5px;margin-top:3px;color:var(--good)">Invoice balances updated in Zoho. Select another client below to record a new payment.</span>
  </div>`:''}
  <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--mute);margin-bottom:4px">
    ${PAY.loading?'Loading clients…':'All clients with unpaid invoices — click a row to record payment'}
  </div>
  ${payUnpaidTableHtml()}
  <label style="margin-top:4px">Or search by name</label>
  <input id="paySearch" type="text" autocomplete="off" placeholder="🔍 Search client name…" value="${(PAY.q||'').replace(/"/g,'&quot;')}" oninput="paySearch(this.value)">
  <div id="payCList">${payCListHtml()}</div>

  ${invoiceBlock}

  ${PAY.clientId && !PAY.invLoading && PAY.invoices.length ? `
  <div style="border-top:1px solid var(--line);padding-top:10px;margin-top:2px">
    <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--mute);margin-bottom:4px">Payment details</div>
    <div class="grid2" style="gap:8px;margin-bottom:0">
      <div>
        <label>Invoice gross (for WHT calc)</label>
        <input id="payGross" type="number" min="0" step="0.01" placeholder="e.g. ${Math.round(selTotal)||''}" value="${PAY.gross||''}" oninput="payGrossChange(this.value)" style="margin-bottom:0">
      </div>
      <div>
        <label>Amount received (KES) *</label>
        <input id="payAmount" type="number" min="0" step="0.01" placeholder="Actual amount received" value="${PAY.amount||''}" oninput="payAmountChange(this.value)" style="margin-bottom:0">
      </div>
    </div>
    <div id="payWht" style="margin:6px 0">${payWhtHtml(gross)}</div>
    <div class="grid2" style="gap:8px">
      <div>
        <label>Payment date *</label>
        <input type="date" value="${PAY.date||today}" onchange="PAY.date=this.value" style="margin-bottom:0">
      </div>
      <div>
        <label>Bank charges (if any)</label>
        <input type="number" min="0" step="0.01" placeholder="0" value="${PAY.bankCharges||''}" oninput="PAY.bankCharges=this.value" style="margin-bottom:0">
      </div>
    </div>
    <div class="grid2" style="gap:8px;margin-top:6px">
      <div>
        <label>Payment mode</label>
        <select onchange="PAY.mode=this.value" style="margin-bottom:0">${modeOpts.map(([v,l])=>`<option value="${v}" ${v===PAY.mode?'selected':''}>${l}</option>`).join('')}</select>
      </div>
      <div>
        <label>Deposit to</label>
        ${accountSel}
      </div>
    </div>
    <div class="grid2" style="gap:8px;margin-top:6px">
      <div>
        <label>Reference # / Transaction ID</label>
        <input type="text" placeholder="e.g. MPESA ref, cheque #" value="${PAY.ref||''}" oninput="PAY.ref=this.value" style="margin-bottom:0">
      </div>
      <div>
        <label>Notes</label>
        <input type="text" placeholder="Optional notes" value="${PAY.notes||''}" oninput="PAY.notes=this.value" style="margin-bottom:0">
      </div>
    </div>
    ${PAY.msg?`<div class="${PAY.msgErr?'warn':'ok'}" style="margin-top:8px;padding:8px 11px;border-radius:8px;font-size:12px">${PAY.msg}</div>`:''}
    <button class="btn" style="margin-top:10px" onclick="paySubmit()" ${PAY.saving?'disabled':''}>${PAY.saving?'Saving to Zoho…':'💾 Save payment to Zoho Books'}</button>
    <div class="muted" style="margin-top:6px">This records the payment directly in Zoho Books and updates the invoice balance immediately.</div>
  </div>` : (PAY.msg&&!PAY.clientId?`<div class="warn" style="margin-top:8px">${PAY.msg}</div>`:'')}
  </div>`;
}
/* ================= end Payments ================= */

/* ================= Bulk Mark Paid ================= */
let BULK = {
  loaded:false, loading:false, invoices:[], sel:{}, wht:{}, q:'', usd:false,
  date:(()=>{ const d=new Date(); return d.toISOString().slice(0,10); })(),
  mode:'bankremittance', ref:'',
  processing:false, done:[], msg:'', err:false
};

function bulkIvNet(iv){
  const w=BULK.wht[iv.id]||{};
  const rate=(w.r5?0.05:0)+(w.r2?0.02:0)+(w.r7?0.07:0);
  const vat=(CFG.vat!=null?CFG.vat:0.16);
  const base=iv.balance/(1+vat);              // WHT on the VAT-exclusive value, per KRA
  const deduct=Math.round(base*rate);
  return {gross:Math.round(iv.balance), deduct, net:Math.round(iv.balance)-deduct};
}

function bulkLoad(){
  if(BULK.loading) return;
  BULK.loading=true; BULK.msg=''; BULK.err=false; render();
  fetch('api/unpaid_invoices.php',{credentials:'same-origin'})
    .then(r=>r.json()).then(j=>{
      BULK.loading=false; BULK.loaded=true;
      BULK.invoices=j.ok?(j.invoices||[]):[];
      BULK.sel={}; BULK.wht={};
      BULK.invoices.forEach(iv=>{ BULK.sel[iv.id]=false; BULK.wht[iv.id]={r5:false,r2:false,r7:false}; });
      if(TAB==='bulkpay') render();
    }).catch(e=>{ BULK.loading=false; BULK.msg='Error: '+e; BULK.err=true; if(TAB==='bulkpay') render(); });
}

function bulkToggleClient(custId, on){
  BULK.invoices.filter(iv=>iv.customer_id===custId).forEach(iv=>{ BULK.sel[iv.id]=on; });
  render();
}

function bulkToggleAll(on){
  BULK.invoices.forEach(iv=>{ BULK.sel[iv.id]=on; });
  render();
}

function bulkToggleVisible(on){
  const q=(BULK.q||'').toLowerCase().trim();
  let visible=BULK.invoices||[];
  if(BULK.usd) visible=visible.filter(iv=>iv.currency&&iv.currency!=='KES');
  if(q) visible=visible.filter(iv=>iv.customer_name.toLowerCase().includes(q)||iv.number.toLowerCase().includes(q));
  visible.forEach(iv=>{ BULK.sel[iv.id]=on; });
  render();
}

async function bulkProcess(){
  const selInvs=(BULK.invoices||[]).filter(iv=>BULK.sel[iv.id]);
  if(!selInvs.length){ BULK.msg='Select at least one invoice.'; BULK.err=true; render(); return; }
  const byClient={};
  selInvs.forEach(iv=>{
    if(!byClient[iv.customer_id]) byClient[iv.customer_id]={name:iv.customer_name,invoices:[]};
    byClient[iv.customer_id].invoices.push(iv);
  });
  BULK.processing=true; BULK.done=[]; BULK.msg=''; BULK.err=false; render();
  for(const [custId,g] of Object.entries(byClient)){
    const nets=g.invoices.map(iv=>({iv,n:bulkIvNet(iv)}));
    const amount=nets.reduce((s,{n})=>s+n.net,0);       // cash actually received (net of WHT)
    const payload={
      customer_id:custId,
      amount:Math.round(amount*100)/100,
      date:BULK.date,
      mode:BULK.mode,
      reference:BULK.ref,
      deposit_account_id:PAY.depositId||'',
      // per invoice: cash applied + tax withheld → Zoho clears the invoice, WHT booked as TDS
      invoices:nets.map(({iv,n})=>{ const r={invoice_id:iv.id,amount_applied:n.net}; if(n.deduct>0) r.tax_amount_withheld=n.deduct; return r; })
    };
    try{
      const r=await fetch('api/payment_record.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)});
      const j=await r.json();
      BULK.done.push({client:g.name,ok:j.ok,ref:j.ok?'#'+(j.payment?.payment_number||''):'',msg:j.ok?'':( j.error||'failed'),amount});
    }catch(e){
      BULK.done.push({client:g.name,ok:false,ref:'',msg:String(e),amount});
    }
    render();
  }
  const okClients=new Set(Object.keys(byClient).filter((_,i)=>BULK.done[i]?.ok));
  BULK.invoices=BULK.invoices.filter(iv=>!BULK.sel[iv.id]||!okClients.has(iv.customer_id));
  BULK.sel={}; BULK.wht={};
  BULK.invoices.forEach(iv=>{ BULK.sel[iv.id]=false; BULK.wht[iv.id]={r5:false,r2:false,r7:false}; });
  BULK.processing=false;
  render();
}

function vBulkPay(){
  const today=new Date().toISOString().slice(0,10);
  const q=(BULK.q||'').toLowerCase().trim();
  const usdCount=(BULK.invoices||[]).filter(iv=>iv.currency&&iv.currency!=='KES').length;
  let visible=BULK.invoices||[];
  if(BULK.usd) visible=visible.filter(iv=>iv.currency&&iv.currency!=='KES');
  if(q) visible=visible.filter(iv=>iv.customer_name.toLowerCase().includes(q)||iv.number.toLowerCase().includes(q));
  const selInvs=visible.filter(iv=>BULK.sel[iv.id]);
  const selClientSet=new Set(selInvs.map(iv=>iv.customer_id));
  const selClients=selClientSet.size;
  const totGross=selInvs.reduce((s,iv)=>s+bulkIvNet(iv).gross,0);
  const totDeduct=selInvs.reduce((s,iv)=>s+bulkIvNet(iv).deduct,0);
  const totNet=totGross-totDeduct;

  let tableHtml='';
  if(BULK.loading){
    tableHtml=`<div class="card muted" style="padding:12px">Loading all unpaid invoices from Zoho…</div>`;
  } else if(BULK.loaded && !BULK.invoices.length){
    tableHtml=`<div class="ok" style="padding:12px;border-radius:8px">🎉 No unpaid invoices — all clear!</div>`;
  } else if(BULK.invoices.length){
    const sorted=[...visible].sort((a,b)=>a.customer_name.localeCompare(b.customer_name)||a.date.localeCompare(b.date));
    let lastCust='';
    const rows=sorted.map(iv=>{
      const on=!!BULK.sel[iv.id];
      const overdue=iv.due_date&&iv.due_date<today;
      const newCust=iv.customer_id!==lastCust;
      if(newCust) lastCust=iv.customer_id;
      const clientInvs=visible.filter(x=>x.customer_id===iv.customer_id);
      const clientAllOn=clientInvs.length>0&&clientInvs.every(x=>BULK.sel[x.id]);
      const w=BULK.wht[iv.id]||{};
      const {gross,deduct,net}=bulkIvNet(iv);
      const clientHdr=newCust?`<tr style="background:#F4F6FA;border-top:2px solid var(--line)">
        <td style="padding:5px 8px"><input type="checkbox" title="Select all" ${clientAllOn?'checked':''} onchange="bulkToggleClient('${iv.customer_id}',this.checked)"></td>
        <td class="l cl" colspan="5" style="padding:5px 8px;font-size:11.5px">${iv.customer_name} <span style="font-weight:400;color:var(--mute);font-size:10px">(${clientInvs.length})</span></td>
      </tr>`:'';
      return clientHdr+`<tr style="${on?'background:#F0FDF4;':''}cursor:default">
        <td style="padding:4px 8px 4px 22px"><input type="checkbox" ${on?'checked':''} onchange="BULK.sel['${iv.id}']=this.checked;render()"></td>
        <td class="l" style="padding:4px 8px;font-size:11px;font-weight:600">${iv.number}${iv.currency&&iv.currency!=='KES'?`&nbsp;<span style="background:#EEF2FE;color:var(--blue);border-radius:4px;padding:1px 5px;font-size:9px;font-weight:700;vertical-align:middle">${iv.currency}</span>`:''}
          <span style="display:block;font-weight:400;font-size:9.5px;color:${overdue?'var(--bad)':'var(--mute)'}">${iv.date}${overdue?' · overdue':''}</span></td>
        <td style="padding:4px 8px;text-align:right;font-size:11px">${gross.toLocaleString('en-KE')}</td>
        <td style="padding:4px 8px;white-space:nowrap;text-align:center">
          <label style="font-size:9.5px;cursor:pointer;margin-right:6px;color:${w.r5?'var(--orange)':'var(--mute)'}"><input type="checkbox" ${w.r5?'checked':''} onchange="BULK.wht['${iv.id}'].r5=this.checked;render()"> 5%</label><label style="font-size:9.5px;cursor:pointer;margin-right:6px;color:${w.r2?'var(--orange)':'var(--mute)'}"><input type="checkbox" ${w.r2?'checked':''} onchange="BULK.wht['${iv.id}'].r2=this.checked;render()"> 2%</label><label style="font-size:9.5px;cursor:pointer;color:${w.r7?'var(--orange)':'var(--mute)'}"><input type="checkbox" ${w.r7?'checked':''} onchange="BULK.wht['${iv.id}'].r7=this.checked;render()"> 7%</label>
        </td>
        <td style="padding:4px 8px;text-align:right;font-size:10.5px;color:var(--bad)">${deduct>0?'−'+deduct.toLocaleString('en-KE'):'—'}</td>
        <td style="padding:4px 8px;text-align:right;font-weight:700;font-size:11px;color:${deduct>0?'var(--good)':'inherit'}">${net.toLocaleString('en-KE')}</td>
      </tr>`;
    }).join('');
    const allVisOn=visible.length>0&&visible.every(iv=>BULK.sel[iv.id]);
    tableHtml=`<div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;flex-wrap:wrap">
      <input id="bulkQ" type="text" autocomplete="off" placeholder="🔍 Search client or invoice number…" value="${(BULK.q||'').replace(/"/g,'&quot;')}" oninput="BULK.q=this.value;render()" style="flex:1;min-width:160px;margin-bottom:0;min-height:unset!important;padding:7px 10px!important;font-size:12px!important">
      ${usdCount?`<button onclick="BULK.usd=!BULK.usd;render()" style="border:1.5px solid ${BULK.usd?'var(--blue)':'var(--line)'};background:${BULK.usd?'#EEF2FE':'#fff'};color:${BULK.usd?'var(--blue)':'var(--mute)'};border-radius:7px;padding:4px 11px;font-size:11px;font-weight:700;cursor:pointer;white-space:nowrap;min-height:unset">💵 USD only${BULK.usd?` (${visible.length})`:`&nbsp;·&nbsp;${usdCount}`}</button>`:''}
      ${q||BULK.usd?`<button class="btn sec" onclick="BULK.q='';BULK.usd=false;render()" style="white-space:nowrap;padding:5px 10px;font-size:11px;min-height:unset">✕ Clear</button>`:''}
      <span style="font-size:10.5px;color:var(--mute);white-space:nowrap">${visible.length} of ${BULK.invoices.length}</span>
    </div>
    <div class="rptwrap" style="margin-bottom:10px;max-height:420px;overflow-y:auto">
      <table class="rpt" style="font-size:11px;width:100%">
        <thead><tr>
          <th style="padding:5px 8px;width:28px"><input type="checkbox" title="Select all visible" ${allVisOn?'checked':''} onchange="bulkToggleVisible(this.checked)"></th>
          <th class="l" style="padding:5px 8px">Invoice · Date</th>
          <th style="padding:5px 8px;text-align:right">Gross</th>
          <th style="padding:5px 8px;text-align:center">WHT</th>
          <th style="padding:5px 8px;text-align:right">Deduct</th>
          <th style="padding:5px 8px;text-align:right">Net</th>
        </tr></thead>
        <tbody>${rows}</tbody>
        ${selInvs.length?`<tfoot>
          <tr class="tot">
            <td colspan="2" style="padding:5px 8px;text-align:right;font-size:10.5px">${selInvs.length} invoice${selInvs.length!==1?'s':''} · ${selClients} client${selClients!==1?'s':''}</td>
            <td style="padding:5px 8px;text-align:right">${totGross.toLocaleString('en-KE')}</td>
            <td></td>
            <td style="padding:5px 8px;text-align:right;color:var(--bad)">${totDeduct>0?'−'+totDeduct.toLocaleString('en-KE'):'—'}</td>
            <td style="padding:5px 8px;text-align:right;font-size:12px;font-weight:700;color:var(--good)">${totNet.toLocaleString('en-KE')}</td>
          </tr>
          ${totDeduct>0?`<tr><td colspan="6" style="padding:3px 8px 7px;text-align:right">
            <span style="font-size:11px;background:#FFF4EB;color:var(--orange);border:1.5px solid #F7C99A;border-radius:7px;padding:4px 13px;font-weight:700">
              Expected to receive: KES ${totNet.toLocaleString('en-KE')} &nbsp;·&nbsp; WHT −KES ${totDeduct.toLocaleString('en-KE')}
            </span>
          </td></tr>`:''}
        </tfoot>`:''}
      </table>
    </div>`;
  }

  let resultsHtml='';
  if(BULK.done.length){
    resultsHtml=`<div class="card" style="padding:10px 13px;margin-bottom:10px">
      <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--mute);margin-bottom:6px">Results</div>
      ${BULK.done.map(d=>`<div style="padding:5px 0;border-bottom:1px solid var(--line);display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:8px">
        <span style="font-size:11.5px">${d.ok?'✅':'❌'} <b>${d.client}</b>${d.ref?' <span style="color:var(--good);font-size:10.5px">'+d.ref+'</span>':''}</span>
        <span style="font-size:11px;font-weight:700;color:${d.ok?'var(--good)':'var(--bad)'}">${d.ok?'KES '+Math.round(d.amount).toLocaleString('en-KE'):d.msg}</span>
      </div>`).join('')}
    </div>`;
  }

  const modeOpts=[['bankremittance','MPESA / Bank Transfer'],['cash','Cash'],['check','Cheque'],['creditcard','Credit Card'],['others','Other']];
  const accountSel=PAY.accounts.length
    ?`<select onchange="PAY.depositId=this.value" style="margin-bottom:0">${PAY.accounts.map(a=>`<option value="${a.id}" ${a.id===PAY.depositId?'selected':''}>${a.name}</option>`).join('')}</select>`
    :`<select style="margin-bottom:0"><option value="">Loading accounts…</option></select>`;
  const canProcess=selInvs.length>0&&!BULK.processing;

  return `<div class="em-compact">
  <h2>⚡ Bulk Mark Invoices Paid</h2>
  ${BULK.msg?`<div class="card" style="color:${BULK.err?'var(--bad)':'var(--good)'};padding:8px 13px;margin-bottom:8px">${BULK.msg}</div>`:''}
  ${resultsHtml}
  ${tableHtml}
  ${(BULK.loaded&&BULK.invoices.length)||BULK.done.length?`
  <div style="border-top:1px solid var(--line);padding-top:10px;margin-top:4px">
    <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--mute);margin-bottom:6px">Payment settings — applied to all selected</div>
    <div class="grid2" style="gap:8px">
      <div><label>Payment date *</label><input type="date" value="${BULK.date}" onchange="BULK.date=this.value" style="margin-bottom:0"></div>
      <div><label>Payment mode</label><select onchange="BULK.mode=this.value" style="margin-bottom:0">${modeOpts.map(([v,l])=>`<option value="${v}" ${v===BULK.mode?'selected':''}>${l}</option>`).join('')}</select></div>
    </div>
    <div class="grid2" style="gap:8px;margin-top:6px">
      <div><label>Deposit to</label>${accountSel}</div>
      <div><label>Reference # (optional)</label><input type="text" placeholder="e.g. MPESA ref, batch #" value="${BULK.ref}" oninput="BULK.ref=this.value" style="margin-bottom:0"></div>
    </div>
    <button class="btn" onclick="bulkProcess()" style="margin-top:12px;width:100%" ${canProcess?'':'disabled'}>
      ${BULK.processing?'⏳ Processing payments in Zoho…':`⚡ Process ${selInvs.length} Invoice${selInvs.length!==1?'s':''} for ${selClients} Client${selClients!==1?'s':''} — KES ${totNet.toLocaleString('en-KE')}${totDeduct>0?' (after WHT)':''}`}
    </button>
    <div class="muted" style="font-size:10.5px;margin-top:6px">One payment per client posted to Zoho Books. Net amount (after WHT) applied per invoice.</div>
  </div>`:''}
  </div>`;
}
/* ================= end Bulk Mark Paid ================= */

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
function todoAddFromEmail(idx){ tmOpen(idx); }
/* ---- New-task popup (from an email row) ---- */
let TM={open:false,title:'',notes:'',source:'',ref:'',assignee:'',busy:false,msg:'',err:false};
function tmEsc(s){ return String(s==null?'':s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function tmBody(){
  const users=(TASK.users||[]);
  return `<label>Task title</label>
    <input type="text" id="tmTitle" value="${tmEsc(TM.title)}" oninput="TM.title=this.value" placeholder="What needs doing?">
    <label>Notes</label>
    <textarea id="tmNotes" style="min-height:96px" oninput="TM.notes=this.value">${tmEsc(TM.notes)}</textarea>
    <label>Assign to (optional)</label>
    <select id="tmAssignee" onchange="TM.assignee=this.value" style="margin-bottom:0">
      <option value="">— no one —</option>
      ${users.map(u=>`<option value="${tmEsc(u.name)}" ${TM.assignee===u.name?'selected':''}>${tmEsc(u.name)}</option>`).join('')}
    </select>
    ${TM.msg?`<div class="${TM.err?'warn':'ok'}" style="margin:12px 0 0">${tmEsc(TM.msg)}</div>`:''}
    <button class="btn" id="tmBtn" style="margin-top:14px" onclick="tmSave()" ${TM.busy?'disabled':''}>${TM.busy?'Adding…':'+ Add task'}</button>`;
}
function tmRender(){ const b=document.getElementById('taskModalBody'); if(b) b.innerHTML=tmBody(); }
function tmOpen(idx){ const m=TASK.inbox[idx]; if(!m) return;
  TM={open:true,title:m.subject||'',notes:(m.from?('From: '+m.from+'\n'):'')+(m.summary||''),source:'email',ref:m.id,assignee:'',busy:false,msg:'',err:false};
  const mo=document.getElementById('taskModal'); if(mo) mo.classList.add('open'); document.body.style.overflow='hidden'; tmRender();
  setTimeout(()=>{ const t=document.getElementById('tmTitle'); if(t) t.focus(); },50);
}
function tmClose(){ TM.open=false; const mo=document.getElementById('taskModal'); if(mo) mo.classList.remove('open'); document.body.style.overflow=''; }
function tmSave(){
  if(!String(TM.title||'').trim()){ TM.msg='Add a task title.'; TM.err=true; tmRender(); return; }
  TM.busy=true; TM.msg=''; tmRender();
  const assignees=[]; if(TM.assignee){ const u=todoUserByName(TM.assignee); if(u) assignees.push({name:u.name,email:u.email}); }
  fetch('api/tasks.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},
    body:JSON.stringify({action:'add',title:TM.title,notes:TM.notes,source:TM.source,source_ref:TM.ref,assignees})})
  .then(r=>r.json()).then(j=>{ TM.busy=false;
    if(j.ok){ todoRefreshTasks(j); TASK.msg='Added “'+TM.title+'” to your list.'; TASK.msgErr=false; tmClose(); render(); }
    else { TM.msg=j.error||'Failed.'; TM.err=true; tmRender(); } })
  .catch(e=>{ TM.busy=false; TM.msg='Error: '+e; TM.err=true; tmRender(); });
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

function todoCardToggle(id){ TASK.open=TASK.open||{}; TASK.open[id]=!TASK.open[id]; render(); }
function todoTaskCard(t){
  const esc=s=>String(s==null?'':s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  const done=t.status==='done';
  const open=!!(TASK.open&&TASK.open[t.id]);
  const as=t.assignees||[];
  const tickedCount=as.filter(a=>a.ticked).length;
  const avatars = as.slice(0,4).map(a=>dshAvatar(a.name||a.email,24)).join('') + (as.length>4?`<span class="tk-more">+${as.length-4}</span>`:'');
  const sent = t.sent_at ? `<span class="tk-sent" title="Emailed to assignees">✓ sent</span>` : '';
  const head = `<div class="tk-row" onclick="todoCardToggle(${t.id})">
      <span class="tk-check" title="${done?'Mark not done':'Mark done'}" onclick="event.stopPropagation();todoToggle(${t.id},${done?'false':'true'})">${done?'✓':''}</span>
      <div class="tk-main">
        <div class="tk-title">${esc(t.title)}</div>
        <div class="tk-sub">${t.subject?`<span class="tk-tag">${esc(t.subject)}</span>`:''}${as.length?'':'<span class="muted" style="font-size:10.5px">Unassigned</span>'}${sent}</div>
      </div>
      <div class="tk-people">${avatars}</div>
      <span class="tk-caret">${open?'▴':'▾'}</span>
    </div>`;
  if(!open) return `<div class="tkcard${done?' done':''}">${head}</div>`;

  const adminBody = ME.admin ? `
    <label style="margin-top:0">Subject / short label</label>
    <input type="text" placeholder="e.g. Site survey" style="width:100%;font-size:12px;font-weight:600" value="${esc(t.subject||'')}" onchange="todoField(${t.id},'subject',this.value)">
    <label style="margin-top:12px">Notes</label>
    <textarea placeholder="Notes…" style="width:100%;min-height:54px;font-size:12px" onchange="todoField(${t.id},'notes',this.value)">${esc(t.notes||'')}</textarea>
    <div class="muted" style="font-size:10.5px;margin-top:8px">Assignees (tick who to send to):</div>
    ${todoAssigneeChips(t)}
    <div class="row" style="gap:8px;margin-top:12px;flex-wrap:wrap;align-items:center">
      <button class="btn" style="width:auto;padding:6px 12px;font-size:12px" onclick="todoSend(${t.id})" ${(tickedCount===0||TASK.busyId===t.id)?'disabled':''}>${TASK.busyId===t.id?'Sending…':'✉ Send to ticked ('+tickedCount+')'}</button>
      <button class="btn sec" style="width:auto;padding:6px 12px;font-size:12px" onclick="todoCalToggle(${t.id})">📅 Calendar</button>
      <button class="btn sec" style="width:auto;padding:6px 12px;font-size:12px;color:var(--bad);margin-left:auto" onclick="todoDelete(${t.id})">🗑 Delete</button>
    </div>
    ${TASK.calId===t.id?`<div class="card" style="background:#fff;margin-top:10px">
      <div class="muted" style="font-size:10.5px;margin-bottom:6px">Emails a calendar invite to the ticked assignees — they tap once to add it to their calendar.</div>
      <div class="grid2" style="gap:8px">
        <div><label>Date</label><input type="date" value="${esc(TASK.calDate||today())}" onchange="TASK.calDate=this.value"></div>
        <div><label>Time</label><input type="time" value="${esc(TASK.calTime||'09:00')}" onchange="TASK.calTime=this.value"></div>
      </div>
      <label>Duration (minutes)</label>
      <input type="number" step="15" min="5" value="${esc(TASK.calDur||60)}" onchange="TASK.calDur=this.value">
      <button class="btn" style="margin-top:8px;width:auto;padding:6px 12px;font-size:12px" onclick="todoCalSend(${t.id})" ${(tickedCount===0||TASK.calBusy)?'disabled':''}>${TASK.calBusy?'Sending…':'Send calendar invite to ticked ('+tickedCount+')'}</button>
      ${(TASK.calMsg&&TASK.calId===t.id)?`<div class="${TASK.calErr?'warn':'ok'}" style="margin-top:8px;font-size:12px">${TASK.calMsg}</div>`:''}
    </div>`:''}` : `
    <label style="margin-top:0">Notes</label>
    <textarea placeholder="Add a progress note…" style="width:100%;min-height:54px;font-size:12px" onchange="todoField(${t.id},'notes',this.value)">${esc(t.notes||'')}</textarea>
    ${as.length>1?`<div class="muted" style="font-size:11px;margin-top:8px">Also on this: ${as.filter(a=>String(a.name||a.email).toLowerCase()!==String(ME.user||'').toLowerCase()).map(a=>esc(a.name||a.email)).join(', ')||'just you'}</div>`:''}`;

  return `<div class="tkcard open${done?' done':''}">${head}<div class="tk-body">${adminBody}</div></div>`;
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
    <div class="muted" style="margin:-4px 2px 14px;font-size:12px">Tasks assigned to you. Tap a task to open it, tick the circle when it's done.</div>
    <div class="sect" style="margin-top:6px"><b>Open</b><span class="ln"></span><span class="pill" style="background:#FFF4EB;color:var(--orange)">${open.length}</span></div>
    ${open.length? `<div class="tkgrid">${open.map(todoTaskCard).join('')}</div>` : `<div class="card muted" style="text-align:center;padding:20px">Nothing assigned to you right now. 🎉</div>`}
    ${done.length?`<div class="sect"><b>Done</b><span class="ln"></span><span class="pill" style="background:#E7F6EC;color:var(--good)">${done.length}</span>
        <button class="btn sec" style="width:auto;padding:4px 10px;font-size:11px" onclick="TASK.showDone=!TASK.showDone;render()">${TASK.showDone?'Hide':'Show'}</button>
      </div>${TASK.showDone? `<div class="tkgrid">${done.map(todoTaskCard).join('')}</div>` : ''}`:''}`;
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

  <div class="sect"><b>From your email</b><span class="ln"></span>
    <button class="btn sec" style="width:auto;padding:4px 10px;font-size:11px" onclick="TASK.hideInbox=!TASK.hideInbox;render()">${TASK.hideInbox?'Show':'Hide'}</button></div>
  ${TASK.hideInbox? '' : todoInboxHtml()}

  <div class="sect"><b>Open tasks</b><span class="ln"></span><span class="pill" style="background:#FFF4EB;color:var(--orange)">${open.length}</span></div>
  ${open.length? `<div class="tkgrid">${open.map(todoTaskCard).join('')}</div>` : `<div class="card muted" style="text-align:center;padding:20px">Nothing open. 🎉</div>`}

  ${done.length?`<div class="sect"><b>Done</b><span class="ln"></span><span class="pill" style="background:#E7F6EC;color:var(--good)">${done.length}</span>
      <button class="btn sec" style="width:auto;padding:4px 10px;font-size:11px" onclick="TASK.showDone=!TASK.showDone;render()">${TASK.showDone?'Hide':'Show'}</button>
    </div>${TASK.showDone? done.map(todoTaskCard).join('') : ''}`:''}`;
}
/* ================= end To-Do ================= */

function themeIsDark(){ return document.documentElement.classList.contains('dark'); }
function openJobCard(id){ window.open('api/job_card.php?id='+id+(themeIsDark()?'&theme=dark':''),'_blank'); }   /* job card follows dark mode on screen, prints light */
/* open a branded document (BOQ / profit report) in a popup with a print/save-PDF button */
function docOpen(url,title){ document.getElementById('docModalTitle').textContent=title||'Document'; document.getElementById('docFrame').src=url; document.getElementById('docModal').classList.add('open'); document.body.style.overflow='hidden'; }
function docClose(){ document.getElementById('docModal').classList.remove('open'); document.body.style.overflow=''; const f=document.getElementById('docFrame'); if(f) f.src='about:blank'; }
function docPrint(){ const f=document.getElementById('docFrame'); if(f&&f.contentWindow){ try{ f.contentWindow.focus(); f.contentWindow.print(); }catch(e){ window.open(f.src.replace('&embed=1',''),'_blank'); } } }
function openBoq(id){ docOpen('api/job_card.php?id='+id+'&doc=boq&embed=1'+(themeIsDark()?'&theme=dark':''),'Bill of Quantities'); }   /* materials only, labour excluded */
function openProfit(id){ docOpen('api/job_card.php?id='+id+'&doc=profit&embed=1'+(themeIsDark()?'&theme=dark':''),'Profit Report'); }   /* per-invoice profit (admin) */
function syncThemeBtn(){ const b=document.getElementById('themeBtn'); if(b) b.textContent = themeIsDark()?'☀️':'🌙'; }
function toggleTheme(){
  const dark=document.documentElement.classList.toggle('dark');
  try{ localStorage.setItem('theme912', dark?'dark':'light'); }catch(e){}
  syncThemeBtn();
}
syncThemeBtn();

/* ---- Install-app affordance (mobile, when not already installed) ---- */
let _deferredInstall=null;
function pwaStandalone(){ return (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) || window.navigator.standalone===true; }
function pwaIsMobile(){ return /Mobile|Android|iPhone|iPad|iPod/i.test(navigator.userAgent); }
function pwaIsIOS(){ return /iPhone|iPad|iPod/i.test(navigator.userAgent) || (navigator.platform==='MacIntel' && navigator.maxTouchPoints>1); }
function showInstallBtn(){ const b=document.getElementById('installBtn'); if(b) b.style.display='inline-flex'; }
function hideInstallBtn(){ const b=document.getElementById('installBtn'); if(b) b.style.display='none'; }
function iosHelpOpen(){
  const steps=document.getElementById('installSteps');
  if(steps){
    steps.innerHTML = pwaIsIOS()
      ? `<div class="step"><span style="font-size:20px">1️⃣</span><div>Tap the <b>Share</b> button at the bottom of Safari</div></div>`
        + `<div class="step"><span style="font-size:20px">2️⃣</span><div>Scroll down and tap <b>Add to Home Screen</b> ➕</div></div>`
        + `<div class="step"><span style="font-size:20px">3️⃣</span><div>Tap <b>Add</b> — the 912 icon appears on your Home Screen</div></div>`
      : `<div class="step"><span style="font-size:20px">1️⃣</span><div>Tap the <b>⋮ menu</b> (top-right of your browser)</div></div>`
        + `<div class="step"><span style="font-size:20px">2️⃣</span><div>Tap <b>Install app</b> or <b>Add to Home screen</b></div></div>`
        + `<div class="step"><span style="font-size:20px">3️⃣</span><div>Confirm — the 912 icon appears on your Home Screen</div></div>`;
  }
  const m=document.getElementById('iosHelp'); if(m) m.classList.add('open');
}
function iosHelpClose(){ const m=document.getElementById('iosHelp'); if(m) m.classList.remove('open'); }
async function installClick(){
  if(_deferredInstall){ _deferredInstall.prompt(); try{ await _deferredInstall.userChoice; }catch(e){} _deferredInstall=null; hideInstallBtn(); return; }
  iosHelpOpen();   // no native prompt available → show platform-specific steps
}
window.addEventListener('beforeinstallprompt', function(e){ e.preventDefault(); _deferredInstall=e; if(pwaIsMobile() && !pwaStandalone()) showInstallBtn(); });
window.addEventListener('appinstalled', function(){ _deferredInstall=null; hideInstallBtn(); });
(function pwaInit(){
  if(pwaStandalone() || !pwaIsMobile()){ hideInstallBtn(); return; }   // already installed or desktop → hide
  showInstallBtn();                                                    // always offer install on mobile
})();

function closeNavGroups(){ document.querySelectorAll('.navgroup.open').forEach(g=>g.classList.remove('open')); }
function openMobileNav(){ document.getElementById('mobDrawer').classList.add('open'); document.getElementById('mobOverlay').classList.add('open'); document.body.style.overflow='hidden'; }
function closeMobileNav(){ document.getElementById('mobDrawer').classList.remove('open'); document.getElementById('mobOverlay').classList.remove('open'); document.body.style.overflow=''; }
function updateMobActive(){ document.querySelectorAll('.mob-item[data-tab]').forEach(b=>b.classList.toggle('mob-active',b.dataset.tab===TAB)); }

function navActivate(b){
  if(b.dataset.tab==='newquote'){ qbOpenNew(); closeNavGroups(); return; }   // builder opens as a popup, not a tab
  document.querySelectorAll('.tabs button').forEach(x=>x.classList.remove('active'));
  b.classList.add('active');
  const grp=b.closest('.navgroup');
  if(grp){ const gb=grp.querySelector('.grp'); if(gb) gb.classList.add('active'); }
  TAB=b.dataset.tab;
  updateMobActive();
  closeNavGroups();
  if(TAB==='report' && !REPORT.loaded && !REPORT.loading){ render(); loadReport(); return; }
  if(TAB==='etr' && !ETR.loaded && !ETR.loading){ render(); etrLoad(); return; }
  if(TAB==='invrep' && !INVR.loaded && !INVR.loading){ render(); invrLoad(); return; }
  if(TAB==='quotes' && !QUOT.loaded && !QUOT.loading){ render(); quotLoad(); return; }
  if(TAB==='qlist'){ render(); if(!QLIST.loaded && !QLIST.loading) qlistLoad(); return; }
  if(TAB==='ivlist'){ render(); if(!IVLIST.loaded && !IVLIST.loading) ivlistLoad(); return; }
  if(TAB==='stmtbuild'){ render(); if(!SB.loaded && !SB.loading) sbLoad(); return; }
  if(TAB==='latepay'){ render(); if(!LATE.loaded && !LATE.loading) lateLoad(); return; }
  if(TAB==='bulkexp'){ render(); expLoadAccounts(); return; }
  if(TAB==='emails' && !EMAIL.loaded && !EMAIL.loadingClients){ render(); emailLoadClients(); return; }
  if(TAB==='todo' && !TASK.loaded && !TASK.loading){ render(); todoLoad(); return; }
  if(TAB==='loans'){ render(); loadLoans(); return; }
  if(TAB==='myquotes'){ render(); mqLoad(); return; }
  if(TAB==='clientaccess'){ render(); if(ME.admin && !QB.assignLoaded) qbAssignLoad(); return; }
  if(TAB==='jobcards'){ render(); loadJobCards(); return; }
  if(TAB==='activity'){ render(); if(ME.admin) loadActivity(); return; }
  if(TAB==='settings'){ render(); if(ME.admin && !USERS.loaded) usersLoad(); return; }
  render();
}

/* ===================== Quotes → Zoho (Create group) ===================== */
const EDITABLE_STATUSES = ['local_draft','draft','pending_approval'];
function mqEditable(q){ return ME.admin || EDITABLE_STATUSES.includes(q.status); }   /* admins can edit any quote */
function qbBlank(){ return { id:0, zohoId:'', status:'local_draft', customerId:'', customerName:'', currency:'KES',
           reference:'', subject:'', quoteDate:today(), expiryDate:'',
           items:[{lid:newLid(),name:'',description:'',qty:1,rate:0,cost:0,acost:0,tax:'vat'}], notes:'Looking forward for your business.', terms:'',
           discVal:0, discType:'percent',
           msg:'', err:false, busy:false }; }
let QB = Object.assign(qbBlank(), { assignOpen:false, assignLoaded:false, assignments:[], users:[], aCust:null, aUsers:[] });
let MQ = { quotes:[], loaded:false, loading:false, syncing:false, msg:'', err:false, busyId:0, month:'all', page:1, perPage:50, open:{}, users:[], statuses:[], q:'' };
const STATUS_GROUPS = [
  {key:'approved', label:'Approved', set:['approved','accepted']},
  {key:'pending',  label:'Pending',  set:['pending_approval','draft','local_draft']},
  {key:'declined', label:'Declined', set:['declined','rejected']},
  {key:'sent',     label:'Sent',     set:['sent']},
  {key:'project',  label:'In progress', set:['project']},
  {key:'invoiced', label:'Invoiced', set:['invoiced']},
];
function mqStatusSet(){ const s=new Set(); MQ.statuses.forEach(k=>{ const g=STATUS_GROUPS.find(x=>x.key===k); if(g) g.set.forEach(v=>s.add(v)); }); return s; }
const MQ_PER_PAGE = 50;
/* ---- shared page-size + pagination helpers (used by every record list) ---- */
const PAGE_SIZES=[10,15,25,50,100];
function pgSel(cur,onchange){ return `<select onchange="${onchange}" title="Records per page" style="width:auto;margin:0;font-size:12px;padding:6px 8px">${PAGE_SIZES.map(n=>`<option value="${n}" ${(cur||25)===n?'selected':''}>${n} / page</option>`).join('')}</select>`; }
function pgSlice(arr,st){ const total=arr.length; const per=st.perPage||25; const pages=Math.max(1,Math.ceil(total/per)); if(st.page>pages)st.page=pages; if(!st.page||st.page<1)st.page=1; return {total,per,pages,slice:arr.slice((st.page-1)*per,st.page*per)}; }
function pgBar(st,pages,goFn){ if(pages<=1)return ''; return `<div class="row" style="justify-content:center;gap:8px;margin-top:14px"><button class="btn sec" style="width:auto;padding:6px 12px;font-size:12px" onclick="${goFn}(${st.page-1})" ${st.page<=1?'disabled':''}>‹ Prev</button><span class="muted" style="font-size:12px">Page ${st.page} of ${pages}</span><button class="btn sec" style="width:auto;padding:6px 12px;font-size:12px" onclick="${goFn}(${st.page+1})" ${st.page>=pages?'disabled':''}>Next ›</button></div>`; }
const MONTHS_SHORT = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
function mqNormalize(qs){ (qs||[]).forEach(q=>{ if(q.zoho_invoice_number) q.status='invoiced'; }); return qs||[]; }   /* an invoice number means invoiced, regardless of estimate status */
function mqMonthKey(q){ return String(q.created_at||q.quote_date||'').slice(0,7); }   /* YYYY-MM */
function mqMonthLabel(k){ if(!k||k.length<7) return k||'—'; const m=parseInt(k.slice(5,7),10); return (MONTHS_SHORT[m-1]||k.slice(5,7))+' '+k.slice(0,4); }
const qesc = s => String(s==null?'':s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
let _qbSearchT=null, _qbAssignT=null;

function qbLineAmt(it){ return (parseFloat(it.qty)||0)*(parseFloat(it.rate)||0); }
function qbEffCost(it){ const a=parseFloat(it.acost)||0; return a>0?a:(parseFloat(it.cost)||0); }   /* actual cost wins, else unit cost */
function qbLineCost(it){ return (parseFloat(it.qty)||0)*qbEffCost(it); }
function qbSub(){ return QB.items.reduce((s,it)=>s+qbLineAmt(it),0); }
function qbCostTotal(){ return QB.items.reduce((s,it)=>s+qbLineCost(it),0); }
function qbProfit(){ return qbSub()-qbDiscAmt()-qbCostTotal(); }
function qbAmtCellHtml(it){ const amt=qbLineAmt(it); const lp=amt-qbLineCost(it);
  return `${fmtn(amt)}${ME.admin?`<div style="font-size:9.5px;font-weight:600;color:${lp<0?'var(--bad)':'var(--good)'}" title="Line profit (ex VAT)">+${fmtn(lp)}</div>`:''}`; }
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
    project:['In progress','#7A5AF8','#EEEAFE'],
    invoiced:['Invoiced','#2350C5','#EEF2FE'],
    expired:['Expired','#64748B','#F1F4F8']
  };
  const m=map[status]||[status||'—','#64748B','#F1F4F8'];
  return `<span class="pill" style="color:${m[1]};background:${m[2]}">${m[0]}</span>`;
}
function quoteAccent(s){
  if(s==='approved'||s==='accepted') return 'var(--good)';
  if(s==='declined'||s==='rejected') return 'var(--bad)';
  if(s==='pending_approval') return '#E0A400';
  if(s==='project') return '#7A5AF8';
  if(s==='sent'||s==='invoiced') return 'var(--blue)';
  return '#CBD5E1';
}

/* ---- New Quote (builder) — laid out like the Zoho estimate form ---- */
function vNewQuote(){
  const cur=QB.currency||'KES';
  const taxOpt=t=>`<option value="vat" ${t!=='none'?'selected':''}>VAT (${Math.round((CFG.vat||0.16)*100)}%)</option><option value="none" ${t==='none'?'selected':''}>No tax</option>`;
  const rows=QB.items.map((it,i)=>`
    <div class="qbrow qb-noac">
      <div class="qbc-item">
        <div style="position:relative;margin-bottom:4px">
          <input id="qbIN${i}" type="text" autocomplete="off" placeholder="Item name" value="${qesc(it.name)}" oninput="qbItemName(${i},this.value)" onfocus="qbItemNameFocus(${i})" onblur="qbItemNameBlur(${i})" style="margin-bottom:0;font-weight:600;width:100%">
          <div id="qbIND${i}" style="display:none;position:absolute;left:0;right:0;top:calc(100% + 2px);background:#fff;border:1px solid var(--line);border-radius:8px;box-shadow:0 10px 26px rgba(21,32,43,.16);max-height:190px;overflow-y:auto;z-index:60"></div>
        </div>
        <input type="text" placeholder="Add a description to your item" value="${qesc(it.description)}" oninput="qbItem(${i},'description',this.value)" style="margin-bottom:0;font-size:11px;color:var(--mute)">
      </div>
      <div class="qbc-qty"><span class="qbc-lab">Qty</span><input type="number" step="0.01" min="0" value="${qesc(it.qty)}" oninput="qbItem(${i},'qty',this.value)" style="margin-bottom:0;text-align:right"></div>
      <div class="qbc-rate"><span class="qbc-lab">Rate</span><input type="number" step="0.01" min="0" value="${qesc(it.rate)}" oninput="qbItem(${i},'rate',this.value)" style="margin-bottom:0;text-align:right"></div>
      <div class="qbc-cost"><span class="qbc-lab">Budgeted unit cost</span><input type="number" step="0.01" min="0" placeholder="0" value="${qesc(it.cost)}" oninput="qbItem(${i},'cost',this.value)" title="Budgeted/estimated cost per unit (internal). Actual cost is captured at conversion." style="margin-bottom:0;text-align:right"></div>
      <div class="qbc-tax"><span class="qbc-lab">Tax</span><select onchange="qbItem(${i},'tax',this.value)" style="margin-bottom:0">${taxOpt(it.tax)}</select></div>
      <div class="qbc-amt"><span class="qbc-lab">Amount</span><div id="qbAmt${i}" style="font-weight:700;font-size:13px">${qbAmtCellHtml(it)}</div></div>
      <button class="qbc-del btn sec" onclick="qbDelRow(${i})" title="Remove">✕</button>
    </div>`).join('');

  const custBlock = QB.customerId
    ? `<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
         <div class="wchip" style="background:#EEF2FE;border-color:#C7D5F5"><b>${qesc(QB.customerName)}</b>
           <span style="cursor:pointer;color:var(--bad);font-weight:700;margin-left:6px" onclick="qbClearCust()">✕</span></div>
         <span class="pill" style="background:#E7F6EC;color:#0F7A34">⦿ ${qesc(cur)}</span></div>`
    : `<input type="text" id="qbCustSearch" ${tip('Type the client name, then pick them from the list')} placeholder="Search customer name (from Zoho)…" oninput="qbSearch(this.value)" autocomplete="off" style="margin-bottom:0">
       <div id="qbCustResults"></div>`;

  return `
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
    <div class="qbhead qb-noac">
      <div class="qbc-item">Item details</div><div class="qbc-qty">Qty</div><div class="qbc-rate">Rate</div>
      <div class="qbc-cost">Budgeted unit cost</div><div class="qbc-tax">Tax</div><div class="qbc-amt">Amount</div><div class="qbc-del"></div>
    </div>
    ${rows}
    <button class="btn sec" style="width:auto;padding:7px 12px;font-size:12px;margin-top:10px" ${tip('Add another item line to this quote')} onclick="qbAddRow()">⊕ Add new row</button>
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
    ? `<div class="warn" style="background:#FFF4D6;color:#9A6700;margin-bottom:10px;font-size:11.5px">Editing a quote that's already in Zoho. Saving updates it there${(QB.status==='approved'||QB.status==='sent'||QB.status==='accepted')?'.':' and re-submits it for approval.'}</div>
       <button class="btn" style="width:100%" onclick="qbSaveUpdate()" ${QB.busy?'disabled':''}>${QB.busy?'Working…':'Save changes & re-submit to Zoho →'}</button>`
    : `<div class="row" style="gap:8px">
        <button class="btn sec" style="flex:1" ${tip('Save without sending — you can finish it later')} onclick="qbSave()" ${QB.busy?'disabled':''}>Save draft</button>
        <button class="btn" style="flex:1" ${tip('Save and send to Zoho so an admin can approve it')} onclick="qbSaveAndPush()" ${QB.busy?'disabled':''}>${QB.busy?'Working…':'Save & push to Zoho →'}</button>
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
    <div class="row" style="margin-top:10px;padding-top:10px;border-top:1px solid var(--line)"><b>Total ( ${qesc(cur)} )</b><b style="color:var(--orange);font-size:17px">${fmtn(qbTotal())}</b></div>
    ${ME.admin?`<div class="row" style="margin-top:12px;padding-top:10px;border-top:1px dashed var(--line)"><span class="muted">Cost (internal)</span><span>${fmtn(qbCostTotal())}</span></div>
    <div class="row" style="margin-top:6px"><b style="color:var(--good)">Profit (ex VAT)</b><b style="color:${qbProfit()<0?'var(--bad)':'var(--good)'}">${fmtn(qbProfit())}${qbSub()>0?` · ${Math.round(qbProfit()/qbSub()*100)}%`:''}</b></div>`:''}`;
}
function qbItem(i,field,val){ if(!QB.items[i])return; QB.items[i][field]=val;
  const a=document.getElementById('qbAmt'+i); if(a) a.innerHTML=qbAmtCellHtml(QB.items[i]);
  const t=document.getElementById('qbTotals'); if(t) t.innerHTML=qbTotalsHtml(); }

/* ---- Shared item-name autocomplete (cached across all users) ---- */
let ITEMNAMES = [];
function itemNamesLoad(){
  fetch('api/item_names.php?action=list',{credentials:'same-origin'}).then(r=>r.json())
    .then(j=>{ if(j.ok) ITEMNAMES=j.names||[]; }).catch(()=>{});
}
function itemNamesAdd(names){
  const clean=[...new Set((names||[]).map(n=>String(n||'').trim()).filter(Boolean))];
  if(!clean.length) return;
  // merge locally so this session autocompletes new names immediately
  const seen=new Set(ITEMNAMES.map(n=>n.toLowerCase()));
  clean.forEach(n=>{ if(!seen.has(n.toLowerCase())){ ITEMNAMES.push(n); seen.add(n.toLowerCase()); } });
  fetch('api/item_names.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'add',names:clean})}).catch(()=>{});
}
function qbItemMatches(i){ const q=String((QB.items[i]||{}).name||'').trim().toLowerCase(); let l=ITEMNAMES; if(q) l=ITEMNAMES.filter(n=>n.toLowerCase().includes(q)); return l.slice(0,40); }
function qbItemNameDD(i){ const list=qbItemMatches(i); if(!list.length) return ''; return list.map(n=>`<div data-name="${qesc(n)}" onmousedown="event.preventDefault();qbItemPickEl(${i},this)" style="padding:7px 10px;font-size:12px;cursor:pointer;border-bottom:1px solid var(--line);background:#fff" onmouseover="this.style.background='#F1F4F8'" onmouseout="this.style.background='#fff'">${qesc(n)}</div>`).join(''); }
function qbItemName(i,v){ if(QB.items[i]) QB.items[i].name=v; const dd=document.getElementById('qbIND'+i); if(dd){ dd.innerHTML=qbItemNameDD(i); dd.style.display=dd.innerHTML?'block':'none'; } }
function qbItemNameFocus(i){ const dd=document.getElementById('qbIND'+i); if(dd){ dd.innerHTML=qbItemNameDD(i); dd.style.display=dd.innerHTML?'block':'none'; } }
function qbItemNameBlur(i){ setTimeout(()=>{ const dd=document.getElementById('qbIND'+i); if(dd) dd.style.display='none'; },150); }
function qbItemPickEl(i,el){ const n=el.getAttribute('data-name'); if(QB.items[i]) QB.items[i].name=n; const inp=document.getElementById('qbIN'+i); if(inp) inp.value=n; const dd=document.getElementById('qbIND'+i); if(dd) dd.style.display='none'; }
function qbDisc(field,val){ QB[field]=val; const t=document.getElementById('qbTotals'); if(t) t.innerHTML=qbTotalsHtml(); }
function newLid(){ return Math.random().toString(36).slice(2,10)+Math.random().toString(36).slice(2,6); }  // stable per-line id (fix #10)
function qbAddRow(){ QB.items.push({lid:newLid(),name:'',description:'',qty:1,rate:0,tax:'vat'}); render(); }
function qbDelRow(i){ QB.items.splice(i,1); if(!QB.items.length) QB.items.push({lid:newLid(),name:'',description:'',qty:1,rate:0,tax:'vat'}); render(); }
function qbClearCust(){ QB.customerId=''; QB.customerName=''; render(); }
/* ---- builder modal open/close ---- */
function qbOpen(){ QB.modalOpen=true; const m=document.getElementById('qbModal'); if(m) m.classList.add('open');
  const b=document.getElementById('qbModalBody'); if(b) b.innerHTML=vNewQuote();
  const t=document.getElementById('qbModalTitle'); if(t) t.textContent=QB.id?'Edit quote':'New quote';
  document.body.style.overflow='hidden'; }
function qbOpenNew(){ QB=Object.assign(qbBlank(), {assignOpen:QB.assignOpen,assignLoaded:QB.assignLoaded,assignments:QB.assignments,users:QB.users,aCust:null,aUsers:[]}); qbOpen(); }
function qbClose(){ QB.modalOpen=false; const m=document.getElementById('qbModal'); if(m) m.classList.remove('open'); document.body.style.overflow=''; }
/* ---- change-my-password modal ---- */
function pwOpen(){ ['pwCur','pwNew','pwNew2'].forEach(i=>{const e=document.getElementById(i); if(e)e.value='';});
  const msg=document.getElementById('pwMsg'); if(msg)msg.innerHTML=''; const m=document.getElementById('pwModal'); if(m) m.classList.add('open'); document.body.style.overflow='hidden';
  const c=document.getElementById('pwCur'); if(c) setTimeout(()=>c.focus(),50); }
function pwClose(){ const m=document.getElementById('pwModal'); if(m) m.classList.remove('open'); document.body.style.overflow=''; }
function pwMsg(html,err){ const e=document.getElementById('pwMsg'); if(e) e.innerHTML=`<div class="${err?'warn':'ok'}" style="font-size:12px">${html}</div>`; }
function pwSave(){
  const cur=(document.getElementById('pwCur')||{}).value||'';
  const nw=(document.getElementById('pwNew')||{}).value||'';
  const nw2=(document.getElementById('pwNew2')||{}).value||'';
  if(nw.length<6){ pwMsg('New password must be at least 6 characters.',true); return; }
  if(nw!==nw2){ pwMsg('The new passwords do not match.',true); return; }
  const btn=document.getElementById('pwBtn'); if(btn){ btn.disabled=true; btn.textContent='Updating…'; }
  fetch('api/my_password.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({current:cur,new:nw})})
  .then(r=>r.json()).then(j=>{ if(btn){ btn.disabled=false; btn.textContent='Update password'; }
    if(j.ok){ pwMsg('Password updated ✓',false); setTimeout(pwClose,1200); }
    else pwMsg(j.error||'Could not update password.',true);
  }).catch(e=>{ if(btn){ btn.disabled=false; btn.textContent='Update password'; } pwMsg('Error: '+e,true); });
}
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
function qbPick(id,name,cur){ cur=(cur||'KES');
  if(!ME.admin && cur.toUpperCase()!=='KES'){ QB.msg='This customer is billed in '+cur+'. Only an admin can raise quotes in a currency other than KES.'; QB.err=true; render(); return; }
  QB.customerId=id; QB.customerName=name; QB.currency=cur; QB.msg=''; QB.err=false; render(); }
function qbPayload(){ return { id:QB.id||undefined, zoho_customer_id:QB.customerId, customer_name:QB.customerName,
  currency:QB.currency, reference:QB.reference, subject:QB.subject, quote_date:QB.quoteDate, expiry_date:QB.expiryDate,
  notes:QB.notes, terms:QB.terms, discount_value:QB.discVal, discount_type:QB.discType,
  line_items:QB.items.map(it=>({lid:it.lid,name:it.name,description:it.description,qty:it.qty,rate:it.rate,cost:it.cost,actual_cost:it.acost,tax:it.tax||'vat'})) }; }
function qbReqOk(){
  if(!QB.customerId && !QB.customerName){ QB.msg='Choose a customer.'; QB.err=true; render(); return false; }
  if(!String(QB.subject||'').trim()){ QB.msg='Subject is required.'; QB.err=true; render(); return false; }
  if(!QB.items.some(it=>String(it.name||'').trim())){ QB.msg='Add at least one line item.'; QB.err=true; render(); return false; }
  return true;
}
function qbSave(cb){ if(!qbReqOk())return; QB.busy=true; QB.msg=''; render();
  fetch('api/quotes.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify(Object.assign({action:'save'},qbPayload()))})
  .then(r=>r.json()).then(j=>{ QB.busy=false;
    if(j.ok){ itemNamesAdd(QB.items.map(it=>it.name)); QB.id=j.quote.id; QB.msg='Saved draft #'+j.quote.id+'.'; QB.err=false; MQ.loaded=false; if(cb){cb();return;} render(); }
    else { QB.msg=j.error||'Save failed.'; QB.err=true; render(); }
  }).catch(e=>{ QB.busy=false; QB.msg='Error: '+e; QB.err=true; render(); });
}
function qbSaveAndPush(){ qbSave(()=>qbPush(QB.id)); }
function qbSaveUpdate(){ qbSave(()=>qbUpdate(QB.id)); }
function qbUpdate(id){ QB.busy=true; QB.msg='Updating Zoho…'; QB.err=false; render();
  fetch('api/quote_update.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({id})})
  .then(r=>r.json()).then(j=>{ QB.busy=false;
    if(j.ok){ qbClose(); QB=Object.assign(qbBlank(), { assignOpen:QB.assignOpen, assignLoaded:QB.assignLoaded, assignments:QB.assignments, users:QB.users, aCust:null, aUsers:[] });
      MQ.loaded=false; MQ.msg='Quote updated in Zoho — '+(j.status==='pending_approval'?'re-submitted for approval.':'status: '+j.status+'.')+(j.note?' '+j.note:''); MQ.err=!!j.note;
      navTo('myquotes');
    } else { QB.msg=j.error||'Update failed.'; QB.err=true; render(); }
  }).catch(e=>{ QB.busy=false; QB.msg='Error: '+e; QB.err=true; render(); });
}
function qbPush(id){ QB.busy=true; QB.msg='Pushing to Zoho…'; QB.err=false; render();
  fetch('api/quote_push.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({id})})
  .then(r=>r.json()).then(j=>{ QB.busy=false;
    if(j.ok){ qbClose(); QB=Object.assign(qbBlank(), { assignOpen:QB.assignOpen, assignLoaded:QB.assignLoaded, assignments:QB.assignments, users:QB.users, aCust:null, aUsers:[] });
      MQ.loaded=false; MQ.msg='Quote pushed to Zoho as '+(j.estimate_number||'estimate')+' — '+(j.status==='pending_approval'?'awaiting approval.':'status: '+j.status+'.')+(j.note?' '+j.note:''); MQ.err=!!j.note;
      navTo('myquotes');
    } else { QB.msg=j.error||'Push failed.'; QB.err=true; render(); }
  }).catch(e=>{ QB.busy=false; QB.msg='Error: '+e; QB.err=true; render(); });
}

/* ---- admin: customer → users assignment panel ---- */
/* ---- Client Access tab (Settings → Client Access): assign clients to users ---- */
function vClientAccess(){
  if(!ME.admin) return `<div class="card muted" style="text-align:center">Admins only.</div>`;
  const list = QB.assignments.length
    ? QB.assignments.map(a=>`<div class="invrow" style="cursor:default"><div style="min-width:0"><div style="font-size:13px;font-weight:600">${qesc(a.name||a.id)}</div>
        <div class="muted">${(a.users||[]).length? (a.users||[]).map(u=>`<span class="pill" style="background:#EEF2FE;color:var(--blue);margin-right:4px">${qesc(u)}</span>`).join('') : 'no users assigned'}</div></div>
        <button class="btn sec" style="width:auto;padding:5px 11px;font-size:11px" onclick="qbAEdit('${qesc(a.id)}','${qesc(a.name||'').replace(/'/g,'&#39;')}')">Edit</button></div>`).join('')
    : `<div class="muted" style="font-size:12px;padding:14px;text-align:center">No clients assigned yet. Search a client above to begin.</div>`;
  const editor = QB.aCust ? `<div class="card" style="background:#FAFBFD;margin-top:10px">
      <div class="row" style="margin-bottom:8px"><b style="font-size:13px">${qesc(QB.aCust.name)}</b>
        <span style="cursor:pointer;color:var(--bad);font-weight:700" onclick="QB.aCust=null;render()" title="Close">✕</span></div>
      <div class="muted" style="font-size:11.5px;margin-bottom:8px">Tick the users this client belongs to (a client can go to as many users as you like):</div>
      <div style="display:flex;flex-wrap:wrap;gap:7px">${QB.users.map(u=>`<label style="display:inline-flex;align-items:center;gap:6px;background:#fff;border:1px solid var(--line);border-radius:20px;padding:5px 11px;font-size:12px;cursor:pointer">
        <input type="checkbox" ${QB.aUsers.includes(u)?'checked':''} onchange="qbAToggle('${qesc(u)}',this.checked)">${qesc(u)}</label>`).join('')||'<span class="muted" style="font-size:11px">No app users yet — add users in Settings → App Settings.</span>'}</div>
      <button class="btn" style="margin-top:12px;width:auto;padding:8px 16px;font-size:12px" onclick="qbASave()">Save (${QB.aUsers.length} user${QB.aUsers.length===1?'':'s'})</button>
    </div>` : '';
  return `<h2>Client access</h2>
    <div class="muted" style="margin:-6px 0 12px">Assign clients to your users. Use this to keep track of who owns which client. ${QB.assignLoaded?'':'<span style="color:var(--blue)">Loading…</span>'}</div>
    <div class="card">
      <label>Find a client</label>
      <input type="text" id="qbAssignSearch" placeholder="🔍 Search a Zoho client by name…" oninput="qbASearch(this.value)" autocomplete="off" style="margin-bottom:0">
      <div id="qbAssignResults"></div>
      ${editor}
    </div>
    <div class="sect"><b>Current assignments</b><span class="ln"></span>${QB.assignments.length?`<span class="pill" style="background:#F1F4F8;color:var(--ink)">${QB.assignments.length}</span>`:''}</div>
    ${list}`;
}
function qbAssignLoad(){ fetch('api/customer_assign.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'list'})})
  .then(r=>r.json()).then(j=>{ if(j.ok){ QB.assignments=j.assignments||[]; QB.users=j.users||[]; QB.assignLoaded=true; if(TAB==='clientaccess') render(); } }).catch(()=>{}); }
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
  const showProfit=ME.admin;
  const rows=its.map(it=>{
    const amt=(it.amount!=null?it.amount:(it.qty*it.rate));
    const ec=((it.actual_cost>0)?it.actual_cost:(it.cost||0));
    const lp=(it.profit!=null?it.profit:(amt-(it.qty*ec)));
    return `<div class="row" style="gap:8px;padding:5px 0;border-bottom:1px dashed var(--line)">
      <div style="flex:1;min-width:0"><div style="font-size:11.5px;font-weight:600;text-transform:uppercase">${qesc(it.name||'')}</div>
        ${it.description?`<div class="muted" style="font-size:10.5px">${qesc(it.description)}</div>`:''}</div>
      <div class="muted" style="font-size:11px;white-space:nowrap">${fmt1(it.qty)} × ${fmtn(it.rate)}${(it.tax==='none')?' · no tax':''}${showProfit&&(ec>0)?` · cost ${fmtn(ec)}${(it.actual_cost>0)?' (actual)':''}`:''}</div>
      <div style="white-space:nowrap;min-width:84px;text-align:right">
        <div style="font-weight:600;font-size:11.5px">${fmtn(amt)}</div>
        ${showProfit?`<div style="font-size:10px;color:${lp<0?'var(--bad)':'var(--good)'}">+${fmtn(lp)}</div>`:''}
      </div>
    </div>`;}).join('');
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
    ${ME.admin?`<div class="row" style="margin-top:6px;padding-top:6px;border-top:1px dashed var(--line)"><span class="muted" style="font-size:11px">Cost ${fmtn(q.total_cost||0)}</span>
      <b style="font-size:12px;color:${(q.profit||0)<0?'var(--bad)':'var(--good)'}">Profit ${fmtn(q.profit||0)}${q.sub_total>0?` · ${Math.round((q.profit||0)/q.sub_total*100)}%`:''}</b></div>`:''}
    ${q.notes?`<div class="muted" style="font-size:11px;margin-top:6px">📝 ${qesc(q.notes)}</div>`:''}
  </div>`;
}
function mqListHtml(){
  const all=MQ.quotes||[];
  let filtered = all;
  if(MQ.month!=='all') filtered=filtered.filter(q=>mqMonthKey(q)===MQ.month);
  if(MQ.users.length) filtered=filtered.filter(q=>MQ.users.includes(q.created_by));
  if(MQ.statuses.length){ const ss=mqStatusSet(); filtered=filtered.filter(q=>ss.has(q.status)); }
  const qq=(MQ.q||'').trim().toLowerCase();
  if(qq){
    const digits=qq.replace(/\D/g,'');   // let "2642" match "NOT-2642", "INV 2642", etc.
    filtered=filtered.filter(q=>{
      const hay=((q.zoho_estimate_number||'')+' '+(q.zoho_invoice_number||'')+' '+(q.reference||'')+' '+(q.customer_name||'')+' '+(q.subject||'')+' #'+(q.id||'')).toLowerCase();
      if(hay.includes(qq)) return true;
      return digits!=='' && hay.replace(/\D/g,'').includes(digits);
    });
  }
  const _pg=pgSlice(filtered, MQ); const pages=_pg.pages; const slice=_pg.slice;

  const count=`<div class="muted" style="margin-bottom:10px;font-size:12px">${MQ.syncing?'Checking Zoho…':(filtered.length+' quote'+(filtered.length===1?'':'s')+(MQ.month==='all'?'':' · '+mqMonthLabel(MQ.month))+(qq?(' · matching “'+qesc(MQ.q)+'”'):''))}</div>`;

  const cards = slice.length ? slice.map(q=>{
    const pushed=!!q.zoho_estimate_id; const isOpen=!!MQ.open[q.id]; const busy=MQ.busyId===q.id;
    const acc=quoteAccent(q.status);
    const canSend=pushed && ['approved','sent','accepted'].includes(q.status);
    // lifecycle: approved → (Convert to project) → project → (Bill client) → invoiced
    const isProject=q.status==='project';
    const isInvoiced=q.status==='invoiced'||!!q.zoho_invoice_number;
    const alreadyProject=(q.is_project==1||q.is_project===true);
    // convert an approved quote OR an already-invoiced quote (that isn't already a project) into a project
    const canProject=ME.admin && pushed && !alreadyProject && (['approved','sent','accepted'].includes(q.status) || isInvoiced);
    const canBill=ME.admin && isProject;
    const canCost=(isProject||isInvoiced) && (ME.admin || ME.tabs==='*' || (ME.tabs||[]).includes('costcap') || (ME.tabs||[]).includes('projects') || q.created_by===ME.user);
    const date=(q.quote_date||String(q.created_at||'').slice(0,10))||'';
    const meta=[ pushed?`<span style="color:var(--ink);font-weight:600">${qesc(q.zoho_estimate_number||'—')}</span>`:null,
                 q.zoho_invoice_number?`<span style="color:var(--blue);font-weight:700">${qesc(q.zoho_invoice_number)}</span>`:null,
                 ME.admin?('by '+qesc(q.created_by)):null,
                 `${q.line_items.length} item${q.line_items.length===1?'':'s'}`,
                 date?date:null ].filter(Boolean).join(' · ');
    return `<div class="card qcard" style="border-left:4px solid ${acc}">
      <div class="row" style="align-items:flex-start;gap:12px">
        <div style="min-width:0;flex:1">
          <b style="font-size:14px">${qesc(q.customer_name||'(no customer)')}</b>
          ${q.subject?`<div style="font-size:12px;color:var(--ink);font-weight:500;margin-top:2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${qesc(q.subject)}</div>`:''}
          <div class="muted" style="margin-top:3px;font-size:11.5px">${meta}</div>
        </div>
        <div style="text-align:right;white-space:nowrap">
          <div style="color:var(--orange);font-weight:700;font-size:15px">${qesc(q.currency||'KES')} ${fmtn(q.total)}</div>
          <div style="margin-top:5px">${quoteBadge(q.status)}</div>
          ${ME.admin?(()=>{const useActual=isProject||isInvoiced;const p=(useActual?q.actual_profit:q.profit)||0;return `<div style="margin-top:5px;font-size:10.5px;font-weight:600;color:${p<0?'var(--bad)':'var(--good)'}">${useActual?'Actual profit':'Profit'} ${fmtn(p)}</div>`;})():''}
        </div>
      </div>
      <div class="qact">
        <button class="btn sec qb" ${tip('See the items and totals on this quote')} onclick="mqTogglePreview(${q.id})">${isOpen?'▴ Hide':'▾ Preview'}</button>
        ${(ME.admin && q.status==='pending_approval')?`<button class="btn qb" style="background:var(--good);box-shadow:none" onclick="mqApprove(${q.id})" ${busy?'disabled':''}>${busy?'Approving…':'✓ Approve'}</button>
        <button class="btn sec qb" style="color:var(--bad);border-color:#F4C7C0" onclick="mqDecline(${q.id})" ${busy?'disabled':''}>✕ Decline</button>`:''}
        ${canSend?`<button class="btn qb" ${tip('Email this quote to the customer (PDF attached)')} onclick="mqSend(${q.id})" ${busy?'disabled':''}>${busy?'Sending…':'✉ Send to customer'}</button>`:''}
        ${canProject?`<button class="btn qb" style="background:#7A5AF8;box-shadow:none" ${tip('Start the job: capture actual costs against this quote before billing')} onclick="mqToProject(${q.id})" ${busy?'disabled':''}>${busy?'Working…':'📋 Convert to project'}</button>`:''}
        ${canCost?`<button class="btn qb" style="background:var(--good);box-shadow:none" ${tip('Enter or edit the actual costs that went into this job, broken down per line')} onclick="jcOpen(${q.id},'capture')">${isInvoiced?'💰 Capture costs':'💰 Costs'}</button>`:''}
        ${canBill?`<button class="btn qb" style="background:var(--blue);box-shadow:none" ${tip('Job done? Review costs vs profit, create the invoice, and post the expenses to Zoho')} onclick="jcOpen(${q.id},'bill')" ${busy?'disabled':''}>${busy?'Working…':'🧾 Bill client'}</button>`:''}
        ${(ME.admin && isInvoiced)?`<button class="btn sec qb" ${tip('Open the printable job card')} onclick="openJobCard(${q.id})">⤓ Job card</button>`:''}
        ${(ME.admin && (isProject||isInvoiced))?`<button class="btn sec qb" ${tip('Bill of Quantities — materials required (labour excluded), branded PDF')} onclick="openBoq(${q.id})">⤓ BOQ</button>`:''}
        ${(isInvoiced && !ME.admin)?`<span class="pill" style="background:#EEF2FE;color:var(--blue);align-self:center;padding:5px 10px" ${tip('This quote has been invoiced')}>🧾 Invoiced · ${qesc(q.zoho_invoice_number||'')}</span>`:''}
        ${pushed?`<button class="btn sec qb" ${tip('Download this quote as a PDF')} onclick="mqPdf(${q.id})">⤓ PDF</button>`:''}
        ${mqEditable(q)?`<button class="btn sec qb" ${tip('Edit this quote')} onclick="mqEdit(${q.id})">✎ Edit</button>`:''}
        ${(ME.admin && (q.imported==1||q.imported===true) && !isProject && !isInvoiced && q.zoho_estimate_id)?`<button class="btn sec qb" ${tip('Re-pull the latest line items and totals from Zoho (if you changed them there)')} onclick="mqRefreshZoho(${q.id},'${qesc(q.zoho_estimate_id)}')" ${busy?'disabled':''}>${busy?'Refreshing…':'↻ Refresh from Zoho'}</button>`:''}
        ${!pushed?`<button class="btn qb" ${tip('Send this quote to Zoho for approval')} onclick="mqPush(${q.id})" ${busy?'disabled':''}>${busy?'Pushing…':'Push to Zoho →'}</button>`:''}
        ${pushed?`<button class="btn sec qb" ${tip('Check the latest approval status from Zoho')} onclick="mqSyncOne(${q.id})" ${busy?'disabled':''}>${busy?'Checking…':'↻ Status'}</button>`:''}
        ${ME.admin?`<select class="qb" title="Change status" onchange="mqSetStatus(${q.id},this.value)" style="width:auto;font-size:11.5px;padding:6px 8px;margin-bottom:0;border-radius:9px">
          ${[['draft','Draft'],['pending_approval','Pending'],['approved','Approved'],['declined','Declined'],['sent','Sent'],['accepted','Accepted'],['project','In progress'],['invoiced','Invoiced'],['expired','Expired']].map(s=>`<option value="${s[0]}" ${q.status===s[0]?'selected':''}>${s[1]}</option>`).join('')}
        </select>`:''}
        <button class="btn sec qb qb-del" onclick="mqDelete(${q.id})" title="Remove from app">✕</button>
      </div>
      ${isOpen?mqPreviewHtml(q):''}
    </div>`; }).join('')
    : `<div class="card muted" style="text-align:center;padding:22px">${all.length?'No quotes match the filters.':'No quotes yet. Make one under <b>New Quote</b>.'}</div>`;

  const pages2=pages;
  const pager = pages2>1 ? `<div class="row" style="justify-content:center;gap:8px;margin-top:14px">
      <button class="btn sec" style="width:auto;padding:6px 12px;font-size:12px" onclick="mqGoPage(${MQ.page-1})" ${MQ.page<=1?'disabled':''}>‹ Prev</button>
      <span class="muted" style="font-size:12px">Page ${MQ.page} of ${pages2}</span>
      <button class="btn sec" style="width:auto;padding:6px 12px;font-size:12px" onclick="mqGoPage(${MQ.page+1})" ${MQ.page>=pages2?'disabled':''}>Next ›</button>
    </div>` : '';
  // admins: if a search doesn't match locally, it may be a quote made straight in Zoho — offer to import it
  const zohoPanel = (ME.admin && qq) ? `
    <div class="card" style="margin-top:14px;padding:14px">
      <div class="row" style="align-items:center;gap:10px;flex-wrap:wrap">
        <div style="flex:1;min-width:0"><b style="font-size:12.5px">Made this quote in Zoho?</b>
          <div class="muted" style="font-size:11.5px">Quotes created directly in Zoho aren't here until you import them. Search Zoho and pull it in.</div></div>
        <button class="btn" style="width:auto;padding:7px 14px;font-size:12px" onclick="mqZohoSearch()" ${ZQ.loading?'disabled':''}>${ZQ.loading?'Searching Zoho…':('🔎 Search Zoho for “'+qesc(MQ.q)+'”')}</button>
      </div>
      ${(ZQ.forQ===qq && ZQ.results)?mqZohoResultsHtml():''}
      ${ZQ.msg?`<div class="${ZQ.err?'warn':'ok'}" style="margin-top:10px;font-size:11.5px">${qesc(ZQ.msg)}</div>`:''}
    </div>` : '';
  return count + cards + pager + zohoPanel;
}
function mqSearch(v){ MQ.q=v; MQ.page=1; const b=document.getElementById('mqListBox'); if(b) b.innerHTML=mqListHtml(); }

/* ---- Import a quote that was created directly in Zoho ---- */
var ZQ={loading:false,results:null,msg:'',err:false,forQ:''};
function mqRepaintList(){ const b=document.getElementById('mqListBox'); if(b) b.innerHTML=mqListHtml(); }
function mqZohoResultsHtml(){
  const r=ZQ.results||[];
  if(!r.length) return `<div class="muted" style="margin-top:10px;font-size:11.5px">No Zoho estimates matched “${qesc(MQ.q)}”.</div>`;
  return `<div style="margin-top:10px;display:flex;flex-direction:column;gap:6px">${r.map(e=>`
    <div class="row" style="align-items:center;gap:10px;border:1px solid var(--line);border-radius:9px;padding:8px 10px">
      <div style="flex:1;min-width:0"><b style="font-size:12px">${qesc(e.number||'—')}</b>
        <span class="muted" style="font-size:11px">· ${qesc(e.customer||'')} · ${qesc(e.date||'')} · ${qesc(e.currency||'KES')} ${fmtn(e.total||0)} · ${qesc(e.status||'')}</span></div>
      ${e.imported?`<span class="pill" style="background:#E7F6EC;color:#0F7A34;padding:4px 9px">✓ In app</span>`:`<button class="btn sec" style="width:auto;padding:5px 12px;font-size:12px" onclick="mqImport('${qesc(e.estimate_id)}')">Import</button>`}
    </div>`).join('')}</div>`;
}
function mqZohoSearch(){ const q=(MQ.q||'').trim(); if(!q)return; ZQ.loading=true; ZQ.msg=''; ZQ.forQ=q.toLowerCase(); mqRepaintList();
  fetch('api/quote_import.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'search',q})})
  .then(r=>r.json()).then(j=>{ ZQ.loading=false; if(j.ok){ ZQ.results=j.items||[]; ZQ.forQ=q.toLowerCase(); } else { ZQ.msg=j.error||'Zoho search failed.'; ZQ.err=true; } mqRepaintList(); })
  .catch(e=>{ ZQ.loading=false; ZQ.msg='Error: '+e; ZQ.err=true; mqRepaintList(); });
}
function mqImport(eid){ ZQ.msg='';
  fetch('api/quote_import.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'import',estimate_id:eid})})
  .then(r=>r.json()).then(j=>{
    if(j.ok){ const w=j.warnings||[]; ZQ.msg='Imported '+((j.quote&&j.quote.zoho_estimate_number)||'')+(j.already?' (already in app).':' — it now appears in your quotes.')+(w.length?(' ⚠ '+w.join(' ')):''); ZQ.err=w.length>0;
      if(ZQ.results){ const it=ZQ.results.find(x=>x.estimate_id===eid); if(it){ it.imported=true; it.local_id=(j.quote&&j.quote.id)||0; } }
      MQ.loaded=false; mqLoad(); mqRepaintList();
    } else { ZQ.msg=j.error||'Import failed.'; ZQ.err=true; mqRepaintList(); }
  }).catch(e=>{ ZQ.msg='Error: '+e; ZQ.err=true; mqRepaintList(); });
}

function vMyQuotes(){
  const all=MQ.quotes||[];
  const monthKeys=[...new Set(all.map(mqMonthKey).filter(k=>k&&k.length>=7))].sort().reverse();
  const creators=[...new Set(all.map(q=>q.created_by).filter(Boolean))].sort();

  const statusFilter = `
    <div style="display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin-bottom:${(ME.admin && creators.length>1)?'8px':'12px'}">
      <span class="muted" style="font-size:11px">Status:</span>
      <button class="btn${MQ.statuses.length===0?'':' sec'}" style="width:auto;padding:4px 11px;font-size:11px" onclick="mqClearStatus()">All</button>
      ${STATUS_GROUPS.map(g=>`<button class="btn${MQ.statuses.includes(g.key)?'':' sec'}" style="width:auto;padding:4px 11px;font-size:11px" onclick="mqToggleStatus('${g.key}')">${g.label}</button>`).join('')}
    </div>`;

  const userFilter = (ME.admin && creators.length>1) ? `
    <div style="display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin-bottom:12px">
      <span class="muted" style="font-size:11px">Created by:</span>
      <button class="btn${MQ.users.length===0?'':' sec'}" style="width:auto;padding:4px 11px;font-size:11px" onclick="mqClearUsers()">Everyone</button>
      ${creators.map(u=>`<button class="btn${MQ.users.includes(u)?'':' sec'}" style="width:auto;padding:4px 11px;font-size:11px" onclick="mqToggleUser('${qesc(u).replace(/'/g,'&#39;')}')">${qesc(u)}</button>`).join('')}
    </div>` : '';

  const monthSel = `<select onchange="mqSetMonth(this.value)" style="width:auto;margin-bottom:0;font-size:12px;padding:6px 10px">
      <option value="all" ${MQ.month==='all'?'selected':''}>All months</option>
      ${monthKeys.map(k=>`<option value="${k}" ${MQ.month===k?'selected':''}>${mqMonthLabel(k)}</option>`).join('')}
    </select>`;

  return `<h2>My quotes</h2>
    ${MQ.msg?`<div class="${MQ.err?'warn':'ok'}" style="margin-bottom:10px">${qesc(MQ.msg)}</div>`:''}
    <div class="row" style="margin-bottom:12px;gap:8px;flex-wrap:wrap;align-items:center">
      <input id="mqSearch" type="text" autocomplete="off" ${tip('Find by quote/estimate number, invoice number, reference, client or subject')} placeholder="🔍 Search quote #, invoice #, client or subject…" value="${qesc(MQ.q||'')}" oninput="mqSearch(this.value)" style="flex:1;min-width:200px;margin-bottom:0">
      <span style="display:inline-flex;gap:8px;align-items:center">${monthSel}${pgSel(MQ.perPage,'mqPerPage(this.value)')}
        <button class="btn sec" style="width:auto;padding:6px 12px;font-size:12px" onclick="mqSync()" ${MQ.syncing?'disabled':''}>${MQ.syncing?'Syncing…':'↻ Refresh statuses'}</button></span>
    </div>
    ${statusFilter}
    ${userFilter}
    <div id="mqListBox">${mqListHtml()}</div>`;
}
function mqTogglePreview(id){ MQ.open[id]=!MQ.open[id]; render(); }
function mqSetMonth(v){ MQ.month=v; MQ.page=1; render(); }
function mqGoPage(n){ MQ.page=n; render(); }
function mqPerPage(v){ MQ.perPage=parseInt(v,10)||50; MQ.page=1; const b=document.getElementById('mqListBox'); if(b) b.innerHTML=mqListHtml(); }
function mqToggleUser(u){ const i=MQ.users.indexOf(u); if(i>=0) MQ.users.splice(i,1); else MQ.users.push(u); MQ.page=1; render(); }
function mqClearUsers(){ MQ.users=[]; MQ.page=1; render(); }
function mqToggleStatus(k){ const i=MQ.statuses.indexOf(k); if(i>=0) MQ.statuses.splice(i,1); else MQ.statuses.push(k); MQ.page=1; render(); }
function mqClearStatus(){ MQ.statuses=[]; MQ.page=1; render(); }
function mqSend(id){ MQ.busyId=id; MQ.msg=''; render();
  fetch('api/quote_send.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({id})})
  .then(r=>r.json()).then(j=>{ MQ.busyId=0;
    if(j.ok){ MQ.msg='Sent to '+(j.email||'the customer')+(j.number?(' ('+j.number+')'):'')+'.'; MQ.err=false; const q=MQ.quotes.find(x=>x.id===id); if(q)q.status='sent'; }
    else { MQ.msg=j.error||'Send failed.'; MQ.err=true; } render();
  }).catch(e=>{ MQ.busyId=0; MQ.msg='Error: '+e; MQ.err=true; render(); });
}
function mqPdf(id){ window.open('api/quote_pdf.php?id='+id,'_blank'); }
function mqJobCard(id){
  if(!confirm('Have you completed this job?\n\nGenerating the Job Card will mark the job done and create the INVOICE in Zoho. This cannot be undone.')) return;
  if(!confirm('Please confirm once more: is the job fully complete and ready to invoice the customer?')) return;
  MQ.busyId=id; MQ.msg=''; render();
  fetch('api/quote_invoice.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({id})})
  .then(r=>r.json()).then(j=>{ MQ.busyId=0;
    if(j.ok){ const q=MQ.quotes.find(x=>x.id===id); if(q){ q.status='invoiced'; q.zoho_invoice_number=j.invoice_number; }
      MQ.msg='Job card ready — invoice '+(j.invoice_number||'')+(j.already?' (already invoiced).':' created in Zoho.'); MQ.err=false;
      openJobCard(id); render(); }
    else { MQ.msg=j.error||'Could not generate job card.'; MQ.err=true; render(); }
  }).catch(e=>{ MQ.busyId=0; MQ.msg='Error: '+e; MQ.err=true; render(); });
}
function mqSetStatus(id,status){ MQ.busyId=id; MQ.msg=''; render();
  fetch('api/quote_set_status.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({id,status})})
  .then(r=>r.json()).then(j=>{ MQ.busyId=0;
    if(j.ok){ const q=MQ.quotes.find(x=>x.id===id); if(q)q.status=j.status; MQ.msg='Status set to '+j.status+'.'+(j.note?' '+j.note:''); MQ.err=!!j.note; }
    else { MQ.msg=j.error||'Could not change status.'; MQ.err=true; } render();
  }).catch(e=>{ MQ.busyId=0; MQ.msg='Error: '+e; MQ.err=true; render(); });
}
function mqApprove(id){ MQ.busyId=id; MQ.msg=''; render();
  fetch('api/quote_approve.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({id})})
  .then(r=>r.json()).then(j=>{ MQ.busyId=0;
    if(j.ok){ const q=MQ.quotes.find(x=>x.id===id); if(q)q.status=j.status||'approved'; MQ.msg='Quote '+(j.number||'')+' approved ✓'; MQ.err=false; }
    else { MQ.msg=j.error||'Approve failed.'; MQ.err=true; } render();
  }).catch(e=>{ MQ.busyId=0; MQ.msg='Error: '+e; MQ.err=true; render(); });
}
function mqDecline(id){ if(!confirm('Decline this quote? It will be marked Declined.'))return; MQ.busyId=id; MQ.msg=''; render();
  fetch('api/quote_decline.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({id})})
  .then(r=>r.json()).then(j=>{ MQ.busyId=0;
    if(j.ok){ const q=MQ.quotes.find(x=>x.id===id); if(q)q.status='declined'; MQ.msg='Quote '+(j.number||'')+' declined.'+(j.zohoUpdated?'':' (Not reflected in Zoho — decline it there too if needed.)'); MQ.err=!j.zohoUpdated; }
    else { MQ.msg=j.error||'Decline failed.'; MQ.err=true; } render();
  }).catch(e=>{ MQ.busyId=0; MQ.msg='Error: '+e; MQ.err=true; render(); });
}
function mqLoad(){ MQ.loading=true;
  fetch('api/quotes.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'list'})})
  .then(r=>r.json()).then(j=>{ MQ.loading=false; if(j.ok){ MQ.quotes=mqNormalize(j.quotes||[]); MQ.loaded=true; if(TAB==='myquotes'){ render(); if(MQ.quotes.some(q=>q.zoho_estimate_id)) mqSync(true); } } })
  .catch(()=>{ MQ.loading=false; });
}
function mqSync(silent){ if(MQ.syncing)return; MQ.syncing=true; if(!silent) MQ.msg=''; if(TAB==='myquotes')render();
  fetch('api/quote_sync.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({})})
  .then(r=>r.json()).then(j=>{ MQ.syncing=false;
    if(j.ok){ (j.quotes||[]).forEach(u=>{ const q=MQ.quotes.find(x=>x.id===u.id); if(q) q.status=u.status; }); mqNormalize(MQ.quotes); }
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
    QB.items=(q.line_items||[]).map(it=>({lid:it.lid||newLid(),name:it.name,description:it.description||'',qty:it.qty,rate:it.rate,cost:it.cost||0,acost:it.actual_cost||0,tax:it.tax||'vat'})); if(!QB.items.length)QB.items=[{lid:newLid(),name:'',description:'',qty:1,rate:0,cost:0,acost:0,tax:'vat'}];
    QB.notes=q.notes||''; QB.terms=q.terms||''; QB.discVal=q.discount_value||0; QB.discType=q.discount_type||'percent'; QB.msg=''; QB.err=false; qbOpen();
  }).catch(e=>alert(''+e)); }
function mqRefreshZoho(id,eid){ if(!confirm("Re-pull this quote's line items and totals from Zoho?\n\nThis replaces the current line items with Zoho's latest (any budgeted costs set here are reset)."))return;
  MQ.busyId=id; MQ.msg=''; render();
  fetch('api/quote_import.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'import',estimate_id:eid})})
  .then(r=>r.json()).then(j=>{ MQ.busyId=0;
    if(j.ok){ const w=j.warnings||[]; MQ.msg='Refreshed from Zoho.'+(w.length?(' ⚠ '+w.join(' ')):''); MQ.err=w.length>0; MQ.loaded=false; mqLoad(); }
    else { MQ.msg=j.error||'Refresh failed.'; MQ.err=true; render(); }
  }).catch(e=>{ MQ.busyId=0; MQ.msg='Error: '+e; MQ.err=true; render(); });
}
function mqDelete(id){ if(!confirm('Remove this quote from the app? (If it was pushed, it stays in Zoho.)'))return;
  fetch('api/quotes.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'delete',id})})
  .then(r=>r.json()).then(j=>{ if(j.ok){ MQ.quotes=MQ.quotes.filter(q=>q.id!==id); render(); } else alert(j.error||'Delete failed'); }).catch(e=>alert(''+e)); }

/* ---------- Job costing: capture the actual cost of each invoice line ----------
   Two modes:
     'capture' — edit actual costs on an already-invoiced job (post-invoice)
     'convert' — review budgeted vs actual before converting a quote to an invoice
   In both, actual costs post to Zoho as expenses attached to the invoice. */
var JC={id:0,quote:null,admin:false,mode:'capture',lines:[],busy:false,msg:'',err:false,warnings:[],config:{account_id:'',paid_through_account_id:''}};
var JCACC=null;   // cached expense/paid-through accounts for the admin booking picker
const JC_CATS=[['parts','Parts'],['labour','Labour'],['consumables','Consumables'],['subcontract','Subcontract'],['other','Other']];

function jcLinesFrom(q){ return (q.line_items||[]).map(it=>{
  const qty=it.qty!=null?it.qty:1, bcost=(it.cost!=null?it.cost:0);
  return { lid:it.lid||'', name:it.name||'', description:it.description||'', qty:qty,
    amount:(it.amount!=null?it.amount:null),   // absent for non-admins (price-stripped server-side)
    bcost:bcost, budget:Math.round(qty*bcost*100)/100,   // budgeted unit + line cost
    rows:(it.cost_rows||[]).map(r=>({id:r.id||0,category:r.category||'other',description:r.description||'',qty:r.qty!=null?r.qty:1,unit_cost:r.unit_cost!=null?r.unit_cost:0,vat:(r.vat==='plus'||r.vat==='incl')?r.vat:'none'})) };
}); }

function jcOpen(id,mode){
  mode=(mode==='bill')?'bill':'capture';
  JC={id:id,quote:null,admin:false,mode:mode,lines:[],busy:false,msg:'',err:false,warnings:[],config:{account_id:'',paid_through_account_id:''}};
  document.getElementById('jcModalBody').innerHTML='<div class="muted" style="padding:22px;text-align:center">Loading…</div>';
  document.getElementById('jcModalTitle').textContent=(mode==='bill')?'Review & bill':'Capture costs';
  document.getElementById('jcModal').classList.add('open'); document.body.style.overflow='hidden';
  const finish=()=>{ jcAutoDefaultBooking(); jcRender(); };
  const done=()=>{ if(JC.admin && !JCACC){ fetch('api/accounts.php',{credentials:'same-origin'}).then(r=>r.json()).then(a=>{ if(a.ok) JCACC={expense:a.expense||[],paid:a.paid||[]}; finish(); }).catch(()=>jcRender()); } else { finish(); } };
  // both modes load line items + the project's captured cost rows (from the project_costs table)
  fetch('api/quote_costs.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'get',id})})
  .then(r=>r.json()).then(j=>{
    if(!j.ok){ document.getElementById('jcModalBody').innerHTML='<div class="warn" style="margin:10px">'+qesc(j.error||'Could not load')+'</div>'; return; }
    JC.quote=j.quote; JC.admin=!!j.admin; JC.lines=jcLinesFrom(j.quote); if(j.config) JC.config=j.config;
    done();
  }).catch(e=>{ document.getElementById('jcModalBody').innerHTML='<div class="warn" style="margin:10px">Error: '+qesc(''+e)+'</div>'; });
}
function jcClose(){ document.getElementById('jcModal').classList.remove('open'); document.body.style.overflow=''; }

/* Pick a sensible default booking account the first time (Cost of Goods Sold + a KES bank)
   and save it, so captured costs post to Zoho without an admin having to set anything. */
function jcAutoDefaultBooking(){
  if(!JC.admin || !JCACC) return;
  if(JC.config && JC.config.account_id) return;          // already configured — leave it
  const exp=JCACC.expense||[], paid=JCACC.paid||[];
  const cogs = exp.find(a=>a.type==='cost_of_goods_sold') || exp.find(a=>/cost of goods|cogs/i.test(a.name||'')) || exp[0];
  if(!cogs) return;
  const pay = paid.find(a=>/kes/i.test(a.name||'')) || paid[0];
  const acc=cogs.id, pth=pay?pay.id:'';
  JC.config={account_id:acc,paid_through_account_id:pth};   // optimistic so the UI/warning updates now
  fetch('api/quote_costs.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'config_set',account_id:acc,paid_through_account_id:pth})})
    .then(r=>r.json()).then(j=>{ if(j.ok&&j.config){ JC.config=j.config; jcRender(); } }).catch(()=>{});
}
function jcAddBudgetRow(li){ const l=JC.lines[li]; l.rows.push({id:0,category:'other',description:'Budgeted estimate',qty:l.qty||1,unit_cost:l.bcost||0,vat:'none'}); jcRender(); }

function jcSaveConfig(){
  const acc=document.getElementById('jcAcc'), paid=document.getElementById('jcPaid');
  fetch('api/quote_costs.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'config_set',account_id:acc?acc.value:'',paid_through_account_id:paid?paid.value:''})})
  .then(r=>r.json()).then(j=>{ if(j.ok){ JC.config=j.config; JC.msg='Booking account saved.'; JC.err=false; } else { JC.msg=j.error||'Could not save booking.'; JC.err=true; } jcRender(); })
  .catch(e=>{ JC.msg='Error: '+e; JC.err=true; jcRender(); });
}

function jcVatRate(){ return (typeof CFG!=='undefined' && CFG.vat)?CFG.vat:0.16; }
function jcVatMode(r){ return (r.vat==='plus'||r.vat==='incl')?r.vat:'none'; }
function jcRowAmt(r){ const q=parseFloat(r.qty)||0, u=parseFloat(r.unit_cost)||0; return Math.round(q*u*100)/100; }   /* entered line amount */
function jcRowExVat(r){ const a=jcRowAmt(r); return (jcVatMode(r)==='incl')? Math.round(a/(1+jcVatRate())*100)/100 : a; }   /* net (for profit) */
function jcRowVat(r){ const a=jcRowAmt(r),m=jcVatMode(r); if(m==='incl')return Math.round((a-a/(1+jcVatRate()))*100)/100; if(m==='plus')return Math.round(a*jcVatRate()*100)/100; return 0; }
function jcRowGross(r){ return Math.round((jcRowExVat(r)+jcRowVat(r))*100)/100; }   /* total spent */
function jcLineCost(l){ return (l.rows||[]).reduce((s,r)=>s+jcRowExVat(r),0); }   /* ex-VAT cost — VAT is never in profit */
function jcTotalCost(){ return JC.lines.reduce((s,l)=>s+jcLineCost(l),0); }
function jcTotalVat(){ return JC.lines.reduce((s,l)=>s+(l.rows||[]).reduce((a,r)=>a+jcRowVat(r),0),0); }
function jcTotalGross(){ return jcTotalCost()+jcTotalVat(); }
function jcTotalCharged(){ return JC.lines.reduce((s,l)=>s+(parseFloat(l.amount)||0),0); }
function jcTotalBudget(){ return JC.lines.reduce((s,l)=>s+(parseFloat(l.budget)||0),0); }
function jcAddRow(li){ JC.lines[li].rows.push({id:0,category:'other',description:'',qty:1,unit_cost:0,vat:'none'}); jcRender(); }
function jcDelRow(li,ri){ JC.lines[li].rows.splice(ri,1); jcRender(); }

/* update only the computed figures so typing doesn't lose input focus */
function jcRepaint(){
  JC.lines.forEach((l,li)=>{
    (l.rows||[]).forEach((r,ri)=>{ const e=document.getElementById('jcamt-'+li+'-'+ri); if(e) e.textContent=fmtn(jcRowAmt(r)); });
    const lc=document.getElementById('jclc-'+li); if(lc) lc.textContent=fmtn(jcLineCost(l));
    if(JC.admin){ const lp=document.getElementById('jclp-'+li); if(lp){ const ch=parseFloat(l.amount)||0, pr=ch-jcLineCost(l); lp.textContent=fmtn(pr)+(ch>0?(' · '+Math.round(pr/ch*100)+'%'):''); lp.style.color=pr<0?'var(--bad)':'var(--good)'; } }
  });
  const tc=document.getElementById('jctc'); if(tc) tc.textContent=fmtn(jcTotalCost());
  const tv=document.getElementById('jctvat'); if(tv) tv.textContent=fmtn(jcTotalVat());
  const tg=document.getElementById('jctgross'); if(tg) tg.textContent=fmtn(jcTotalGross());
  if(JC.admin){ const tp=document.getElementById('jctp'); if(tp){ const ch=jcTotalCharged(), pr=ch-jcTotalCost(); tp.textContent=fmtn(pr)+(ch>0?(' · '+Math.round(pr/ch*100)+'%'):''); tp.style.color=pr<0?'var(--bad)':'var(--good)'; } }
}

function jcRender(){
  const q=JC.quote, box=document.getElementById('jcModalBody'); if(!q||!box)return;
  const conv=(JC.mode==='bill');
  document.getElementById('jcModalTitle').textContent=(conv?'Review & bill · ':'Capture costs · ')+(q.customer_name||'')+((!conv&&q.zoho_invoice_number)?(' · '+q.zoho_invoice_number):'');
  const catOpts=(sel)=>JC_CATS.map(c=>`<option value="${c[0]}" ${sel===c[0]?'selected':''}>${c[1]}</option>`).join('');
  // admin: pick the Zoho expense account these costs book to
  const booking = JC.admin ? `
    <div class="card" style="padding:10px 12px;margin-bottom:12px;background:var(--surface-2)">
      <div style="font-size:11.5px;color:var(--mute);margin-bottom:6px">Captured costs post to Zoho as expenses on this invoice. Booking account:</div>
      <div class="row" style="gap:8px;flex-wrap:wrap;align-items:center">
        <select id="jcAcc" class="in" style="margin:0;width:auto;min-width:210px;font-size:12px">
          <option value="">— choose expense account —</option>
          ${(JCACC?JCACC.expense:[]).map(a=>`<option value="${a.id}" ${JC.config.account_id===a.id?'selected':''}>${qesc(a.name)}</option>`).join('')}
        </select>
        <select id="jcPaid" class="in" style="margin:0;width:auto;min-width:180px;font-size:12px">
          <option value="">— paid through (optional) —</option>
          ${(JCACC?JCACC.paid:[]).map(a=>`<option value="${a.id}" ${JC.config.paid_through_account_id===a.id?'selected':''}>${qesc(a.name)}</option>`).join('')}
        </select>
        <button class="btn sec" style="width:auto;padding:5px 12px;font-size:12px" onclick="jcSaveConfig()">Save booking</button>
      </div>
      ${JC.config.account_id?'':`<div class="warn" style="margin-top:8px;font-size:11.5px">Set a booking account so captured costs post to Zoho.</div>`}
    </div>` : '';
  const warns = (JC.warnings&&JC.warnings.length) ? `<div class="warn" style="margin-bottom:10px;font-size:11.5px">${JC.warnings.map(w=>'⚠ '+qesc(w)).join('<br>')}</div>` : '';
  const lines=JC.lines.map((l,li)=>{
    const rows=(l.rows||[]).map((r,ri)=>`
      <tr>
        <td><select class="in" style="margin:0;padding:5px 6px;font-size:12px" onchange="JC.lines[${li}].rows[${ri}].category=this.value">${catOpts(r.category)}</select></td>
        <td><input class="in" style="margin:0;padding:5px 7px;font-size:12px" placeholder="e.g. Paint, beating labour…" value="${qesc(r.description||'')}" oninput="JC.lines[${li}].rows[${ri}].description=this.value"></td>
        <td><input class="in" type="number" step="0.01" min="0" style="margin:0;padding:5px 6px;font-size:12px;width:66px;text-align:right" value="${r.qty}" oninput="JC.lines[${li}].rows[${ri}].qty=this.value;jcRepaint()"></td>
        <td><input class="in" type="number" step="0.01" min="0" style="margin:0;padding:5px 6px;font-size:12px;width:94px;text-align:right" value="${r.unit_cost}" oninput="JC.lines[${li}].rows[${ri}].unit_cost=this.value;jcRepaint()"></td>
        <td><select class="in" style="margin:0;padding:5px 4px;font-size:11.5px" onchange="JC.lines[${li}].rows[${ri}].vat=this.value;jcRepaint()"><option value="none" ${jcVatMode(r)==='none'?'selected':''}>No VAT</option><option value="plus" ${jcVatMode(r)==='plus'?'selected':''}>+ 16% VAT</option><option value="incl" ${jcVatMode(r)==='incl'?'selected':''}>VAT incl.</option></select></td>
        <td style="text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap;padding:0 6px"><b id="jcamt-${li}-${ri}">${fmtn(jcRowAmt(r))}</b></td>
        <td style="text-align:center"><button class="btn sec" style="width:auto;padding:3px 8px;font-size:12px;color:var(--bad)" onclick="jcDelRow(${li},${ri})" title="Remove this cost">✕</button></td>
      </tr>`).join('');
    const ep=(parseFloat(l.amount)||0)-(parseFloat(l.budget)||0);   // expected (budgeted) line profit — static
    const hdr = JC.admin
      ? `<span style="font-size:11px;color:var(--mute);line-height:1.6;text-align:right;display:inline-block">
           Charged <b style="color:var(--ink)">${fmtn(l.amount||0)}</b><br>
           Budget <b style="color:var(--ink)">${fmtn(l.budget||0)}</b> · Actual <b id="jclc-${li}" style="color:var(--ink)">${fmtn(jcLineCost(l))}</b><br>
           Exp. profit <b style="color:${ep<0?'var(--bad)':'var(--good)'}">${fmtn(ep)}</b> · Act. profit <b id="jclp-${li}">—</b>
         </span>`
      : `<span style="font-size:11.5px;color:var(--mute)">Cost so far <b id="jclc-${li}" style="color:var(--ink)">${fmtn(jcLineCost(l))}</b></span>`;
    return `<div class="card" style="padding:12px;margin-bottom:12px">
      <div class="row" style="align-items:flex-start;gap:10px;margin-bottom:8px">
        <div style="min-width:0;flex:1"><b style="font-size:13.5px">${qesc(l.name||'(item)')}</b>${l.description?`<div class="muted" style="font-size:11.5px;margin-top:2px">${qesc(l.description)}</div>`:''}<div class="muted" style="font-size:11px;margin-top:2px">Qty ${fmtn(l.qty)}</div></div>
        <div style="text-align:right">${hdr}</div>
      </div>
      <div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse;font-size:12px">
        <thead><tr style="color:var(--mute);font-size:11px">
          <th style="padding:3px 6px;font-weight:600;text-align:left">Category</th><th style="padding:3px 6px;font-weight:600;text-align:left">Description</th>
          <th style="padding:3px 6px;font-weight:600;text-align:right">Qty</th><th style="padding:3px 6px;font-weight:600;text-align:right">Unit cost</th>
          <th style="padding:3px 6px;font-weight:600;text-align:left">VAT</th>
          <th style="padding:3px 6px;font-weight:600;text-align:right">Amount</th><th></th></tr></thead>
        <tbody>${rows||`<tr><td colspan="7" class="muted" style="padding:8px 6px;font-size:11.5px">No costs yet — add the parts, labour and materials that went into this line.</td></tr>`}</tbody>
      </table></div>
      <button class="btn sec" style="width:auto;padding:5px 12px;font-size:12px;margin-top:8px" onclick="jcAddRow(${li})">+ Add actual cost</button>
      ${JC.admin&&(l.budget>0)?`<button class="btn sec" style="width:auto;padding:5px 12px;font-size:12px;margin-top:8px;margin-left:6px" onclick="jcAddBudgetRow(${li})" title="Add an actual-cost row seeded from the budgeted figure">+ from budget</button>`:''}
    </div>`;
  }).join('');
  const tExpP=jcTotalCharged()-jcTotalBudget();
  const footer = JC.admin
    ? `<div class="card" style="padding:12px;margin-bottom:12px;background:var(--surface-2)">
        <div class="row" style="align-items:flex-start"><b style="font-size:12.5px">${conv?'INVOICE TOTAL':'PROJECT TOTAL'}</b>
        <span style="font-size:12px;color:var(--mute);text-align:right;line-height:1.7">
          Charged <b style="color:var(--ink)">${fmtn(jcTotalCharged())}</b> · Budgeted cost <b style="color:var(--ink)">${fmtn(jcTotalBudget())}</b><br>
          Net cost <b id="jctc" style="color:var(--ink)">${fmtn(jcTotalCost())}</b> · VAT <b id="jctvat" style="color:var(--ink)">${fmtn(jcTotalVat())}</b> · Total spent <b id="jctgross" style="color:var(--ink)">${fmtn(jcTotalGross())}</b><br>
          Expected profit <b style="color:${tExpP<0?'var(--bad)':'var(--good)'}">${fmtn(tExpP)}${jcTotalCharged()>0?(' · '+Math.round(tExpP/jcTotalCharged()*100)+'%'):''}</b> · Actual profit <b id="jctp">—</b>
        </span></div></div>`
    : `<div class="card" style="padding:12px;margin-bottom:12px;background:var(--surface-2)"><div class="row" style="align-items:flex-start"><b style="font-size:12.5px">TOTALS</b>
        <span style="font-size:12px;color:var(--mute);text-align:right;line-height:1.7">Net cost <b id="jctc" style="color:var(--ink)">${fmtn(jcTotalCost())}</b> · VAT <b id="jctvat" style="color:var(--ink)">${fmtn(jcTotalVat())}</b> · Total spent <b id="jctgross" style="color:var(--ink)">${fmtn(jcTotalGross())}</b></span></div></div>`;
  const vatNote = `<div class="muted" style="font-size:11px;margin-bottom:10px">💡 Per line, choose <b>No VAT</b>, <b>+ 16% VAT</b> (amount is net, VAT added — like a supplier receipt), or <b>VAT incl.</b> (amount already includes VAT). VAT is never counted in profit; “Net cost” is ex-VAT and “Total spent” includes VAT.</div>`;
  const intro = (conv
    ? `<div class="muted" style="font-size:11.5px;margin-bottom:6px">Review budgeted vs actual cost and profit, then bill. <b>Expected profit</b> uses the budgeted cost from the quote; <b>Actual profit</b> uses the actual costs entered here — which post to Zoho as expenses on the invoice.</div>`
    : (JC.admin
        ? `<div class="muted" style="font-size:11.5px;margin-bottom:6px">Enter the <b>actual cost</b> of each line (parts, labour, materials — several rows per line if needed). <b>Expected profit</b> comes from the budgeted cost; <b>Actual profit</b> comes from what you enter here (and is what posts to Zoho as expenses when you bill).</div>`
        : `<div class="muted" style="font-size:11.5px;margin-bottom:6px">Enter what each line actually cost, and whether it's Incl or Excl VAT. You won't see the price charged to the customer.</div>`)) + vatNote;
  const primary = conv
    ? `<button class="btn" style="width:auto;background:var(--blue);box-shadow:none" onclick="jcBill()" ${JC.busy?'disabled':''}>${JC.busy?'Billing…':'🧾 Bill client'}</button>`
    : `<button class="btn" style="width:auto" onclick="jcSave()" ${JC.busy?'disabled':''}>${JC.busy?'Saving…':'Save costs'}</button>`;
  box.innerHTML=(JC.msg?`<div class="${JC.err?'warn':'ok'}" style="margin-bottom:10px">${qesc(JC.msg)}</div>`:'')
    + warns + booking + intro
    + lines + footer
    + `<div class="row" style="justify-content:flex-end;gap:8px"><button class="btn sec" style="width:auto" onclick="jcClose()">Close</button>${primary}</div>`;
  jcRepaint();
}

function jcCollectLines(){
  return JC.lines.map((l,idx)=>({ index:idx, lid:l.lid||'', line_name:l.name||'', cost_rows:(l.rows||[])
    .filter(r=>(r.description||'').trim()!==''||(parseFloat(r.unit_cost)||0)>0)
    .map(r=>({id:r.id||0,category:r.category,description:r.description,qty:r.qty,unit_cost:r.unit_cost,vat:(r.vat==='plus'||r.vat==='incl')?r.vat:'none'})) }));
}

/* reflect updated actual cost/profit onto the My Quotes card */
function jcSyncCard(q){ const mq=(MQ.quotes||[]).find(x=>x.id===JC.id); if(mq&&q){ if(q.actual_cost!=null)mq.actual_cost=q.actual_cost; if(q.actual_profit!=null)mq.actual_profit=q.actual_profit; } }

function jcSave(){
  JC.busy=true; JC.msg=''; JC.warnings=[]; jcRender();
  fetch('api/quote_costs.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'save_costs',id:JC.id,lines:jcCollectLines()})})
  .then(r=>r.json()).then(j=>{ JC.busy=false;
    if(j.ok){ JC.quote=j.quote; JC.admin=!!j.admin; JC.lines=jcLinesFrom(j.quote); JC.warnings=j.warnings||[];
      JC.msg=(JC.warnings.length?'Costs saved (see notes below).':'Costs saved.'); JC.err=false;
      if(j.admin) jcSyncCard(j.quote);
      PROJ.loaded=false; if(TAB==='projects') projLoad();
      jcRender();
    } else { JC.msg=j.error||'Save failed.'; JC.err=true; jcRender(); }
  }).catch(e=>{ JC.busy=false; JC.msg='Error: '+e; JC.err=true; jcRender(); });
}

function jcBill(){
  const cust=(JC.quote&&JC.quote.customer_name)||'this client';
  if(!confirm('Bill '+cust+' now?\n\nThis creates the INVOICE in Zoho and posts the captured costs as expenses attached to it. This cannot be undone.')) return;
  if(!confirm('Please confirm once more.\n\nAre you sure you want to bill '+cust+' and create the invoice in Zoho?')) return;
  JC.busy=true; JC.msg=''; JC.warnings=[]; jcRender();
  // 1) persist the latest cost edits to the project, then 2) create the invoice + push expenses
  fetch('api/quote_costs.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'save_costs',id:JC.id,lines:jcCollectLines()})})
  .then(r=>r.json()).then(sj=>{
    if(!sj.ok){ JC.busy=false; JC.msg=sj.error||'Could not save costs.'; JC.err=true; jcRender(); return; }
    if(sj.quote) jcSyncCard(sj.quote);
    return fetch('api/quote_invoice.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({id:JC.id})})
      .then(r=>r.json()).then(j=>{ JC.busy=false;
        if(j.ok){
          const mq=(MQ.quotes||[]).find(x=>x.id===JC.id); if(mq){ mq.status='invoiced'; mq.zoho_invoice_number=j.invoice_number||mq.zoho_invoice_number; }
          const w=j.warnings||[];
          MQ.msg='Invoice '+(j.invoice_number||'')+(j.already?' (already invoiced).':' created — costs posted to Zoho.')+(w.length?(' ⚠ '+w.join(' ')):''); MQ.err=w.length>0;
          jcClose();
          openJobCard(JC.id);
          MQ.loaded=false; mqLoad(); PROJ.loaded=false; if(TAB==='projects') projLoad(); render();
        } else { JC.msg=j.error||'Could not bill.'; JC.err=true; jcRender(); }
      });
  }).catch(e=>{ JC.busy=false; JC.msg='Error: '+e; JC.err=true; jcRender(); });
}

function mqToProject(id){
  const q0=(MQ.quotes||[]).find(x=>x.id===id); const inv=q0&&q0.zoho_invoice_number;
  const msg = inv
    ? ('This quote is already INVOICED ('+inv+').\n\nConvert it into a project so you can capture costs against it? It stays invoiced.')
    : 'Start this job as a project? You can then capture actual costs against it before billing.';
  if(!confirm(msg)) return;
  MQ.busyId=id; MQ.msg=''; render();
  fetch('api/quote_project.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({id,action:'start'})})
  .then(r=>r.json()).then(j=>{ MQ.busyId=0;
    if(j.ok){ const q=(MQ.quotes||[]).find(x=>x.id===id); if(q){ q.status=j.status||q.status; q.is_project=1; } PROJ.loaded=false;
      MQ.msg=(inv?('Invoiced job '+inv+' added to Projects — capture costs there.'):'Project started — capture costs on the Projects page.'); MQ.err=false; }
    else { MQ.msg=j.error||'Could not start project.'; MQ.err=true; } render();
  }).catch(e=>{ MQ.busyId=0; MQ.msg='Error: '+e; MQ.err=true; render(); });
}

/* ---------- Projects page (jobs converted from quotes) ---------- */
var PROJ={loaded:false,loading:false,projects:[],admin:false,busyId:0,msg:'',err:false,q:'',filter:'open',perPage:25,page:1};
function projLoad(){ PROJ.loading=true; PROJ.msg='';
  fetch('api/projects.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'list'})})
  .then(r=>r.json()).then(j=>{ PROJ.loading=false; if(j.ok){ PROJ.projects=j.projects||[]; PROJ.admin=!!j.admin; PROJ.loaded=true; } else { PROJ.msg=j.error||'Could not load'; PROJ.err=true; } if(TAB==='projects')render(); })
  .catch(()=>{ PROJ.loading=false; if(TAB==='projects')render(); });
}
function projStageOf(p){ if(p.project_closed) return 'closed'; if(p.status==='invoiced'||p.zoho_invoice_number) return 'billed'; return 'open'; }
function projStageBadge(stage){ const m={open:['🟣 Open','#7A5AF8'],billed:['🔵 Billed','var(--blue)'],closed:['⚪ Closed','var(--mute)']}; const b=m[stage]||m.open; return `<span class="pill" style="color:${b[1]};background:var(--surface-2);border:1px solid var(--line)">${b[0]}</span>`; }
function projMatch(p,qq){
  const digits=qq.replace(/\D/g,'');
  const hay=((p.customer_name||'')+' '+(p.zoho_invoice_number||'')+' '+(p.zoho_estimate_number||'')+' '+(p.subject||'')+' #'+(p.id||'')).toLowerCase();
  if(hay.includes(qq)) return true;
  return digits!=='' && hay.replace(/\D/g,'').includes(digits);
}
function projSearch(v){ PROJ.q=v; PROJ.page=1; const b=document.getElementById('projListBox'); if(b) b.innerHTML=projListHtml(); }
function projRepaint(){ const b=document.getElementById('projListBox'); if(b) b.innerHTML=projListHtml(); }
function projGoPage(n){ PROJ.page=n; projRepaint(); }
function projPerPage(v){ PROJ.perPage=parseInt(v,10)||25; PROJ.page=1; projRepaint(); }
function projListHtml(){
  let list=PROJ.projects||[];
  if(PROJ.admin && (PROJ.filter||'open')!=='all') list=list.filter(p=>projStageOf(p)===(PROJ.filter||'open'));
  const qq=(PROJ.q||'').trim().toLowerCase();
  if(qq) list=list.filter(p=>projMatch(p,qq));
  const total=list.length;
  if(!total) return `<div class="card muted" style="text-align:center;padding:22px">No ${PROJ.admin&&PROJ.filter!=='all'?qesc(PROJ.filter)+' ':''}projects${qq?(' matching “'+qesc(PROJ.q)+'”'):''}.</div>`;
  const per=PROJ.perPage||25;
  const pages=Math.max(1,Math.ceil(total/per));
  if(PROJ.page>pages)PROJ.page=pages; if(PROJ.page<1)PROJ.page=1;
  const slice=list.slice((PROJ.page-1)*per, PROJ.page*per);
  const count=`<div class="muted" style="margin-bottom:8px;font-size:12px">${total} project${total===1?'':'s'}${qq?(' matching “'+qesc(PROJ.q)+'”'):''}${pages>1?(' · page '+PROJ.page+' of '+pages):''}</div>`;
  const cards=slice.map(PROJ.admin?projCardAdmin:projCardTeam).join('');
  const pager=pages>1?`<div class="row" style="justify-content:center;gap:8px;margin-top:14px">
      <button class="btn sec" style="width:auto;padding:6px 12px;font-size:12px" onclick="projGoPage(${PROJ.page-1})" ${PROJ.page<=1?'disabled':''}>‹ Prev</button>
      <span class="muted" style="font-size:12px">Page ${PROJ.page} of ${pages}</span>
      <button class="btn sec" style="width:auto;padding:6px 12px;font-size:12px" onclick="projGoPage(${PROJ.page+1})" ${PROJ.page>=pages?'disabled':''}>Next ›</button>
    </div>`:'';
  return count+cards+pager;
}
function projPerPageSel(){ return `<select onchange="projPerPage(this.value)" ${tip('Records per page')} style="width:auto;margin:0;font-size:12px;padding:6px 8px">${[10,15,25,50,100].map(n=>`<option value="${n}" ${(PROJ.perPage||25)===n?'selected':''}>${n} / page</option>`).join('')}</select>`; }
function vProjects(){
  if(!PROJ.loaded && PROJ.loading) return `<h2>Projects</h2><div class="card muted" style="text-align:center;padding:22px">Loading…</div>`;
  const all=PROJ.projects||[];
  const msg=PROJ.msg?`<div class="${PROJ.err?'warn':'ok'}" style="margin-bottom:10px">${qesc(PROJ.msg)}</div>`:'';
  if(!all.length) return `<h2>Projects</h2>${msg}<div class="card muted" style="text-align:center;padding:24px">${PROJ.admin?'No projects yet. Convert an approved quote to a project from <b>My Quotes</b>.':'No open projects right now.'}</div>`;
  const search=`<div class="row" style="gap:8px;align-items:center;margin-bottom:10px;flex-wrap:wrap">
      <input id="projSearch" type="text" autocomplete="off" ${tip('Filter by client, invoice/estimate number or subject')} placeholder="🔍 Filter by client or invoice #…" value="${qesc(PROJ.q||'')}" oninput="projSearch(this.value)" style="flex:1;min-width:180px;margin:0">
      ${projPerPageSel()}
    </div>`;
  if(!PROJ.admin){
    return `<h2>Projects</h2><div class="muted" style="font-size:11.5px;margin-bottom:10px">Open jobs — add the actual costs as you go. You won't see the price charged to the customer.</div>${msg}${search}<div id="projListBox">${projListHtml()}</div>`;
  }
  const cnt=s=>all.filter(p=>projStageOf(p)===s).length;
  const chip=(k,label,n)=>`<button class="btn${(PROJ.filter||'open')===k?'':' sec'}" style="width:auto;padding:5px 12px;font-size:12px" onclick="PROJ.filter='${k}';PROJ.page=1;render()">${label}${n!=null?(' ('+n+')'):''}</button>`;
  return `<div class="row" style="align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:10px">
      <h2 style="margin:0;flex:1;min-width:160px">Projects</h2>
      <button class="btn" style="width:auto;padding:7px 14px;font-size:12px;background:#7A5AF8;box-shadow:none" ${tip('Open the full profit report')} onclick="navTo('report')">📊 Profit Report</button>
    </div>
    <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:8px">
      ${chip('open','🟣 Open',cnt('open'))}${chip('billed','🔵 Billed',cnt('billed'))}${chip('closed','⚪ Closed',cnt('closed'))}${chip('all','All',all.length)}
    </div>
    ${search}${msg}<div id="projListBox">${projListHtml()}</div>`;
}

function projCardAdmin(p){
  const stage=projStageOf(p); const busy=PROJ.busyId===p.id;
  const num=p.zoho_invoice_number||p.zoho_estimate_number||('#'+p.id);
  const prof=p.actual_profit||0;
  return `<div class="card" style="border-left:4px solid ${quoteAccent(p.status)};margin-bottom:10px">
    <div class="row" style="align-items:flex-start;gap:12px">
      <div style="min-width:0;flex:1"><b style="font-size:14px">${qesc(p.customer_name||'(no customer)')}</b>
        ${p.subject
          ? `<div style="font-size:12px;color:var(--ink);font-weight:500;margin-top:2px">${qesc(p.subject)} <span onclick="projSetSubject(${p.id})" style="cursor:pointer;color:var(--mute);font-size:11px" title="Edit subject">✎</span>${p.zoho_estimate_number?` <span onclick="projPullSubject(${p.id})" style="cursor:pointer;color:var(--mute);font-size:11px" title="Pull the subject from the Zoho quote">⟳</span>`:''}</div>`
          : `<div style="margin-top:2px"><button class="btn sec" style="width:auto;padding:2px 9px;font-size:11px" ${tip('Add a subject so everyone can see what this job is')} onclick="projSetSubject(${p.id})">＋ Add subject</button>${p.zoho_estimate_number?`<button class="btn sec" style="width:auto;padding:2px 9px;font-size:11px;margin-left:6px" ${tip('Pull the subject from the Zoho quote')} onclick="projPullSubject(${p.id})">⟳ From Zoho</button>`:''}</div>`}
        <div class="muted" style="margin-top:3px;font-size:11.5px">${qesc(num)} · ${p.line_count} item${p.line_count===1?'':'s'} · by ${qesc(p.created_by||'')}</div>
        <div class="muted" style="margin-top:3px;font-size:11px">👤 ${(p.assignees&&p.assignees.length)?('Assigned: '+p.assignees.map(qesc).join(', ')):'<span style="color:var(--orange)">Unassigned</span>'}</div></div>
      <div style="text-align:right;white-space:nowrap;font-size:11px;line-height:1.6">
        <div style="margin-bottom:4px">${projStageBadge(stage)}</div>
        <div style="color:var(--mute)">Charged <b style="color:var(--ink)">${fmtn(p.total||0)}</b> · Budget <b style="color:var(--ink)">${fmtn(p.budget_cost||0)}</b></div>
        <div style="color:var(--mute)">Cost <b style="color:var(--ink)">${fmtn(p.actual_cost||0)}</b> · VAT <b style="color:var(--ink)">${fmtn(p.cost_vat||0)}</b> · Total cost <b style="color:var(--ink)">${fmtn((p.actual_cost||0)+(p.cost_vat||0))}</b></div>
        <div style="font-weight:700"><span style="color:${(p.expected_profit||0)<0?'var(--bad)':'var(--good)'}">Exp. profit ${fmtn(p.expected_profit||0)}</span> · <span style="color:${prof<0?'var(--bad)':'var(--good)'}">Actual profit ${fmtn(prof)}${(p.total>0)?(' · '+Math.round(prof/p.total*100)+'%'):''}</span></div>
        <div style="color:var(--mute)">Paid <b style="color:var(--ink)">${fmtn(p.paid_total||0)}</b> · Balance <b style="color:${(p.balance||0)>0?'var(--orange)':'var(--good)'}">${fmtn(p.balance||0)}</b></div>
      </div>
    </div>
    <div class="qact">
      <button class="btn sec qb" ${tip('Preview the quote — line items and totals')} onclick="qvOpen(${p.id})">👁 Preview</button>
      <button class="btn qb" style="background:var(--good);box-shadow:none" ${tip('View / edit the actual costs on this project')} onclick="jcOpen(${p.id},'capture')">💰 Costs</button>
      <button class="btn sec qb" ${tip('Record client deposits/payments and see the balance')} onclick="ppOpen(${p.id})">💵 Payments</button>
      <button class="btn sec qb" ${tip('Assign which team members can view and cost this project')} onclick="paOpen(${p.id})">👤 Assign</button>
      <button class="btn sec qb" ${tip('Bill of Quantities — materials required (labour excluded), branded PDF')} onclick="openBoq(${p.id})">⤓ BOQ</button>
      ${stage==='open'?`<button class="btn qb" style="background:var(--blue);box-shadow:none" ${tip('Review costs vs profit, create the invoice and post expenses to Zoho')} onclick="jcOpen(${p.id},'bill')" ${busy?'disabled':''}>🧾 Bill client</button>`:''}
      ${p.zoho_invoice_number?`<button class="btn sec qb" ${tip('Open the printable job card')} onclick="openJobCard(${p.id})">⤓ Job card</button>`:''}
      <button class="btn sec qb" ${tip('Per-invoice profit report — charged vs budgeted vs actual cost and profit, branded PDF')} onclick="openProfit(${p.id})">📊 Profit report</button>
      ${p.project_closed?`<button class="btn sec qb" ${tip('Reopen so the team can see and cost it again')} onclick="projReopen(${p.id})" ${busy?'disabled':''}>${busy?'…':'↺ Reopen'}</button>`:`<button class="btn sec qb" ${tip('Close this project — hides it from the team; you can reopen anytime')} onclick="projClose(${p.id})" ${busy?'disabled':''}>${busy?'…':'✓ Close'}</button>`}
    </div>
  </div>`;
}

function projCardTeam(p){
  return `<div class="card" style="border-left:4px solid #7A5AF8;margin-bottom:10px">
    <div class="row" style="align-items:flex-start;gap:12px">
      <div style="min-width:0;flex:1"><b style="font-size:14px">${qesc(p.customer_name||'(no customer)')}</b>
        ${p.subject?`<div style="font-size:12px;color:var(--ink);font-weight:500;margin-top:2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${qesc(p.subject)}</div>`:''}
        <div class="muted" style="margin-top:3px;font-size:11.5px">${qesc(p.zoho_estimate_number||('#'+p.id))} · ${p.line_count} item${p.line_count===1?'':'s'}</div></div>
      <div style="text-align:right;white-space:nowrap"><div style="font-size:11px;color:var(--mute)">Cost so far</div><b>${fmtn(p.actual_cost||0)}</b></div>
    </div>
    <div class="qact">
      <button class="btn qb" style="background:var(--good);box-shadow:none" ${tip('Add the parts, labour and materials that went into this job')} onclick="jcOpen(${p.id},'capture')">💰 Input costs</button>
      <button class="btn sec qb" ${tip('Bill of Quantities — the materials required for this job (branded PDF)')} onclick="openBoq(${p.id})">⤓ BOQ</button>
    </div>
  </div>`;
}

function projClose(id){ if(!confirm('Close this project? Your team will no longer see it or add costs. You can reopen it anytime.'))return; PROJ.busyId=id; render();
  fetch('api/quote_project.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({id,action:'close'})})
  .then(r=>r.json()).then(j=>{ PROJ.busyId=0; if(j.ok){ const p=PROJ.projects.find(x=>x.id===id); if(p)p.project_closed=1; PROJ.msg='Project closed.'; PROJ.err=false; } else { PROJ.msg=j.error||'Could not close'; PROJ.err=true; } render(); })
  .catch(e=>{ PROJ.busyId=0; PROJ.msg='Error: '+e; PROJ.err=true; render(); });
}
function projReopen(id){ PROJ.busyId=id; render();
  fetch('api/quote_project.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({id,action:'reopen'})})
  .then(r=>r.json()).then(j=>{ PROJ.busyId=0; if(j.ok){ const p=PROJ.projects.find(x=>x.id===id); if(p)p.project_closed=0; PROJ.msg='Project reopened.'; PROJ.err=false; } else { PROJ.msg=j.error||'Could not reopen'; PROJ.err=true; } render(); })
  .catch(e=>{ PROJ.busyId=0; PROJ.msg='Error: '+e; PROJ.err=true; render(); });
}

/* ---------- Add/edit a project's subject (admin) ---------- */
function projSetSubject(id){
  const p=(PROJ.projects||[]).find(x=>x.id===id);
  const s=prompt('Subject for this project (what the job is):', (p&&p.subject)||'');
  if(s===null) return;
  fetch('api/quote_project.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({id:id,action:'set_subject',subject:s})})
  .then(r=>r.json()).then(j=>{ if(j.ok){ if(p)p.subject=j.subject; const mq=(MQ.quotes||[]).find(x=>x.id===id); if(mq)mq.subject=j.subject;
      const b=document.getElementById('projListBox'); if(b&&TAB==='projects') b.innerHTML=projListHtml(); else render();
    } else alert(j.error||'Could not save subject'); }).catch(e=>alert(''+e));
}

function projPullSubject(id){
  const p=(PROJ.projects||[]).find(x=>x.id===id);
  fetch('api/quote_project.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({id:id,action:'pull_subject'})})
  .then(r=>r.json()).then(j=>{ if(j.ok){ if(p)p.subject=j.subject; const mq=(MQ.quotes||[]).find(x=>x.id===id); if(mq)mq.subject=j.subject;
      const b=document.getElementById('projListBox'); if(b&&TAB==='projects') b.innerHTML=projListHtml(); else render();
      if(!j.subject) alert('The Zoho quote has no subject — add one manually with ✎.');
    } else alert(j.error||'Could not pull subject'); }).catch(e=>alert(''+e));
}

/* ---------- Preview the quote in a popup (line items + totals) ---------- */
function qvOpen(id){
  document.getElementById('qvModalBody').innerHTML='<div class="muted" style="padding:22px;text-align:center">Loading…</div>';
  document.getElementById('qvModalTitle').textContent='Quote preview';
  document.getElementById('qvModal').classList.add('open'); document.body.style.overflow='hidden';
  fetch('api/quotes.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'get',id})})
  .then(r=>r.json()).then(j=>{
    if(!j.ok){ document.getElementById('qvModalBody').innerHTML='<div class="warn" style="margin:10px">'+qesc(j.error||'Could not load')+'</div>'; return; }
    const quote=j.quote;
    document.getElementById('qvModalTitle').textContent='Quote '+qesc(quote.zoho_estimate_number||('#'+quote.id))+' · '+qesc(quote.customer_name||'');
    document.getElementById('qvModalBody').innerHTML=mqPreviewHtml(quote)
      + `<div class="row" style="justify-content:flex-end;gap:8px;margin-top:12px">${quote.zoho_estimate_id?`<button class="btn sec" style="width:auto" onclick="mqPdf(${quote.id})">⤓ PDF</button>`:''}<button class="btn sec" style="width:auto" onclick="qvClose()">Close</button></div>`;
  }).catch(e=>{ document.getElementById('qvModalBody').innerHTML='<div class="warn" style="margin:10px">Error: '+qesc(''+e)+'</div>'; });
}
function qvClose(){ document.getElementById('qvModal').classList.remove('open'); document.body.style.overflow=''; }

/* ---------- Assign which team members can view/cost a project (admin) ---------- */
var PA={quoteId:0,users:[],busy:false,msg:'',err:false};
function paOpen(quoteId){
  PA={quoteId:quoteId,users:[],busy:false,msg:'',err:false};
  document.getElementById('paModalBody').innerHTML='<div class="muted" style="padding:22px;text-align:center">Loading…</div>';
  document.getElementById('paModal').classList.add('open'); document.body.style.overflow='hidden';
  fetch('api/project_assign.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'get',quote_id:quoteId})})
  .then(r=>r.json()).then(j=>{ if(!j.ok){ document.getElementById('paModalBody').innerHTML='<div class="warn" style="margin:10px">'+qesc(j.error||'Could not load')+'</div>'; return; }
    PA.users=j.users||[]; paRender();
  }).catch(e=>{ document.getElementById('paModalBody').innerHTML='<div class="warn" style="margin:10px">Error: '+qesc(''+e)+'</div>'; });
}
function paClose(){ document.getElementById('paModal').classList.remove('open'); document.body.style.overflow=''; }
function paRender(){
  const box=document.getElementById('paModalBody'); if(!box)return;
  const list=(PA.users||[]).map((u,i)=>`<label class="row" style="gap:10px;padding:8px 4px;border-bottom:1px solid var(--line);cursor:pointer">
      <input type="checkbox" ${u.assigned?'checked':''} onchange="PA.users[${i}].assigned=this.checked" style="width:auto;margin:0">
      <span style="flex:1;min-width:0"><b style="font-size:12.5px">${qesc(u.username)}</b>${u.email?`<span class="muted" style="font-size:11px"> · ${qesc(u.email)}</span>`:''}</span>
    </label>`).join('');
  box.innerHTML=(PA.msg?`<div class="${PA.err?'warn':'ok'}" style="margin-bottom:10px">${qesc(PA.msg)}</div>`:'')
    + `<div class="muted" style="font-size:11.5px;margin-bottom:8px">Tick the team members who should see and cost this project. They'll only see projects assigned to them.</div>`
    + (list || '<div class="muted" style="font-size:12px;padding:8px 0">No staff users yet — create them under <b>Settings → Users</b>.</div>')
    + `<div class="row" style="justify-content:flex-end;gap:8px;margin-top:14px"><button class="btn sec" style="width:auto" onclick="paClose()">Close</button>
       <button class="btn" style="width:auto" onclick="paSave()" ${PA.busy?'disabled':''}>${PA.busy?'Saving…':'Save assignment'}</button></div>`;
}
function paSave(){
  const names=(PA.users||[]).filter(u=>u.assigned).map(u=>u.username);
  PA.busy=true; PA.msg=''; paRender();
  fetch('api/project_assign.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'set',quote_id:PA.quoteId,usernames:names})})
  .then(r=>r.json()).then(j=>{ PA.busy=false;
    if(j.ok){ const mp=(PROJ.projects||[]).find(x=>x.id===PA.quoteId); if(mp) mp.assignees=j.assignees||[];
      const b=document.getElementById('projListBox'); if(b&&TAB==='projects') b.innerHTML=projListHtml();
      PA.msg='Assignment saved.'; PA.err=false; paRender();
    } else { PA.msg=j.error||'Could not save.'; PA.err=true; paRender(); }
  }).catch(e=>{ PA.busy=false; PA.msg='Error: '+e; PA.err=true; paRender(); });
}

/* ---------- Client payments / deposits on a project (admin) ---------- */
var PP={quoteId:0,customer:'',currency:'KES',payments:[],total:0,quoteTotal:0,balance:0,busy:false,msg:'',err:false};
function ppOpen(quoteId){
  PP={quoteId:quoteId,customer:'',currency:'KES',payments:[],total:0,quoteTotal:0,balance:0,busy:false,msg:'',err:false};
  document.getElementById('ppModalBody').innerHTML='<div class="muted" style="padding:22px;text-align:center">Loading…</div>';
  document.getElementById('ppModal').classList.add('open'); document.body.style.overflow='hidden';
  fetch('api/project_payments.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'list',quote_id:quoteId})})
  .then(r=>r.json()).then(j=>{ if(!j.ok){ document.getElementById('ppModalBody').innerHTML='<div class="warn" style="margin:10px">'+qesc(j.error||'Could not load')+'</div>'; return; }
    PP.payments=j.payments||[]; PP.total=j.total; PP.quoteTotal=j.quote_total; PP.balance=j.balance; PP.customer=j.customer_name||''; PP.currency=j.currency||'KES'; ppRender();
  }).catch(e=>{ document.getElementById('ppModalBody').innerHTML='<div class="warn" style="margin:10px">Error: '+qesc(''+e)+'</div>'; });
}
function ppClose(){ document.getElementById('ppModal').classList.remove('open'); document.body.style.overflow=''; }
function ppSyncCard(){ const mp=(PROJ.projects||[]).find(x=>x.id===PP.quoteId); if(mp){ mp.paid_total=PP.total; mp.balance=PP.balance; } const b=document.getElementById('projListBox'); if(b&&TAB==='projects') b.innerHTML=projListHtml(); }
function ppRender(){
  const box=document.getElementById('ppModalBody'); if(!box)return;
  document.getElementById('ppModalTitle').textContent='Client payments · '+(PP.customer||'');
  const cur=PP.currency;
  const rows=(PP.payments||[]).map(p=>`<div class="row" style="border-bottom:1px solid var(--line);padding:7px 0;font-size:12.5px">
      <div style="flex:1;min-width:0"><b>${cur} ${fmtn(p.amount)}</b> <span class="muted" style="font-size:11px">· ${qesc(p.paid_date||'')}${p.note?(' · '+qesc(p.note)):''}</span></div>
      <button class="btn sec" style="width:auto;padding:3px 9px;font-size:11px;color:var(--bad)" onclick="ppDelete(${p.id})" title="Remove">✕</button>
    </div>`).join('') || '<div class="muted" style="font-size:12px;padding:6px 0">No payments recorded yet.</div>';
  box.innerHTML=(PP.msg?`<div class="${PP.err?'warn':'ok'}" style="margin-bottom:10px">${qesc(PP.msg)}</div>`:'')
    + `<div class="card" style="padding:12px;margin-bottom:12px;background:var(--surface-2)">
        <div class="row"><span class="muted">Quote total</span><b>${cur} ${fmtn(PP.quoteTotal)}</b></div>
        <div class="row" style="margin-top:4px"><span class="muted">Paid</span><b style="color:var(--good)">${cur} ${fmtn(PP.total)}</b></div>
        <div class="row" style="margin-top:4px"><b>Balance</b><b style="color:${PP.balance>0?'var(--orange)':'var(--good)'}">${cur} ${fmtn(PP.balance)}</b></div></div>`
    + `<div style="font-weight:700;font-size:12.5px;margin-bottom:4px">Payments received</div>${rows}`
    + `<div class="card" style="padding:12px;margin-top:12px">
        <div class="row" style="gap:8px;flex-wrap:wrap;align-items:flex-end">
          <div style="flex:1;min-width:110px"><label style="font-size:11px;margin:0">Amount (${cur})</label><input id="ppAmt" type="number" step="0.01" min="0" style="margin:0" placeholder="0"></div>
          <div style="min-width:130px"><label style="font-size:11px;margin:0">Date</label><input id="ppDate" type="date" value="${today()}" style="margin:0"></div>
          <div style="flex:1;min-width:130px"><label style="font-size:11px;margin:0">Note</label><input id="ppNote" type="text" style="margin:0" placeholder="e.g. deposit, mpesa"></div>
          <button class="btn" style="width:auto" onclick="ppAdd()" ${PP.busy?'disabled':''}>${PP.busy?'Saving…':'Record payment'}</button>
        </div></div>`;
}
function ppAdd(){
  const amt=parseFloat((document.getElementById('ppAmt')||{}).value)||0; if(amt<=0){ PP.msg='Enter a payment amount.'; PP.err=true; ppRender(); return; }
  const date=(document.getElementById('ppDate')||{}).value||today(); const note=(document.getElementById('ppNote')||{}).value||'';
  PP.busy=true; PP.msg=''; ppRender();
  fetch('api/project_payments.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'add',quote_id:PP.quoteId,amount:amt,date:date,note:note})})
  .then(r=>r.json()).then(j=>{ PP.busy=false; if(j.ok){ if(j.payment)PP.payments.push(j.payment); PP.total=j.total; PP.balance=j.balance; PP.msg='Payment recorded.'; PP.err=false; ppSyncCard(); ppRender(); } else { PP.msg=j.error||'Could not save.'; PP.err=true; ppRender(); } })
  .catch(e=>{ PP.busy=false; PP.msg='Error: '+e; PP.err=true; ppRender(); });
}
function ppDelete(id){ if(!confirm('Remove this payment?'))return;
  fetch('api/project_payments.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'delete',id:id})})
  .then(r=>r.json()).then(j=>{ if(j.ok){ PP.payments=PP.payments.filter(p=>p.id!==id); PP.total=j.total; PP.balance=j.balance; ppSyncCard(); ppRender(); } else { PP.msg=j.error||'Could not delete.'; PP.err=true; ppRender(); } })
  .catch(e=>{ PP.msg='Error: '+e; PP.err=true; ppRender(); });
}

var JCP={perPage:25,page:1};
function jcardGoPage(n){ JCP.page=n; render(); }
function jcardPerPage(v){ JCP.perPage=parseInt(v,10)||25; JCP.page=1; render(); }
function vJobCards(){
  const jobs=(MQ.quotes||[]).filter(q=>q.status==='invoiced'||q.zoho_invoice_number)
    .slice().sort((a,b)=>String(b.created_at||'').localeCompare(String(a.created_at||'')));
  if(!MQ.loaded) return `<h2>Job cards</h2><div class="card muted" style="text-align:center;padding:22px">Loading…</div>`;
  if(!jobs.length) return `<h2>Job cards</h2>
    <div class="card" style="text-align:center;padding:26px">
      <div style="font-size:30px;margin-bottom:6px">🧾</div>
      <b style="font-size:14px">No job cards yet</b>
      <div class="muted" style="margin-top:6px;max-width:460px;margin-left:auto;margin-right:auto">Open <b>My Quotes</b>, find an approved quote and tap <b>🧾 Job Card</b>. The quote becomes a Zoho invoice and a printable Delivery Note / Job Card (no prices) opens for the customer to sign.</div>
      <button class="btn" style="width:auto;padding:8px 16px;font-size:12px;margin-top:14px" onclick="navTo('myquotes')">Go to My Quotes →</button>
    </div>`;
  const _jp=pgSlice(jobs, JCP);
  return `<h2>Job cards</h2>
    <div class="row" style="align-items:center;gap:8px;margin:-6px 0 12px;flex-wrap:wrap">
      <span class="muted" style="flex:1;min-width:140px">${jobs.length} job card${jobs.length===1?'':'s'} generated${ME.admin?'':' by you'}${_jp.pages>1?(' · page '+JCP.page+' of '+_jp.pages):''}.</span>
      ${pgSel(JCP.perPage,'jcardPerPage(this.value)')}
    </div>
    ${_jp.slice.map(q=>{
      const date=(q.quote_date||String(q.created_at||'').slice(0,10))||'';
      return `<div class="card" style="border-left:4px solid var(--blue)">
        <div class="row" style="align-items:flex-start;gap:12px">
          <div style="min-width:0;flex:1"><b style="font-size:14px">${qesc(q.customer_name||'(no customer)')}</b>
            <div class="muted" style="margin-top:3px;font-size:11.5px"><span style="color:var(--blue);font-weight:700">${qesc(q.zoho_invoice_number||'—')}</span> · ${ME.admin?('by '+qesc(q.created_by)+' · '):''}${q.line_items.length} item${q.line_items.length===1?'':'s'}${date?(' · '+date):''}</div></div>
          <div style="text-align:right;white-space:nowrap"><div style="color:var(--orange);font-weight:700;font-size:15px">${qesc(q.currency||'KES')} ${fmtn(q.total)}</div>
            <div style="margin-top:5px">${quoteBadge('invoiced')}</div></div>
        </div>
        <div class="qact">
          <button class="btn qb" style="background:var(--blue);box-shadow:none" ${tip('Open the printable job card / delivery note')} onclick="window.open('api/job_card.php?id=${q.id}','_blank')">🧾 ${ME.admin?'Open / print':'View'} job card</button>
          ${ME.admin?`<button class="btn sec qb" onclick="mqPdf(${q.id})">⤓ Download Quote PDF</button>`:''}
        </div>
      </div>`;}).join('')}
    ${pgBar(JCP, _jp.pages, 'jcardGoPage')}`;
}
function loadJobCards(){ MQ.loading=true;
  fetch('api/quotes.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'list'})})
  .then(r=>r.json()).then(j=>{ MQ.loading=false; if(j.ok){ MQ.quotes=mqNormalize(j.quotes||[]); MQ.loaded=true; if(TAB==='jobcards') render(); } }).catch(()=>{ MQ.loading=false; });
}

/* ---- Activity log (admin) ---- */
let ACT={ log:[], loaded:false, loading:false, q:'' };
function vActivity(){
  if(!ME.admin) return `<div class="card muted" style="text-align:center">Admins only.</div>`;
  const esc=s=>String(s==null?'':s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
  const rows=(ACT.log||[]).map(e=>`<tr>
      <td class="l" style="white-space:nowrap">${esc((e.created_at||'').replace('T',' ').slice(0,16))}</td>
      <td class="l"><b>${esc(e.username)}</b></td>
      <td class="l">${esc(e.action)}</td>
      <td class="l">${esc(e.detail)}</td></tr>`).join('');
  return `<h2>Activity log</h2>
    <div class="row" style="margin-bottom:12px;gap:8px;flex-wrap:wrap">
      <input type="text" id="actSearch" autocomplete="off" placeholder="🔍 Search user, action or detail…" value="${esc(ACT.q)}" oninput="ACT.q=this.value" onkeydown="if(event.key==='Enter')loadActivity()" style="flex:1;min-width:200px;margin-bottom:0">
      <button class="btn sec" style="width:auto;padding:6px 12px;font-size:12px" onclick="loadActivity()">${ACT.loading?'Loading…':'↻ Refresh'}</button>
    </div>
    ${!ACT.loaded?`<div class="card muted" style="text-align:center;padding:20px">Loading…</div>`
      : (ACT.log.length? `<div class="rptwrap"><table class="rpt">
          <thead><tr><th class="l">When</th><th class="l">User</th><th class="l">Action</th><th class="l">Detail</th></tr></thead>
          <tbody>${rows}</tbody></table></div>
          <div class="muted" style="font-size:11px;margin-top:8px">Showing the latest ${ACT.log.length} events.</div>`
        : `<div class="card muted" style="text-align:center;padding:22px">No activity recorded yet.</div>`)}`;
}
function loadActivity(){ ACT.loading=true; if(TAB==='activity')render();
  fetch('api/activity.php?limit=400&q='+encodeURIComponent(ACT.q||''),{credentials:'same-origin'})
  .then(r=>r.json()).then(j=>{ ACT.loading=false; if(j.ok){ ACT.log=j.log||[]; ACT.loaded=true; } if(TAB==='activity')render(); })
  .catch(()=>{ ACT.loading=false; if(TAB==='activity')render(); });
}

/* programmatic tab switch (used after push / edit) */
function navTo(tab){ const b=document.querySelector('.tabs button[data-tab="'+tab+'"]'); if(b){ navActivate(b); } else { TAB=tab; render(); } }

/* direct tabs + submenu tabs */
document.querySelectorAll('.tabs button[data-tab]').forEach(b=>b.onclick=()=>navActivate(b));
/* mobile drawer tabs */
document.querySelectorAll('#mobDrawer .mob-item[data-tab]').forEach(b=>b.onclick=()=>{ navActivate(b); closeMobileNav(); });
document.querySelectorAll('#mobDrawer .mob-item[data-ext]').forEach(b=>b.onclick=()=>{ window.open(b.dataset.ext,'_blank'); closeMobileNav(); });
/* external links (e.g. Audrey) */
document.querySelectorAll('.tabs button[data-ext]').forEach(b=>b.onclick=()=>{ window.open(b.dataset.ext,'_blank'); closeNavGroups(); });
/* group headers toggle their dropdown */
document.querySelectorAll('.tabs .navgroup .grp').forEach(g=>g.onclick=(e)=>{
  e.stopPropagation();
  const ng=g.closest('.navgroup'); const wasOpen=ng.classList.contains('open');
  closeNavGroups();
  if(!wasOpen){
    ng.classList.add('open');
    /* on mobile the tabs bar has overflow:auto which clips position:absolute submenus —
       reposition as fixed so the submenu floats above the page */
    if(window.innerWidth<=680){
      const sub=ng.querySelector('.submenu');
      const rect=g.getBoundingClientRect();
      sub.style.top=(rect.bottom+4)+'px';
    }
  }
});
/* click anywhere else closes open dropdowns */
document.addEventListener('click',(e)=>{ if(!e.target.closest('.navgroup')) closeNavGroups(); });
document.addEventListener('keydown',(e)=>{ if(e.key==='Escape'){ if(QB.modalOpen) qbClose(); const pm=document.getElementById('pwModal'); if(pm&&pm.classList.contains('open')) pwClose(); const tm=document.getElementById('taskModal'); if(tm&&tm.classList.contains('open')) tmClose(); const jm=document.getElementById('jcModal'); if(jm&&jm.classList.contains('open')) jcClose(); const ppm=document.getElementById('ppModal'); if(ppm&&ppm.classList.contains('open')) ppClose(); const pam=document.getElementById('paModal'); if(pam&&pam.classList.contains('open')) paClose(); const qvm=document.getElementById('qvModal'); if(qvm&&qvm.classList.contains('open')) qvClose(); const dm=document.getElementById('docModal'); if(dm&&dm.classList.contains('open')) docClose(); } });

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
if('serviceWorker' in navigator){ window.addEventListener('load',()=>{ navigator.serviceWorker.register('index.php?pwa=sw').catch(()=>{}); }); }
if(ME.admin){ loadDeployments(); loadLoans(); loadCacheMeta(); loadBackupStatus(); }
else { render(); }
loadDashTasks();
loadDashQuotes();
dashPaidLoad();
itemNamesLoad();
if(ME.admin && !USERS.loaded) usersLoad();
if(ME.admin) expLoadAccounts();

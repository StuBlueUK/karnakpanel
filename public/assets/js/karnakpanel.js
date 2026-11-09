const t=document.getElementById('themeToggle');
const root=document.documentElement;
root.dataset.theme=localStorage.getItem('skypanel-theme')||'dark';
t&&t.addEventListener('click',()=>{root.dataset.theme=root.dataset.theme==='dark'?'light':'dark';localStorage.setItem('skypanel-theme',root.dataset.theme);});
const s=document.getElementById('toolSearch');
// tool search: filters dashboard tiles AND the sidebar (every page); Enter
// opens the first matching sidebar link; on phones it opens the drawer
s&&s.addEventListener('input',e=>{
  const q=e.target.value.trim().toLowerCase();
  document.querySelectorAll('.tool').forEach(el=>{
    el.style.display=el.textContent.toLowerCase().includes(q)?'':'none';
  });
  document.querySelectorAll('.sidebar nav a').forEach(el=>{
    el.style.display=(!q||el.textContent.toLowerCase().includes(q))?'':'none';
  });
  document.querySelectorAll('.sidebar .navgroup').forEach(el=>{ el.style.display=q?'none':''; });
  if(q&&window.matchMedia('(max-width:800px)').matches) document.body.classList.add('nav-open');
});
s&&s.addEventListener('keydown',e=>{
  if(e.key!=='Enter') return;
  const hit=[...document.querySelectorAll('.sidebar nav a')].find(a=>a.style.display!=='none');
  if(hit&&s.value.trim()){ e.preventDefault(); location.href=hit.getAttribute('href'); }
});
// phones: 🔍 reveals the search row; account menu closes on outside click
(function(){const b=document.getElementById('searchToggle');b&&b.addEventListener('click',e=>{e.stopPropagation();
  document.body.classList.toggle('search-open');if(document.body.classList.contains('search-open')&&s)s.focus();});
  document.addEventListener('click',e=>{document.querySelectorAll('details.acctmenu[open]').forEach(d=>{if(!d.contains(e.target))d.removeAttribute('open');});});})();
// DNS record edit: prefill the add-form and retarget it at /dns/update
document.addEventListener('click',e=>{
  const b=e.target.closest?e.target.closest('.dns-edit'):null;
  if(!b) return;
  e.preventDefault();
  const f=document.getElementById('dns-record-form');
  if(!f) return;
  f.action='/dns/update';
  document.getElementById('dns-f-id').value=b.dataset.id||'';
  document.getElementById('dns-f-type').value=b.dataset.type||'A';
  document.getElementById('dns-f-host').value=b.dataset.host||'';
  document.getElementById('dns-f-value').value=b.dataset.value||'';
  document.getElementById('dns-f-prio').value=b.dataset.priority||'10';
  document.getElementById('dns-f-ttl').value=b.dataset.ttl||'3600';
  document.getElementById('dns-f-submit').textContent='Save & apply';
  document.getElementById('dns-form-title').textContent='Edit record';
  const c=document.getElementById('dns-f-cancel');
  if(c){c.style.display='';c.onclick=ev=>{ev.preventDefault();f.action='/dns/add';document.getElementById('dns-f-id').value='';document.getElementById('dns-f-submit').textContent='Add & apply';document.getElementById('dns-form-title').textContent='Add record';c.style.display='none';f.reset();dnsTypeHints();return false;};}
  dnsTypeHints();
  f.scrollIntoView({behavior:'smooth',block:'center'});
});
// DNS form: per-type value hint; priority only where the type has one
const DNS_HINTS={A:['192.0.2.10','IPv4 address'],AAAA:['2001:db8::10','IPv6 address'],
  CNAME:['target.example.net','points this name at another host (nothing else may share the name)'],
  MX:['mail.example.com','mail server; lower priority = tried first'],
  TXT:['v=spf1 a mx ~all','text — SPF, DKIM, verification codes (quotes optional)'],
  NS:['ns1.provider.net','delegates a SUB-name to other nameservers (the zone\'s own NS is managed above)'],
  CAA:['0 issue letsencrypt.org','which CA may issue certificates'],
  SRV:['5 5060 sip.example.com','weight port target — host like _sip._tcp'],
  SSHFP:['4 2 <64 hex chars>','SSH host key fingerprint']};
function dnsTypeHints(){
  const ty=document.getElementById('dns-f-type'); if(!ty) return;
  const h=DNS_HINTS[ty.value]||['',''];
  const v=document.getElementById('dns-f-value'); v.placeholder=h[0]; v.title=h[1];
  const hint=document.getElementById('dns-f-hint'); if(hint) hint.textContent=h[1];
  const p=document.getElementById('dns-f-prio'); const pr=(ty.value==='MX'||ty.value==='SRV');
  p.style.display=pr?'':'none'; p.title=ty.value==='SRV'?'SRV priority':'MX priority';
}
document.addEventListener('change',e=>{ if(e.target&&e.target.id==='dns-f-type') dnsTypeHints(); });
dnsTypeHints();
// DNS records table: filter by type (client-side, current page)
document.addEventListener('click',e=>{
  const b=e.target.closest?e.target.closest('.dns-tf'):null;
  if(!b) return;
  e.preventDefault();
  const t=b.dataset.type;
  document.querySelectorAll('.dns-tf').forEach(x=>x.classList.toggle('active',x===b));
  document.querySelectorAll('#dns-rows tr[data-type]').forEach(r=>{ r.style.display=(t===''||r.dataset.type===t)?'':'none'; });
});
// phone nav drawer: ☰ toggles; tapping outside, a link, or Esc closes it
(function(){const b=document.getElementById('navToggle'),sb=document.getElementById('sidebar');if(!b||!sb)return;
const set=o=>{document.body.classList.toggle('nav-open',o);b.setAttribute('aria-expanded',o?'true':'false');};
b.addEventListener('click',e=>{e.stopPropagation();set(!document.body.classList.contains('nav-open'));});
document.addEventListener('click',e=>{if(document.body.classList.contains('nav-open')&&!e.target.closest('.search')&&(!sb.contains(e.target)||e.target.closest('a')))set(false);});
document.addEventListener('keydown',e=>{if(e.key==='Escape')set(false);});})();
// in-page tabs (Email): sections stay in the HTML (no JS = everything shown).
// #hash picks a tab; after a form POST we come back to the tab it was sent from.
(function(){const nav=document.querySelector('.etabs');if(!nav)return;
  const tabs=[...nav.querySelectorAll('[data-etab]')],ids=tabs.map(t=>t.dataset.etab),KEY='etab:'+location.pathname;
  const show=id=>{if(!ids.includes(id))id=ids[0];
    document.querySelectorAll('section.etab').forEach(s=>s.classList.toggle('on',s.id==='etab-'+id));
    tabs.forEach(t=>t.classList.toggle('active',t.dataset.etab===id));return id;};
  let pending=null;try{pending=sessionStorage.getItem(KEY);sessionStorage.removeItem(KEY);}catch(e){}
  nav.hidden=false;document.body.classList.add('etabs-on');
  const cur=show(location.hash.slice(1)||pending||ids[0]);
  if(!location.hash&&cur!==ids[0])history.replaceState(null,'','#'+cur);
  tabs.forEach(t=>t.addEventListener('click',e=>{e.preventDefault();history.replaceState(null,'','#'+show(t.dataset.etab));}));
  window.addEventListener('hashchange',()=>show(location.hash.slice(1)));
  document.addEventListener('submit',e=>{const s=e.target.closest('section.etab');if(s)try{sessionStorage.setItem(KEY,s.id.slice(5));}catch(x){}},true);
})();
// long synchronous POSTs (form data-wait="message"): "please wait" overlay
// once the inline confirm() has passed; the page reloads with the result.
// Slow pages too (a data-wait="message" link; data-wait-note = small line).
function kpWait(el){const o=document.createElement('div');o.className='kpwait';o.setAttribute('role','status');
  o.innerHTML='<div class="kpwait-box"><span class="spin big" aria-hidden="true"></span><b></b><small></small></div>';
  o.querySelector('b').textContent=el.dataset.wait;
  o.querySelector('small').textContent=el.dataset.waitNote||'Let\'s Encrypt is checking the domain — usually under a minute. Please keep this page open.';
  document.body.appendChild(o);}
document.addEventListener('submit',e=>{const f=e.target;if(e.defaultPrevented||!f.dataset||!f.dataset.wait)return;
  kpWait(f);setTimeout(()=>f.querySelectorAll('button').forEach(b=>b.disabled=true),0);});
document.addEventListener('click',e=>{const a=e.target.closest&&e.target.closest('a[data-wait]');
  if(!a||e.defaultPrevented||e.button!==0||e.ctrlKey||e.metaKey||e.shiftKey||e.altKey||a.target)return;kpWait(a);});
// every other page change: the same overlay, but only once the next page is
// 0.7 s late, so fast pages never flash it. Downloads/exports never replace
// the page, so they are skipped (URL pattern, a[download], data-nowait); a
// click on the generic overlay (or 60 s) clears it in case one slips through.
(function(){let t=0;
  const dl=/\/(download|download-zip|export|rfile)(\?|$)/;
  const later=(e,msg,note)=>{clearTimeout(t);t=setTimeout(()=>{if(e.defaultPrevented||document.querySelector('.kpwait'))return;
    kpWait({dataset:{wait:msg,waitNote:note}});const o=document.querySelector('.kpwait');if(!o)return;
    o.title='Click to hide';o.addEventListener('click',()=>o.remove());setTimeout(()=>o.remove(),60000);},700);};
  document.addEventListener('click',e=>{const a=e.target.closest&&e.target.closest('a[href]');
    if(!a||a.dataset.wait!==undefined||a.dataset.nowait!==undefined||a.hasAttribute('download')||a.target||e.button!==0||e.ctrlKey||e.metaKey||e.shiftKey||e.altKey)return;
    let u;try{u=new URL(a.href,location.href);}catch(x){return;}
    if(u.origin!==location.origin||dl.test(u.pathname)||(u.pathname===location.pathname&&u.search===location.search&&u.hash))return;
    later(e,'Loading — please wait…','This page is taking a moment.');});
  document.addEventListener('submit',e=>{const f=e.target;if(!f||!f.dataset||f.dataset.wait||f.dataset.nowait!==undefined||f.target)return;
    let u;try{u=new URL(f.getAttribute('action')||location.href,location.href);}catch(x){return;}
    if(u.origin!==location.origin||dl.test(u.pathname))return;
    later(e,'Working — please wait…','Still going — the page updates when it is done.');});
  window.addEventListener('pageshow',e=>{clearTimeout(t);});})();
// back button restores the page from cache with the overlay still up
window.addEventListener('pageshow',e=>{if(e.persisted)document.querySelectorAll('.kpwait').forEach(o=>o.remove());});
// background SSL requests ([data-sslwait] rows): poll, reload once a row is done
(function(){const w=[...document.querySelectorAll('[data-sslwait]')].map(x=>x.dataset.sslwait);if(!w.length)return;
  const t0=Date.now();let busy=false;
  const reload=()=>{const a=document.activeElement;if(a&&/^(INPUT|TEXTAREA|SELECT)$/.test(a.tagName)&&a.value)return false;location.reload();return true;};
  const tick=()=>{if(busy)return;busy=true;
    fetch('/ssl/pending',{credentials:'same-origin',cache:'no-store'}).then(r=>r.ok?r.json():null).then(j=>{busy=false;
      const st=(j&&j.states)||{};
      if(w.some(d=>st[d]!=='running')||Date.now()-t0>360000){if(reload())clearInterval(iv);}
    }).catch(()=>{busy=false;});};
  const iv=setInterval(tick,4000);})();
// row "⋯" menus (details.acmore: Accounts, Domains): fixed-positioned so a
// card's horizontal scroll can't clip them; one open at a time, outside click / Esc closes
(function(){
  var menus=[].slice.call(document.querySelectorAll('details.acmore'));
  function place(d){var m=d.querySelector('.acmenu'),r=d.querySelector('summary').getBoundingClientRect(),w=m.offsetWidth,h=m.offsetHeight;
    var left=Math.max(8,Math.min(r.right-w,innerWidth-w-8)),top=r.bottom+6;if(top+h>innerHeight-8)top=Math.max(8,r.top-h-6);m.style.left=left+'px';m.style.top=top+'px';}
  function closeAll(except){menus.forEach(function(d){if(d!==except)d.open=false;});}
  menus.forEach(function(d){d.addEventListener('toggle',function(){if(d.open){closeAll(d);place(d);}});});
  document.addEventListener('click',function(e){if(!e.target.closest('details.acmore'))closeAll();});
  document.addEventListener('keydown',function(e){if(e.key==='Escape')closeAll();});
  // follow the button (page/card scroll, phone keyboard resizing) instead of closing mid-typing
  function follow(){menus.forEach(function(d){if(d.open)place(d);});}
  window.addEventListener('resize',follow);window.addEventListener('scroll',follow,true);
})();
// row menus (details.emenu): one open at a time, outside click / Esc closes
document.addEventListener('toggle',e=>{const d=e.target;if(!d.matches||!d.matches('details.emenu')||!d.open)return;
  document.querySelectorAll('details.emenu[open]').forEach(o=>{if(o!==d)o.open=false;});},true);
document.addEventListener('click',e=>{if(e.target.closest('details.emenu'))return;document.querySelectorAll('details.emenu[open]').forEach(o=>o.open=false);});
document.addEventListener('keydown',e=>{if(e.key==='Escape')document.querySelectorAll('details.emenu[open]').forEach(o=>o.open=false);});
// Email filter forms: spam score hides the match type; discard hides the target
document.addEventListener('change',e=>{const t=e.target,f=t.closest&&t.closest('form.ffilter');if(!f)return;
  if(t.matches('[data-ffield]')){f.classList.toggle('isspam',t.value==='spam');const m=f.querySelector('[name=match]');if(m)m.placeholder=t.value==='spam'?'score, e.g. 5':'text…';}
  if(t.matches('[data-faction]'))f.classList.toggle('isdiscard',t.value==='discard');});
// [data-reloadin=N]: a background job is running — reload every N seconds
// (skipped while the user is typing) until the server stops sending it
(function(){const el=document.querySelector('[data-reloadin]');if(!el)return;
  const s=Math.max(3,parseInt(el.dataset.reloadin,10)||5);
  setInterval(()=>{const a=document.activeElement;if(a&&/^(INPUT|TEXTAREA|SELECT)$/.test(a.tagName)&&a.value)return;location.reload();},s*1000);})();
// Builder New site: template category filter, chosen name, live preview dialog
(function(){
  const dlg=document.getElementById('kbtpl-dlg'); if(!dlg) return;
  const fr=document.getElementById('kbtpl-dlg-frame'), chosen=document.getElementById('kbtpl-chosen');
  let cur='';
  const pick=v=>{const r=document.querySelector('.kbtpl input[value="'+CSS.escape(v)+'"]');if(r){r.checked=true;r.dispatchEvent(new Event('change',{bubbles:true}));}};
  document.addEventListener('change',e=>{if(e.target.matches&&e.target.matches('.kbtpl input[type=radio]')&&chosen) chosen.textContent=e.target.dataset.name||e.target.value;});
  document.addEventListener('click',e=>{
    const c=e.target.closest?e.target.closest('.kbcat'):null;
    if(c){
      document.querySelectorAll('.kbcat').forEach(b=>b.classList.toggle('on',b===c));
      document.querySelectorAll('.kbtpl,.kbpick-cat').forEach(el=>{el.style.display=!c.dataset.cat||el.dataset.cat===c.dataset.cat||(el.dataset.cat===''&&!c.dataset.cat)?'':'none';});
      return;
    }
    const v=e.target.closest?e.target.closest('.kbtpl-view'):null;
    if(v){
      cur=v.dataset.tpl;
      const u='/builder/template/'+encodeURIComponent(cur)+'/';
      document.getElementById('kbtpl-dlg-name').textContent=v.dataset.name||cur;
      document.getElementById('kbtpl-dlg-open').href=u;
      fr.src=u; dlg.showModal(); return;
    }
    const d=e.target.closest?e.target.closest('.kbtpl-dev button'):null;
    if(d){document.querySelectorAll('.kbtpl-dev button').forEach(b=>b.classList.toggle('on',b===d));fr.style.width=d.dataset.w?d.dataset.w+'px':'';return;}
    if(e.target.id==='kbtpl-dlg-use'){pick(cur);dlg.close();return;}
    if(e.target.id==='kbtpl-dlg-close'||e.target===dlg) dlg.close();
  });
  dlg.addEventListener('close',()=>{fr.removeAttribute('src');});
})();

const t=document.getElementById('themeToggle');
const root=document.documentElement;
root.dataset.theme=localStorage.getItem('skypanel-theme')||'dark';
t&&t.addEventListener('click',()=>{root.dataset.theme=root.dataset.theme==='dark'?'light':'dark';localStorage.setItem('skypanel-theme',root.dataset.theme);});
const s=document.getElementById('toolSearch');
s&&s.addEventListener('input',e=>{
  const q=e.target.value.toLowerCase();
  document.querySelectorAll('.tool').forEach(el=>{
    el.style.display=el.textContent.toLowerCase().includes(q)?'':'none';
  });
});
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

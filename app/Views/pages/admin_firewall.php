<?php
$csrf = Helpers::csrf();
$labels = ['open' => ['Open ports', 'reachable from anywhere'], 'restricted' => ['Restricted', 'open only to the listed addresses'],
           'closed' => ['Closed ports', 'denied even if a service listens'], 'blocked' => ['Blocked IPs', 'Block IP + fail2ban bans']];
$openCount = count($groups['open'] ?? []);
?>
<div class="fwhead"><h2>🧱 Firewall</h2>
<span class="pill <?= $active === 'active' ? 'ok' : 'err' ?>">UFW <?= Helpers::e($active) ?></span>
<span class="pill" id="f2bPill">fail2ban …</span></div>

<div class="fwtiles">
<div class="fwtile" id="tNowT"><small>Banned now</small><b id="tNow">…</b></div>
<div class="fwtile" id="tSerT"><small>Serious</small><b id="tSer">…</b></div>
<div class="fwtile"><small>Bans in 24 h</small><b id="tDay">…</b></div>
<div class="fwtile"><small>Never-ban list</small><b><?= count($allow) ?></b></div>
<div class="fwtile"><small>Open ports</small><b><?= $openCount ?></b></div>
</div>

<div class="card" id="bans"><div class="fwcardhead"><h3>🚨 Recent bans <small>live · every 10 s</small></h3><small id="bansSum" class="muted"></small></div>
<div class="fwchips" id="bansJails"></div>
<label class="fwcheck"><input type="checkbox" id="bansAll"> include expired bans (last 24 h)</label>
<table class="list bans"><thead><tr><th>When</th><th>IP</th><th>Reason</th><th>Tried</th><th>Status</th><th></th></tr></thead>
<tbody id="bansRows"><tr><td colspan="6" class="muted">Loading…</td></tr></tbody></table>
<small class="muted">Flashing red = serious: an attack on the panel itself, an IP banned 3+ times today, or one hitting several services. Unban lifts the IP from every jail; <b>Always allow</b> also adds it to the never-ban list.</small></div>

<div class="fwcols">
<div class="card"><h3>⚡ Quick actions</h3>
<form class="fwact" method="post" action="/admin/firewall-allow">
<input type="hidden" name="csrf" value="<?=$csrf?>"><span>Open a port</span>
<input name="port" type="number" min="1" max="65535" placeholder="port" required>
<select name="proto"><option value="tcp">tcp</option><option value="udp">udp</option></select>
<button class="btn small">Open</button></form>
<form class="fwact" method="post" action="/admin/firewall-deny">
<input type="hidden" name="csrf" value="<?=$csrf?>"><span>Close a port</span>
<input name="port" type="number" min="1" max="65535" placeholder="port" title="any port except 22" required>
<select name="proto"><option value="tcp">tcp</option><option value="udp">udp</option></select>
<button class="btn small danger">Close</button></form>
<form class="fwact" method="post" action="/admin/firewall-block" onsubmit="return confirm('Block this IP on every port?')">
<input type="hidden" name="csrf" value="<?=$csrf?>"><span>Block an IP</span>
<input name="ip" placeholder="203.0.113.45" required class="wide">
<button class="btn small danger">Block</button></form>
<small class="muted">Closing a port beats a listening service. SSH (22) can't be closed or deleted here — lockout protection lives in the script, not just the page.</small></div>

<div class="card"><h3>🛡️ Never ban <small>fail2ban ignores these</small></h3>
<form class="fwact" method="post" action="/admin/firewall-allowip">
<input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="op" value="add">
<input name="net" id="allowNet" placeholder="IP or network (203.0.113.0/24)" required class="wide">
<input name="label" placeholder="label (office…)" maxlength="40" pattern="[A-Za-z0-9 ._@-]{0,40}">
<button class="btn small">Add</button></form>
<?php if ($myIp !== ''): ?><small class="muted">Your IP is <code><?= Helpers::e($myIp) ?></code> <button type="button" class="btn small ghost" onclick="var f=document.getElementById('allowNet');f.value=<?= Helpers::e(json_encode($myIp)) ?>;f.form.label.value='my IP';f.focus()">use it</button></small><?php endif; ?>
<table class="list fwallow">
<?php foreach ($allow as $a): ?><tr><td><code><?= Helpers::e($a['net']) ?></code></td><td class="muted"><?= Helpers::e($a['label']) ?></td>
<td class="fwx"><form method="post" action="/admin/firewall-allowip" onsubmit="return confirm('Let fail2ban ban <?= Helpers::e($a['net']) ?> again?')"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="op" value="del"><input type="hidden" name="net" value="<?= Helpers::e($a['net']) ?>"><button class="btn small danger">Remove</button></form></td></tr><?php endforeach; ?>
<?php if (!$allow): ?><tr><td class="muted">Empty — localhost is always ignored. Add your office or home IP if you keep tripping bans.</td></tr><?php endif; ?>
</table></div>
</div>

<div class="card"><div class="fwcardhead"><h3>📜 Firewall rules</h3><small class="muted">IPv4 + IPv6 copies of a rule are shown once and deleted together</small></div>
<?php foreach ($labels as $k => [$title, $hint]): $rs = $groups[$k] ?? []; ?>
<h4 class="fwsec"><?= $title ?> <span class="badge"><?= count($rs) ?></span> <small class="muted"><?= $hint ?></small></h4>
<?php if (!$rs): ?><p class="muted fwnone">None.</p><?php else: ?>
<table class="list fwrules"><thead><tr><th>Service</th><th>Port</th><th><?= $k === 'blocked' || $k === 'restricted' ? 'Address' : 'From' ?></th><th>IP</th><th></th></tr></thead><tbody>
<?php foreach ($rs as $r): ?><tr class="fw-<?= strtolower($r['action']) ?>">
<td><?= $r['service'] !== '' ? '<b>'.Helpers::e($r['service']).'</b>' : '<span class="muted">'.($r['to'] === 'Anywhere' ? 'All ports' : 'Custom').'</span>' ?><?= $r['action'] === 'LIMIT' ? ' <span class="badge">rate-limited</span>' : '' ?><?= $r['comment'] !== '' ? ' <small class="muted">'.Helpers::e($r['comment']).'</small>' : '' ?></td>
<td><code><?= Helpers::e($r['to']) ?></code></td>
<td><?= $r['from'] === 'Anywhere' ? '<span class="muted">anywhere</span>' : '<code>'.Helpers::e($r['from']).'</code>' ?></td>
<td><?= $r['v4'] ? '<span class="badge">v4</span>' : '' ?><?= $r['v6'] ? ' <span class="badge">v6</span>' : '' ?></td>
<td><?php if ($r['ssh']): ?><span class="muted" title="SSH rules can't be deleted from the panel">🔒 protected</span><?php else: ?>
<form method="post" action="/admin/firewall-delete" onsubmit="return confirm(<?= Helpers::e(json_encode('Delete this rule ('.($r['service'] ?: $r['to']).' '.$r['action'].' from '.$r['from'].')? Remote users may lose access.')) ?>)"><input type="hidden" name="csrf" value="<?=$csrf?>"><?php foreach ($r['specs'] as $sp): ?><input type="hidden" name="spec[]" value="<?= Helpers::e($sp) ?>"><?php endforeach; ?><button class="btn small danger">Delete</button></form><?php endif; ?></td></tr>
<?php endforeach; ?></tbody></table><?php endif; ?>
<?php endforeach; ?>
<?php if ($active !== 'active'): ?><p class="muted">UFW is not active — these rules are not being enforced.</p><?php endif; ?>
</div>
<input type="hidden" id="bansCsrf" value="<?=$csrf?>">
<script>
(function(){
  var tb=document.getElementById('bansRows'),all=document.getElementById('bansAll'),sum=document.getElementById('bansSum'),chips=document.getElementById('bansJails'),csrf=document.getElementById('bansCsrf').value;
  var seen=null,last=[],jails=[],jf='';
  function el(t,c,txt){var e=document.createElement(t);if(c)e.className=c;if(txt!=null)e.textContent=txt;return e;}
  function msg(t){tb.innerHTML='';var tr=el('tr'),td=el('td','muted',t);td.colSpan=6;tr.appendChild(td);tb.appendChild(tr);}
  function post(action,fields,label,cls,ask){var f=el('form');f.method='post';f.action=action;f.style.display='inline';
    Object.keys(fields).forEach(function(k){var i=el('input');i.type='hidden';i.name=k;i.value=fields[k];f.appendChild(i);});
    f.appendChild(el('button','btn small '+(cls||''),label));f.addEventListener('submit',function(e){if(!confirm(ask))e.preventDefault();});return f;}
  function renderChips(){
    var cnt={};last.forEach(function(b){if(b.active)cnt[b.jail]=(cnt[b.jail]||0)+1;});
    chips.innerHTML='';
    [''].concat(jails).forEach(function(j){var n=j?(cnt[j]||0):Object.values(cnt).reduce(function(a,b){return a+b;},0);
      var c=el('button','fwchip'+(jf===j?' on':'')+(n?' hot':''),(j||'All jails')+' ');c.type='button';c.appendChild(el('span',null,String(n)));
      c.addEventListener('click',function(){jf=j;renderChips();render();});chips.appendChild(c);});
  }
  function render(){
    var rows=last.filter(function(b){return (all.checked||b.active)&&(!jf||b.jail===jf);});
    rows.sort(function(a,b){return (b.active-a.active)||(b.serious-a.serious)||(b.ts-a.ts);});
    if(!rows.length){msg(jf?'No bans in '+jf+(all.checked?' in the last 24 hours.':' right now.'):(all.checked?'No bans in the last 24 hours.':'Nobody is banned right now. 🎉'));return;}
    tb.innerHTML='';
    rows.slice(0,100).forEach(function(b){
      var tr=el('tr',(b.active&&b.serious?'serious ':'')+(b.active?'':'expired ')+(b.fresh?'fresh':''));
      tr.appendChild(el('td',null,b.at?b.at.slice(5,16):'—'));
      var ip=el('td');ip.appendChild(el('code',null,b.ip));if(b.me){ip.appendChild(document.createTextNode(' '));ip.appendChild(el('span','pill err','your IP'));}tr.appendChild(ip);
      var r=el('td');r.appendChild(el('b',null,b.reason));r.appendChild(el('span','badge fwjail',b.jail));
      if(b.why)r.appendChild(el('div','banwhy','⚠ '+b.why));
      var nf=b.fails||b.lines.length;
      if(b.lines.length){var d=el('details');d.appendChild(el('summary',null,nf+' failed attempt'+(nf==1?'':'s')+' — show log lines'));d.appendChild(el('pre','banlog',b.lines.join('\n')));r.appendChild(d);}
      tr.appendChild(r);
      tr.appendChild(el('td',null,b.users.length?b.users.join(', '):'—'));
      tr.appendChild(el('td',null,b.active?('banned'+(b.until?(b.until=='permanent'?' permanently':' until '+b.until):'')):'expired'));
      var a=el('td','fwbtns');
      if(b.active)a.appendChild(post('/admin/firewall-unban',{csrf:csrf,ip:b.ip},'Unban','','Unban '+b.ip+' from every fail2ban jail?'));
      a.appendChild(post('/admin/firewall-allowip',{csrf:csrf,op:'add',net:b.ip,label:'false positive'},'Always allow','ghost','Unban '+b.ip+' and never ban it again? Only do this for addresses you trust.'));
      tr.appendChild(a);tb.appendChild(tr);
    });
  }
  function setT(id,v){document.getElementById(id).textContent=v;}
  async function poll(){
    if(document.hidden)return;
    try{var r=await fetch('/admin/firewall-bans',{credentials:'same-origin',cache:'no-store'});var d=await r.json();
      var pill=document.getElementById('f2bPill');
      if(d.error){pill.className='pill err';pill.textContent='fail2ban down';msg('fail2ban: '+d.error);['tNow','tSer','tDay'].forEach(function(i){setT(i,'—');});return;}
      pill.className='pill ok';pill.textContent='fail2ban · '+d.jails.length+' jails';
      var keys={};d.bans.forEach(function(b){var k=b.jail+'|'+b.ip+'|'+b.ts;keys[k]=1;b.fresh=seen!==null&&!seen[k];});
      seen=keys;last=d.bans;jails=d.jails;
      var act=d.bans.filter(function(b){return b.active;}),ser=act.filter(function(b){return b.serious;});
      setT('tNow',act.length);setT('tSer',ser.length);setT('tDay',d.bans.length);
      document.getElementById('tSerT').classList.toggle('alert',ser.length>0);
      sum.textContent='updated '+d.at;
      if(!tb.querySelector('details[open]')){renderChips();render();}
    }catch(e){sum.textContent='⚠ could not refresh';}
  }
  all.addEventListener('change',render);
  poll();setInterval(poll,10000);document.addEventListener('visibilitychange',function(){if(!document.hidden)poll();});
})();
</script>

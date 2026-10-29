<div class="fwhead"><h2>🔥 Abuse board</h2>
<span class="pill ok" id="abLive">● live · every 5 s</span>
<span class="muted" id="abAt"></span></div>

<div class="fwtiles">
  <div class="fwtile" id="tLoad"><small>Load (1 min)</small><b>—</b><span class="muted abtsub"></span></div>
  <div class="fwtile" id="tCpu"><small>CPU in use</small><b>—</b><span class="abbar"><i></i></span></div>
  <div class="fwtile" id="tMem"><small>RAM in use</small><b>—</b><span class="abbar"><i></i></span></div>
  <div class="fwtile" id="tSwap"><small>Swap</small><b>—</b><span class="muted abtsub"></span></div>
  <div class="fwtile" id="tProcs"><small>Processes</small><b>—</b><span class="muted abtsub"></span></div>
  <div class="fwtile" id="tHot"><small>Customers over limit</small><b>—</b><span class="muted abtsub"></span></div>
</div>
<div id="abMsg" class="abmsg" hidden></div>

<div class="card">
  <div class="fwcardhead"><h3>👥 Users right now <small>CPU measured over the last second · trend = last 5 min</small></h3>
  <div class="fwchips" id="uScope" style="margin:0"></div></div>
  <div class="tablewrap"><table class="abt"><thead><tr><th>User</th><th>CPU</th><th>Trend</th><th>RAM</th><th>Procs</th><th>Status</th><th></th></tr></thead>
  <tbody id="uRows"><tr><td colspan="7" class="muted">Sampling…</td></tr></tbody></table></div>
  <small class="muted">Flashing red = over the alert limits (CPU over <?= AdminController::ABUSE_CPU ?>% = <?= AdminController::ABUSE_CPU / 100 ?> cores, or RAM over <?= AdminController::ABUSE_MEM ?>%). If it stays over for two 5-minute checks, admins get an email and a notification (<b>⚠ alerted</b>). 100% CPU = one full core.</small>
</div>

<div class="card" id="procCard">
  <div class="fwcardhead"><h3>⚙️ Processes <small>busiest first</small></h3>
  <input type="search" id="pFind" placeholder="filter: user, command, PID…" class="abfind"></div>
  <div class="fwchips" id="pUsers"></div>
  <div class="tablewrap"><table class="abt"><thead><tr><th>PID</th><th>User</th><th>CPU</th><th>RAM</th><th>Running</th><th>Command</th><th></th></tr></thead>
  <tbody id="pRows"></tbody></table></div>
  <small class="muted">Kill stops the process (TERM, then forced after 3 s). PHP and web workers start again on the next request, so to stop a site that keeps re-offending, suspend the account. Only customer processes can be killed here.</small>
</div>

<div class="card">
  <div class="fwcardhead"><h3>🗄️ Database queries <small>customers' MariaDB queries running now</small></h3></div>
  <div class="tablewrap"><table class="abt"><thead><tr><th>User</th><th>Database</th><th>Time</th><th>State</th><th>Query</th></tr></thead>
  <tbody id="qRows"></tbody></table></div>
</div>

<div class="card"><h3>📦 Customers vs quotas</h3>
<div class="tablewrap"><table class="abt"><thead><tr><th>User</th><th>Disk</th><th>BW month</th><th>Sent today</th><th></th></tr></thead><tbody>
<?php foreach($users as $x):
  $dq = (float)$x['disk_quota_mb']; $dp = $dq > 0 ? min(100, round((float)$x['disk_used_mb'] * 100 / $dq)) : 0;
  $bq = (int)$x['bandwidth_quota_gb'] * 1024; $bp = ($bq > 0 && $x['bw_month_mb'] !== null) ? min(100, round($x['bw_month_mb'] * 100 / $bq)) : 0; ?>
<tr><td><b><?=Helpers::e($x['username'])?></b> <?php if($x['status'] !== 'active'):?><span class="pill mini warn"><?=Helpers::e($x['status'])?></span><?php endif;?></td>
<td><?=Helpers::e(Helpers::fmtMb((float)$x['disk_used_mb']))?> / <?= $dq > 0 ? Helpers::e(Helpers::fmtMb($dq)) : 'unlimited' ?><?php if($dq > 0):?><span class="abbar sm<?= $dp >= 90 ? ' err' : ($dp >= 70 ? ' warn' : '') ?>"><i style="width:<?=$dp?>%"></i></span><?php endif;?></td>
<td><?= $x['bw_month_mb'] === null ? '—' : Helpers::e($x['bw_month_mb'].' MB').' / '.($bq > 0 ? (int)$x['bandwidth_quota_gb'].' GB' : 'unlimited') ?><?php if($bq > 0 && $x['bw_month_mb'] !== null):?><span class="abbar sm<?= $bp >= 90 ? ' err' : ($bp >= 70 ? ' warn' : '') ?>"><i style="width:<?=$bp?>%"></i></span><?php endif;?></td>
<td><?= ($x['sent_today'] ?? null) === null ? '—' : number_format((int)$x['sent_today']) ?></td>
<td class="fwbtns"><?php if($x['status'] === 'active'):?><form method="post" action="/accounts/suspend" style="display:inline"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><input type="hidden" name="username" value="<?=Helpers::e($x['username'])?>"><button class="btn small ghost" name="action" value="suspend" onclick="return confirm('Suspend <?=Helpers::e($x['username'])?>? Sites, mail and FTP stop until unsuspended.')">Suspend</button></form><?php endif;?></td></tr><?php endforeach;?>
<?php if(empty($users)):?><tr><td colspan="5" class="muted">No customer accounts.</td></tr><?php endif;?></tbody></table></div>
<small class="muted">Guardrails already in place: per-user FPM worker caps, 256M per-script memory, niced pools, MySQL query/update/connection caps on new DBs.</small></div>
<input type="hidden" id="abCsrf" value="<?=Helpers::csrf()?>">

<script>
(function(){
  var csrf=document.getElementById('abCsrf').value, hist={}, last=null, scope='customers', pu='', busy=false, timer=null;
  function $(id){return document.getElementById(id);}
  function el(t,c,txt){var e=document.createElement(t);if(c)e.className=c;if(txt!=null)e.textContent=txt;return e;}
  function mb(kb){return kb>=1048576?(kb/1048576).toFixed(1)+' GB':kb>=1024?Math.round(kb/1024)+' MB':kb+' KB';}
  function dur(s){if(s<60)return s+'s';if(s<3600)return Math.floor(s/60)+'m';if(s<86400)return Math.floor(s/3600)+'h '+Math.floor(s%3600/60)+'m';return Math.floor(s/86400)+'d '+Math.floor(s%86400/3600)+'h';}
  function lvl(p){return p>=90?' err':p>=60?' warn':'';}
  function bar(pct,label,cls){var w=el('div','abcell'+(cls||''));var b=el('span','abbar'+lvl(pct));var i=el('i');i.style.width=Math.min(100,Math.max(0,pct))+'%';b.appendChild(i);w.appendChild(el('b',null,label));w.appendChild(b);return w;}
  function empty(tb,n,t){tb.innerHTML='';var tr=el('tr'),td=el('td','muted',t);td.colSpan=n;tr.appendChild(td);tb.appendChild(tr);}
  function flash(t,bad){var m=$('abMsg');m.textContent=t;m.className='abmsg'+(bad?' bad':'');m.hidden=false;clearTimeout(m._t);m._t=setTimeout(function(){m.hidden=true;},7000);}
  function spark(a){var ns='http://www.w3.org/2000/svg',s=document.createElementNS(ns,'svg');s.setAttribute('viewBox','0 0 90 24');s.setAttribute('class','abspark');
    if(a.length<2)return s;var mx=Math.max(100,Math.max.apply(null,a)),pts=a.map(function(v,i){return (i*90/(a.length-1)).toFixed(1)+','+(23-v*21/mx).toFixed(1);}).join(' ');
    var pl=document.createElementNS(ns,'polyline');pl.setAttribute('points',pts);s.appendChild(pl);return s;}
  function tile(id,val,sub,pct,alert){var t=$(id);t.querySelector('b').textContent=val;var s=t.querySelector('.abtsub');if(s)s.textContent=sub||'';
    var i=t.querySelector('.abbar i');if(i){i.style.width=Math.min(100,pct)+'%';i.parentNode.className='abbar'+lvl(pct);}t.classList.toggle('alert',!!alert);}
  function act(fields,ask){if(!confirm(ask))return;var fd=new FormData();fd.append('csrf',csrf);Object.keys(fields).forEach(function(k){fd.append(k,fields[k]);});
    fetch('/admin/abuse-kill',{method:'POST',body:fd,credentials:'same-origin'}).then(function(r){return r.json();}).then(function(d){flash(d.msg||'done',!d.ok);poll();}).catch(function(){flash('Request failed — reload the page',true);});}
  function suspend(u){if(!confirm('Suspend '+u+'? Sites, mail and FTP stop until unsuspended.'))return;var f=el('form');f.method='post';f.action='/accounts/suspend';
    [['csrf',csrf],['username',u],['action','suspend']].forEach(function(p){var i=el('input');i.type='hidden';i.name=p[0];i.value=p[1];f.appendChild(i);});document.body.appendChild(f);f.submit();}
  function btn(label,cls,fn){var b=el('button','btn small '+(cls||''),label);b.type='button';b.addEventListener('click',fn);return b;}

  function renderUsers(){
    var d=last,rows=d.users.filter(function(u){return scope==='all'||u.customer;});
    var sc=$('uScope');sc.innerHTML='';
    [['customers','Customers',d.users.filter(function(u){return u.customer;}).length],['all','Everyone',d.users.length]].forEach(function(c){
      var b=el('button','fwchip'+(scope===c[0]?' on':''),c[1]+' ');b.type='button';b.appendChild(el('span',null,String(c[2])));b.addEventListener('click',function(){scope=c[0];renderUsers();});sc.appendChild(b);});
    var tb=$('uRows');if(!rows.length){empty(tb,7,'No customer accounts yet.');return;}
    tb.innerHTML='';
    rows.forEach(function(u){
      var tr=el('tr',(u.hot?'hot':'')+(u.procs?'':' idle'));
      var n=el('td');n.appendChild(el('b',null,u.name));if(u.role&&u.role!=='user')n.appendChild(el('span','badge fwjail',u.role));if(!u.customer)n.appendChild(el('span','badge fwjail absys','system'));tr.appendChild(n);
      var c=el('td',u.procs?null:'muted',u.procs?null:'—');if(u.procs)c.appendChild(bar(u.cpu*100/d.limits.cpu,u.cpu+'%'));c.title=u.box+'% of the whole server ('+d.host.cpus+' cores)';tr.appendChild(c);
      var sp=el('td');sp.appendChild(spark(hist[u.name]||[]));tr.appendChild(sp);
      var m=el('td',u.procs?null:'muted',u.procs?null:'—');if(u.procs)m.appendChild(bar(u.mem*100/d.limits.mem,mb(u.rss),''));m.title=u.mem+'% of RAM';tr.appendChild(m);
      tr.appendChild(el('td',null,String(u.procs)));
      var s=el('td');
      if(u.status&&u.status!=='active')s.appendChild(el('span','pill mini warn',u.status));
      if(u.alert==='alerted')s.appendChild(el('span','pill mini err','⚠ alerted'));else if(u.alert==='watch')s.appendChild(el('span','pill mini warn','watching'));
      if(u.why)s.appendChild(el('div','banwhy','🔥 '+u.why));
      if(!s.childNodes.length)s.appendChild(el('span','muted',u.procs?'ok':'idle'));
      tr.appendChild(s);
      var a=el('td','fwbtns');
      if(u.procs)a.appendChild(btn('Processes','ghost',function(){pu=u.name;renderProcs();$('procCard').scrollIntoView({behavior:'smooth'});}));
      if(u.customer&&u.procs){a.appendChild(document.createTextNode(' '));a.appendChild(btn('Kill all','danger',function(){act({op:'killuser',user:u.name},'Stop ALL '+u.procs+' processes of '+u.name+'? Their SSH sessions, cron jobs and PHP workers end; sites keep working as new workers start.');}));}
      if(u.customer&&u.role!=='admin'&&u.status==='active'){a.appendChild(document.createTextNode(' '));a.appendChild(btn('Suspend','ghost',function(){suspend(u.name);}));}
      tr.appendChild(a);tb.appendChild(tr);});
  }
  function renderProcs(){
    var d=last,q=$('pFind').value.trim().toLowerCase(),cnt={},chips=$('pUsers');
    d.procs.forEach(function(p){if(p.customer)cnt[p.user]=(cnt[p.user]||0)+1;});
    chips.innerHTML='';
    var hot={};d.procs.forEach(function(p){if(p.hot)hot[p.user]=1;});
    [['','Customers',d.procs.filter(function(p){return p.customer;}).length]].concat(Object.keys(cnt).sort().map(function(u){return [u,u,cnt[u]];})).concat([['*','Everyone',d.procs.length]]).forEach(function(c){
      var b=el('button','fwchip'+(pu===c[0]?' on':'')+(hot[c[0]]?' hot':''),c[1]+' ');b.type='button';b.appendChild(el('span',null,String(c[2])));b.addEventListener('click',function(){pu=c[0];renderProcs();});chips.appendChild(b);});
    var rows=d.procs.filter(function(p){return (pu==='*'||(pu===''?p.customer:p.user===pu))&&(!q||(p.user+' '+p.pid+' '+p.args).toLowerCase().indexOf(q)>=0);});
    var tb=$('pRows');
    if(!rows.length){empty(tb,7,pu==='*'||q?'No matching processes.':'No customer processes running right now — sites are idle. 🎉');return;}
    tb.innerHTML='';
    rows.slice(0,150).forEach(function(p){
      var tr=el('tr',p.hot?'hot':'');
      tr.appendChild(el('td','mono',String(p.pid)));
      var u=el('td');u.appendChild(el('span',null,p.user));tr.appendChild(u);
      var c=el('td');c.appendChild(bar(p.cpu,p.cpu+'%',''));tr.appendChild(c);
      var m=el('td','nw',mb(p.rss));m.title=p.mem+'% of RAM';tr.appendChild(m);
      tr.appendChild(el('td','nw',dur(p.age)));
      var cm=el('td','abcmd');var code=el('code',null,p.args.length>110?p.args.slice(0,110)+'…':p.args);code.title=p.args;cm.appendChild(code);
      if(p.threads>1)cm.appendChild(el('span','badge fwjail',p.threads+' threads'));
      p.flags.forEach(function(f){cm.appendChild(el('span','badge fwjail abflag',f));});
      tr.appendChild(cm);
      var a=el('td','fwbtns');
      if(p.customer)a.appendChild(btn('Kill','danger',function(){act({op:'kill',pid:p.pid,start:p.start},'Kill process '+p.pid+' ('+p.comm+') of '+p.user+'?');}));
      tr.appendChild(a);tb.appendChild(tr);});
    if(rows.length>150){var tr=el('tr'),td=el('td','muted','… '+(rows.length-150)+' more — narrow it with the filter.');td.colSpan=7;tr.appendChild(td);tb.appendChild(tr);}
  }
  function renderQueries(){
    var tb=$('qRows'),rows=last.mysql;
    if(!rows.length){empty(tb,5,'No customer queries running right now.');return;}
    tb.innerHTML='';
    rows.forEach(function(q){var tr=el('tr',q.hot?'hot':'');
      var u=el('td');u.appendChild(el('b',null,q.user));u.appendChild(el('div','muted',q.dbuser));tr.appendChild(u);
      tr.appendChild(el('td','mono',q.db||'—'));tr.appendChild(el('td',null,dur(q.time)));tr.appendChild(el('td',null,q.state||'—'));
      var c=el('td','abcmd');var code=el('code',null,q.query.length>160?q.query.slice(0,160)+'…':q.query);code.title=q.query;c.appendChild(code);tr.appendChild(c);
      tb.appendChild(tr);});
  }
  function render(d){
    last=d;var h=d.host;
    d.users.forEach(function(u){var a=hist[u.name]=hist[u.name]||[];a.push(u.cpu);if(a.length>60)a.shift();});
    var lp=h.load[0]*100/h.cpus;
    tile('tLoad',h.load[0].toFixed(2),'5m '+h.load[1].toFixed(2)+' · 15m '+h.load[2].toFixed(2)+' · '+h.cpus+' cores',0,lp>=150);
    tile('tCpu',h.busy+'%',null,h.busy,h.busy>=90);
    var mp=h.mem_used*100/h.mem_total;tile('tMem',mb(h.mem_used),null,mp,mp>=92);
    $('tMem').querySelector('small').textContent='RAM · '+Math.round(mp)+'% of '+mb(h.mem_total);
    tile('tSwap',h.swap_total?mb(h.swap_used):'none',h.swap_total?'of '+mb(h.swap_total):'no swap configured',0,h.swap_total&&h.swap_used*100/h.swap_total>=50);
    tile('tProcs',String(h.procs),'up '+dur(h.uptime),0,false);
    var hot=d.users.filter(function(u){return u.hot;});
    tile('tHot',String(hot.length),hot.length?hot.map(function(u){return u.name;}).join(', '):'all within limits',0,hot.length>0);
    $('abAt').textContent='updated '+d.at;
    renderUsers();renderProcs();renderQueries();
  }
  function poll(){
    if(busy)return;busy=true;
    fetch('/admin/abuse-live',{credentials:'same-origin'}).then(function(r){if(r.redirected||(r.headers.get('content-type')||'').indexOf('json')<0)return {error:'session expired — reload the page'};return r.json();}).then(function(d){
      busy=false;var lv=$('abLive');
      if(d.error){lv.className='pill err';lv.textContent='● '+d.error;return;}
      lv.className='pill ok';lv.textContent='● live · every 5 s';render(d);
    }).catch(function(){busy=false;var lv=$('abLive');lv.className='pill err';lv.textContent='● connection lost — retrying';});
  }
  $('pFind').addEventListener('input',function(){if(last)renderProcs();});
  function start(){clearInterval(timer);poll();timer=setInterval(poll,5000);}
  document.addEventListener('visibilitychange',function(){if(document.hidden){clearInterval(timer);$('abLive').className='pill';$('abLive').textContent='● paused (tab hidden)';}else start();});
  start();
})();
</script>

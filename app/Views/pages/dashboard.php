<?php $isAdmin = ($u['role'] ?? '') === 'admin'; $isRes = ($u['role'] ?? '') !== 'user'; $csrf = Helpers::csrf();
$webmail = Helpers::serviceUrl(8091, 'app.webmail_url'); $pma = Helpers::serviceUrl(8092, 'app.phpmyadmin_url'); ?>
<div class="fwhead dbhead"><h2>☁️ Welcome, <?=Helpers::e($u['username'])?></h2><span class="badge"><?=Helpers::e($u['role'])?></span>
<div class="dbmeta"><?php if(!empty($server_ip)):?><span title="Server IP — point your domains' A records here">🖧 <code><?=Helpers::e($server_ip)?></code></span><?php endif;?><?php if(!empty($server['os'])):?><span>🐧 <?=Helpers::e($server['os'])?></span><?php endif;?><?php if(!empty($panelVersion)):?><span id="lvVer">v<?=Helpers::e($panelVersion)?></span><?php endif;?>
<?php if(empty($setup) && $isAdmin):?><form method="post" action="/setup-show" class="inline"><input type="hidden" name="csrf" value="<?=$csrf?>"><button class="btn small ghost">📋 Setup checklist</button></form><?php endif;?></div></div>
<?php if(!empty($totpNudge)):?><div class="alert err">🔐 Please enable two-factor authentication — <a href="/2fa">turn on 2FA</a>. This notice stays until 2FA is active on your account.</div><?php endif;?>
<?php if(!empty($osSunset)):?><div class="alert err">🐧 This server runs Ubuntu 22.04: it reaches end-of-life in April 2027, its webmail (Roundcube) package gets no free security updates, and new KarnakPanel installs need Ubuntu 24.04 or Debian 13. Plan a move to a fresh server — <a href="/admin/migrate">Admin → Migrate</a> has the server-transfer steps.</div><?php endif;?>
<?php if(!empty($update) && ($update['sev'] ?? '') !== 'unreachable'):?><div class="alert <?=($update['sev'] ?? '') === 'urgent' ? 'err' : 'ok'?>"><?=($update['sev'] ?? '') === 'urgent' ? '🔴' : '🔵'?> Update available: <b><?=Helpers::e($update['remote'])?></b> (this box: <?=Helpers::e($update['local'])?>)<?=($update['sev'] ?? '') === 'urgent' ? ' — release notes mention SECURITY, update promptly' : ''?> — <a href="/admin/update">review &amp; install</a></div><?php endif;?>
<?php if(!empty($quota) && empty($quota['unlimited']) && $quota['pct'] >= 100):?><div class="alert err">⚠️ Disk quota exceeded (<?=Helpers::e(Helpers::fmtMb($quota['used_mb']).' / '.Helpers::fmtMb((float)$quota['quota_mb']))?>). Delete files/backups or ask for more quota.</div><?php endif;?>
<?php if(!empty($quota) && empty($quota['unlimited']) && $bwBytes !== null && $quota['bw_quota_gb'] > 0 && $bwBytes > $quota['bw_quota_gb']*1073741824):?><div class="alert err">⚠️ Bandwidth quota exceeded this month. Sites may be throttled — contact your provider.</div><?php endif;?>
<?php if(!empty($attention)):?><div class="card dbattn"><div class="fwcardhead"><h3>⚠️ Needs attention</h3><small class="muted"><?=count($attention)?> item<?=count($attention) === 1 ? '' : 's'?></small></div>
<ul><?php foreach($attention as [$lvl, $txt, $href]):?><li><span class="pill mini <?=$lvl === 'err' ? 'err' : 'warn'?>"><?=$lvl === 'err' ? 'urgent' : 'check'?></span> <a href="<?=Helpers::e($href)?>"><?=Helpers::e($txt)?></a></li><?php endforeach;?></ul></div><?php endif;?>
<?php if(!empty($setup)):?><div class="card"><div class="fwcardhead"><h3>🚀 Setup checklist</h3><small class="muted"><?=($setup['done'] ?? 0)?>/<?=($setup['total'] ?? 0)?> done — ticks itself off</small></div>
<ol style="margin:0;padding-left:20px;font-size:14px">
<?php foreach($setup['steps'] as $s):?><li style="margin:5px 0"><?php if($s['done']):?><span class="pill">done</span><?php else:?><span class="pill warn">to do</span><?php endif;?> <a href="<?=Helpers::e($s['u'])?>"><?=Helpers::e($s['t'])?></a><?php if($s['done']):?> <small style="color:var(--muted)">(<?=Helpers::e($s['auto'])?>)</small><?php endif;?></li><?php endforeach;?>
</ol>
<form method="post" action="/setup-dismiss" style="margin-top:6px"><input type="hidden" name="csrf" value="<?=$csrf?>"><button class="btn small">Hide guide</button></form></div><?php endif;?>

<?php // ── your account at a glance: counts vs package limits, disk, bandwidth
$tile = function (string $href, string $lbl, string $big, string $sub, ?int $pct) { ?>
<a class="fwtile dbtile" href="<?=Helpers::e($href)?>"><small><?=$lbl?></small><b><?=$big?></b><span class="muted dbsub"><?=$sub?></span><?php if($pct !== null):?><span class="abbar<?= $pct >= 90 ? ' err' : ($pct >= 70 ? ' warn' : '') ?>"><i style="width:<?=$pct?>%"></i></span><?php endif;?></a>
<?php }; ?>
<div class="fwtiles dbtiles">
<?php foreach ([['domains','max_domains','🌐 Domains','/domains'],['emails','max_emails','✉️ Mailboxes','/email'],['dbs','max_dbs','🗄️ Databases','/databases'],['ftps','max_ftp','📁 FTP accounts','/ftp']] as [$k,$lk,$lbl,$href]):
  $n = (int)$stats[$k]; $max = $limits ? (int)$limits[$lk] : 0;
  $tile($href, $lbl, (string)$n, $max > 0 ? "of $max allowed" : 'no limit', $max > 0 ? min(100, (int)round($n / $max * 100)) : null);
endforeach;
if (!empty($quota)) {
  $unl = !empty($quota['unlimited']);
  $tile('/disk', '💽 Disk', Helpers::e(Helpers::fmtMb((float)$quota['used_mb'])), $unl ? 'no quota' : 'of '.Helpers::e(Helpers::fmtMb((float)$quota['quota_mb'])), $unl ? null : min(100, (int)$quota['pct']));
  $bwq = (float)($quota['bw_quota_gb'] ?? 0); $bwb = (float)($bwBytes ?? 0);
  $tile('/stats', '📶 Bandwidth', Helpers::e(Helpers::fmtMb($bwb / 1048576)), $bwq > 0 ? 'of '.Helpers::e(rtrim(rtrim(number_format($bwq, 1), '0'), '.')).' GB this month' : 'this month · no limit', $bwq > 0 ? min(100, (int)round($bwb / ($bwq * 1073741824) * 100)) : null);
} ?>
</div>

<div class="card"><div class="fwcardhead"><h3>⚡ Quick actions</h3><?php if($limits):?><small class="muted">📦 Package: <?=Helpers::e($limits['name'])?></small><?php endif;?></div>
<div class="dbacts">
<?php foreach ([
  ['/domains', '➕', 'Add a domain', 'sites, subdomains'],
  ['/files', '🗂️', 'File Manager', 'upload & edit files'],
  ['/email', '✉️', 'Mailboxes', 'create, passwords'],
  [$webmail, '📬', 'Webmail', 'read mail in browser', 1],
  ['/databases', '🗄️', 'Databases', 'create DB & user'],
  [$pma, '🐬', 'phpMyAdmin', 'browse tables', 1],
  ['/ssl', '🔒', 'SSL certificates', 'free, auto-renewing'],
  ['/software', '🧰', 'Install WordPress', '1-click apps'],
  ...(!empty($kb) ? [['/builder', '🎨', 'Karnak Builder', 'drag & drop websites']] : []),
  ['/backups', '💾', 'Backups', 'snapshot & restore'],
  ['/dns', '🧭', 'DNS zones', 'records, MX, TXT'],
  ['/php', '🐘', 'PHP version', implode(' / ', PhpVersions::selectable())],
  ['/cron', '⏱️', 'Cron jobs', 'scheduled tasks'],
] as $a):?><a class="dbact" href="<?=Helpers::e($a[0])?>"<?= !empty($a[4]) ? ' target="_blank" rel="noopener"' : '' ?>><span class="ic"><?=$a[1]?></span><span><b><?=Helpers::e($a[2])?><?= !empty($a[4]) ? ' ↗' : '' ?></b><small><?=Helpers::e($a[3])?></small></span></a><?php endforeach;?>
</div>
<?php if($isRes):?><div class="dbgroup">Managing others</div><div class="dbacts">
<a class="dbact" href="/accounts"><span class="ic">👥</span><span><b>Accounts</b><small>create, suspend, log in as</small></span></a>
<a class="dbact" href="/reseller"><span class="ic">🏪</span><span><b>Reseller</b><small>packages & limits</small></span></a>
<?php if($isAdmin):?>
<a class="dbact" href="/admin/services"><span class="ic">⚙️</span><span><b>Services</b><small>restart, SSL, alerts</small></span></a>
<a class="dbact" href="/admin/firewall"><span class="ic">🧱</span><span><b>Firewall</b><small>bans, rules, unban</small></span></a>
<a class="dbact" href="/admin/abuse"><span class="ic">🔥</span><span><b>Abuse board</b><small>live CPU / RAM per user</small></span></a>
<a class="dbact" href="/admin/mail"><span class="ic">📨</span><span><b>Mail queue</b><small>stuck & frozen mail</small></span></a>
<a class="dbact" href="/admin/logs"><span class="ic">📋</span><span><b>Logs</b><small>panel, web, mail</small></span></a>
<a class="dbact" href="/admin/update"><span class="ic">⬆️</span><span><b>Update</b><small>panel releases</small></span></a>
<?php else:?><a class="dbact" href="/api-tokens"><span class="ic">🔌</span><span><b>API tokens</b><small>billing integrations</small></span></a><?php endif;?>
</div><?php endif;?>
</div>

<?php if(!empty($server)):?><?php $dpct = !empty($disk) && $disk['total'] > 0 ? round($disk['used'] / $disk['total'] * 100, 1) : 0; $l = explode(' ', $server['load']); ?>
<div class="card"><div class="fwcardhead"><h3>🖥️ Server — live</h3><small class="muted"><span id="liveDot" class="pill mini acok" title="refreshes every 5 s while this tab is open">● live · 5s</span> · up <span id="lvUptime"><?=Helpers::e($server['uptime'])?></span></small></div>
<div class="fwtiles dblive">
<div class="fwtile"><small>CPU</small><b id="lvCpu">…</b><span class="muted dbsub"><?=$server['cpus']?> core<?= $server['cpus'] > 1 ? 's' : '' ?></span><svg class="dbspark" id="spCpu" viewBox="0 0 100 24" preserveAspectRatio="none"><polyline points=""/></svg></div>
<div class="fwtile"><small>Load (1 / 5 / 15 min)</small><b id="lvLoad1"><?=Helpers::e($l[0] ?? '?')?></b><span class="muted dbsub" id="lvLoad"><?=Helpers::e($server['load'])?></span><span class="abbar" id="lvLoadBar"><i style="width:<?=min(100, (int)round((float)($l[0] ?? 0) / $server['cpus'] * 100))?>%"></i></span></div>
<div class="fwtile"><small>Memory</small><b id="lvMemPct"><?=$server['mem_pct']?>%</b><span class="muted dbsub" id="lvMemTxt"><?=Helpers::e(Helpers::fmtMb((float)$server['mem_used_mb']).' of '.Helpers::fmtMb((float)$server['mem_total_mb']))?></span><span class="abbar<?= $server['mem_pct'] >= 90 ? ' err' : ($server['mem_pct'] >= 75 ? ' warn' : '') ?>" id="lvMemBar"><i style="width:<?=min(100,$server['mem_pct'])?>%"></i></span></div>
<?php if(!empty($disk)):?><div class="fwtile"><small>Disk /</small><b id="lvDiskPct"><?=$dpct?>%</b><span class="muted dbsub" id="lvDiskTxt"><?=Helpers::e(Helpers::fmtMb(($disk['total'] - $disk['used'])/1048576).' free of '.Helpers::fmtMb($disk['total']/1048576))?></span><span class="abbar<?= $dpct >= 90 ? ' err' : ($dpct >= 80 ? ' warn' : '') ?>" id="lvDiskBar"><i style="width:<?=min(100,$dpct)?>%"></i></span></div><?php endif;?>
<div class="fwtile"><small>Network</small><b class="dbnet"><span title="incoming">↓ <span id="lvRx">…</span></span> <span title="outgoing">↑ <span id="lvTx">…</span></span></b><span class="muted dbsub">per second, all interfaces</span><svg class="dbspark" id="spNet" viewBox="0 0 100 24" preserveAspectRatio="none"><polyline points=""/></svg></div>
</div>
<div class="dbcols">
<div><div class="dbsubhead"><b>Services</b><a class="muted" href="/admin/services">details →</a></div>
<?php if(!empty($services)):?><div class="dbsvcs"><?php foreach($services as $k=>$v): $ok = $v['state'] === 'active'; ?>
<div class="dbsvc<?= $ok ? '' : ' bad' ?>" title="<?=Helpers::e($k.': '.$v['state'].($ok ? ' for '.$v['uptime'] : ''))?>"><i class="dot <?=Helpers::e($v['state'])?>"></i><span class="nm"><?=Helpers::e($k)?></span><?php if(!$ok):?><span class="up"><?=Helpers::e($v['state'])?></span><?php endif;?>
<form method="post" action="/admin/restart"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="service" value="<?=Helpers::e($k)?>"><button class="dbrst" title="Restart <?=Helpers::e($k)?>" aria-label="Restart <?=Helpers::e($k)?>">↻</button></form></div>
<?php endforeach;?></div><?php endif;?></div>
<div><div class="dbsubhead"><b>Top processes</b><a class="muted" href="/admin/abuse">abuse board →</a></div>
<table class="dbprocs"><thead><tr><th>User</th><th>CPU</th><th>RAM</th><th>Command</th></tr></thead><tbody id="lvProcs">
<?php foreach($procs as $p):?><tr><td><?=Helpers::e($p[0])?></td><td><?=Helpers::e($p[2])?>%</td><td><?=Helpers::e($p[3])?>%</td><td><code><?=Helpers::e(mb_substr($p[4],0,40))?></code></td></tr><?php endforeach;?></tbody></table></div>
</div>
<?php if(!empty($hist)):?><?php $n = count($hist); $hmax = max(1.0, (float)max(array_column($hist, 'load1'))); $pts = $mpts = [];
foreach($hist as $i => $h){ $x = $n > 1 ? round($i / ($n - 1) * 100, 2) : 0; $pts[] = $x.','.round(38 - (float)$h['load1'] / $hmax * 36, 2); $mpts[] = $x.','.round(38 - (float)$h['mem_pct'] / 100 * 36, 2); } ?>
<div class="dbsubhead" style="margin-top:14px"><b>Last 24 hours</b><span class="muted"><i class="dbkey"></i>load (peak <?=Helpers::e((string)$hmax)?>) <i class="dbkey mem"></i>memory % · <?=$n?> samples, <?=Helpers::e(substr($hist[0]['ts'], 11, 5))?>–<?=Helpers::e(substr($hist[$n-1]['ts'], 11, 5))?></span></div>
<svg class="dbhist" viewBox="0 0 100 40" preserveAspectRatio="none"><polyline class="mem" points="<?=implode(' ', $mpts)?>"/><polyline points="<?=implode(' ', $pts)?>"/></svg><?php endif;?>
</div>
<?php endif;?>

<?php if(!empty($box)):?><div class="card"><div class="fwcardhead"><h3>🏢 This server's hosting</h3><a class="btn small ghost" href="/accounts">All accounts →</a></div>
<div class="dbcounts">
<a href="/accounts"><b><?=$box['accounts']?></b><small>accounts<?= $box['suspended'] ? ' · <span class="dbred">'.(int)$box['suspended'].' suspended</span>' : '' ?></small></a>
<a href="/domains"><b><?=$box['domains']?></b><small>domains</small></a>
<a href="/ssl"><b><?=$box['domains'] ? (int)round($box['ssl'] / $box['domains'] * 100) : 0?>%</b><small>have SSL (<?=$box['ssl']?>/<?=$box['domains']?>)</small></a>
<a href="/email"><b><?=$box['mail']?></b><small>mailboxes</small></a>
<a href="/databases"><b><?=$box['dbs']?></b><small>databases</small></a>
</div>
<?php if(!empty($busy)):?><div class="dbsubhead" style="margin-top:12px"><b>Heaviest accounts</b><span class="muted">by disk, then bandwidth this month</span></div>
<div class="tablewrap"><table class="dbbusy"><thead><tr><th>Account</th><th>Disk</th><th>Bandwidth</th><th>Domains</th></tr></thead><tbody>
<?php foreach($busy as $b): $q = (int)$b['disk_quota_mb']; $pct = $q > 0 ? min(100, (int)round((float)$b['disk_used_mb'] / $q * 100)) : null; ?>
<tr><td><b><?=Helpers::e($b['username'])?></b><?php if($b['status'] !== 'active'):?> <span class="pill mini err"><?=Helpers::e($b['status'])?></span><?php endif;?></td>
<td class="abcell"><b><?=Helpers::e(Helpers::fmtMb((float)$b['disk_used_mb']))?></b> <span class="muted">/ <?= $q > 0 ? Helpers::e(Helpers::fmtMb((float)$q)) : 'unlimited' ?></span><?php if($pct !== null):?><span class="abbar sm<?= $pct >= 90 ? ' err' : ($pct >= 70 ? ' warn' : '') ?>"><i style="width:<?=$pct?>%"></i></span><?php endif;?></td>
<td><?=Helpers::e(Helpers::fmtMb((float)$b['bw'] / 1048576))?></td><td><?=(int)$b['nd']?></td></tr><?php endforeach;?>
</tbody></table></div><?php endif;?></div><?php endif;?>

<div class="dbcols">
<?php $bwMax = 1; foreach($bw_hist ?? [] as $b) $bwMax = max($bwMax, $b['mb']); ?>
<div class="card"><div class="fwcardhead"><h3>📊 Your bandwidth</h3><small class="muted">last 14 days · <?=Helpers::e(Helpers::fmtMb((float)($bw_month_mb ?? 0)))?> this month</small></div>
<div class="dbbars"><?php foreach($bw_hist ?? [] as $b):?><div title="<?=Helpers::e($b['day'].': '.Helpers::fmtMb((float)$b['mb']))?>"><i style="height:<?=max(3, round($b['mb']/$bwMax*100))?>%"></i><small><?=substr($b['day'], 8)?></small></div><?php endforeach;?></div></div>
<div class="card"><div class="fwcardhead"><h3>🌐 Your domains</h3><a class="muted" href="/domains">all domains →</a></div>
<?php if(empty($recent_domains)):?><p class="muted">No domains yet — <a href="/domains">add your first domain</a>.</p>
<?php else:?><div class="dblist"><?php foreach($recent_domains as $d): $exp = !empty($d['ssl_expires']) ? (int)floor((strtotime($d['ssl_expires']) - time()) / 86400) : null; ?>
<div><span><b><?=Helpers::e($d['domain'])?></b><small class="muted">PHP <?=Helpers::e($d['php_version'])?> · added <?=Helpers::e(substr((string)$d['created_at'], 0, 10))?></small></span>
<?php if(empty($d['ssl_enabled'])):?><a class="pill mini warn" href="/ssl">no SSL</a><?php elseif($exp !== null && $exp < 14):?><span class="pill mini err">🔒 <?= $exp < 0 ? 'expired' : $exp.'d left' ?></span><?php else:?><span class="pill mini acok" title="<?=$exp !== null ? 'renews automatically — '.$exp.' days left' : 'SSL on'?>">🔒 SSL<?= $exp !== null ? ' · '.$exp.'d' : '' ?></span><?php endif;?></div><?php endforeach;?></div><?php endif;?></div>
<div class="card"><div class="fwcardhead"><h3>💾 Your backups</h3><a class="muted" href="/backups">backups →</a></div>
<?php if(empty($recent_backups)):?><p class="muted">No backups yet — <a href="/backups">create one</a>. Daily auto-backups run at 02:00.</p>
<?php else:?><div class="dblist"><?php foreach($recent_backups as $b):?><div><span><b class="dbfile"><?=Helpers::e($b['filename'])?></b><small class="muted"><?=Helpers::e($b['created_at'])?></small></span><span class="muted"><?=Helpers::e(Helpers::fmtMb((float)$b['size_bytes']/1048576))?></span></div><?php endforeach;?></div><?php endif;?></div>
</div>

<?php if(!empty($server)):?><script>
(function(){
  var $=function(i){return document.getElementById(i);},dot=$('liveDot'),prev=null,cpuH=[],netH=[];
  function fmtMB(mb){return mb>=1024?(mb/1024).toFixed(1)+' GB':Math.round(mb)+' MB';}
  function rate(b){return b>=1048576?(b/1048576).toFixed(1)+' MB/s':b>=1024?Math.round(b/1024)+' KB/s':Math.round(b)+' B/s';}
  function bar(id,p,w,e){var el=$(id);if(!el)return;el.className='abbar'+(p>=e?' err':p>=w?' warn':'');el.firstChild.style.width=Math.min(100,p)+'%';}
  function spark(id,a){var el=$(id);if(!el)return;var m=Math.max.apply(null,a.concat([1e-9]));
    el.firstChild.setAttribute('points',a.map(function(v,i){return (a.length>1?i/(a.length-1)*100:0).toFixed(1)+','+(23-v/m*22).toFixed(1);}).join(' '));}
  async function tick(){if(document.hidden)return;
    try{var r=await fetch('/live',{credentials:'same-origin'});if(!r.ok||r.redirected)throw 0;var d=await r.json();
      $('lvUptime').textContent=d.uptime;
      var l=String(d.load).split(' ');$('lvLoad1').textContent=l[0];$('lvLoad').textContent=d.load;bar('lvLoadBar',parseFloat(l[0])/d.cpus*100,70,100);
      var vv=$('lvVer');if(vv&&d.version)vv.textContent='v'+d.version;
      $('lvMemPct').textContent=d.mem_pct+'%';$('lvMemTxt').textContent=fmtMB(d.mem_used)+' of '+fmtMB(d.mem_total);bar('lvMemBar',d.mem_pct,75,90);
      if($('lvDiskPct')&&d.disk_total>0){$('lvDiskPct').textContent=d.disk_pct+'%';$('lvDiskTxt').textContent=fmtMB((d.disk_total-d.disk_used)/1048576)+' free of '+fmtMB(d.disk_total/1048576);bar('lvDiskBar',d.disk_pct,80,90);}
      // CPU % and network rates come from the difference between two samples
      if(prev&&d.cpu&&prev.cpu&&d.at>prev.at){var dt=d.at-prev.at,tot=d.cpu[0]-prev.cpu[0],idle=d.cpu[1]-prev.cpu[1];
        if(tot>0){var c=Math.max(0,Math.min(100,Math.round((1-idle/tot)*100)));$('lvCpu').textContent=c+'%';cpuH.push(c);if(cpuH.length>60)cpuH.shift();spark('spCpu',cpuH);}
        if(d.net&&prev.net){var rx=Math.max(0,(d.net[0]-prev.net[0])/dt),tx=Math.max(0,(d.net[1]-prev.net[1])/dt);$('lvRx').textContent=rate(rx);$('lvTx').textContent=rate(tx);netH.push(rx+tx);if(netH.length>60)netH.shift();spark('spNet',netH);}}
      if(d.cpu&&(!prev||d.at>prev.at))prev=d;
      var tb=$('lvProcs');if(tb&&d.procs){tb.textContent='';d.procs.forEach(function(p){var tr=document.createElement('tr');[p[0],p[2]+'%',p[3]+'%',String(p[4]).slice(0,40)].forEach(function(c,i){var td=document.createElement('td');if(i===3){var code=document.createElement('code');code.textContent=c;td.appendChild(code);}else td.textContent=c;tr.appendChild(td);});tb.appendChild(tr);});}
      dot.textContent='● live · 5s';dot.className='pill mini acok';
    }catch(e){dot.textContent='● paused — reload page';dot.className='pill mini err';}}
  tick();setTimeout(tick,3200);setInterval(tick,5000);
  document.addEventListener('visibilitychange',function(){if(!document.hidden)tick();});
})();
</script><?php endif;?>

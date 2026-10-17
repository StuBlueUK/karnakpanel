<h2>☁️ Welcome, <?=Helpers::e($u['username'])?> <span class="badge"><?=Helpers::e($u['role'])?></span></h2>
<?php if(empty($setup) && ($u['role'] ?? '') === 'admin'):?><div style="margin-bottom:14px"><form method="post" action="/setup-show" class="inline"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><button class="btn small">📋 Show setup checklist</button></form></div><?php endif;?>
<?php if(!empty($totpNudge)):?><div class="alert err">🔐 Please enable two-factor authentication — <a href="/2fa">turn on 2FA</a>. This notice stays until 2FA is active on your account.</div><?php endif;?>
<?php if(!empty($osSunset)):?><div class="alert err">🐧 This server runs Ubuntu 22.04: it reaches end-of-life in April 2027, its webmail (Roundcube) package gets no free security updates, and new KarnakPanel installs need Ubuntu 24.04 or Debian 13. Plan a move to a fresh server — <a href="/admin/migrate">Admin → Migrate</a> has the server-transfer steps.</div><?php endif;?>
<?php if(!empty($update) && ($update['sev'] ?? '') !== 'unreachable'):?><div class="alert <?=($update['sev'] ?? '') === 'urgent' ? 'err' : 'ok'?>"><?=($update['sev'] ?? '') === 'urgent' ? '🔴' : '🔵'?> Update available: <b><?=Helpers::e($update['remote'])?></b> (this box: <?=Helpers::e($update['local'])?>)<?=($update['sev'] ?? '') === 'urgent' ? ' — release notes mention SECURITY, update promptly' : ''?> — <a href="/admin/update">review &amp; install</a></div><?php endif;?>
<?php if(!empty($setup)):?><div class="card"><h3>🚀 Setup checklist <small style="color:var(--muted)"><?=($setup['done'] ?? 0)?>/<?=($setup['total'] ?? 0)?> done — ticks itself off</small></h3>
<ol style="margin:0;padding-left:20px;font-size:14px">
<?php foreach($setup['steps'] as $s):?><li style="margin:5px 0"><?php if($s['done']):?><span class="pill">done</span><?php else:?><span class="pill warn">to do</span><?php endif;?> <a href="<?=Helpers::e($s['u'])?>"><?=Helpers::e($s['t'])?></a><?php if($s['done']):?> <small style="color:var(--muted)">(<?=Helpers::e($s['auto'])?>)</small><?php endif;?></li><?php endforeach;?>
</ol>
<form method="post" action="/setup-dismiss" style="margin-top:6px"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><button class="btn small">Hide guide</button></form></div><?php endif;?>
<?php if(!empty($quota) && empty($quota['unlimited']) && $quota['pct'] >= 100):?><div class="alert err">⚠️ Disk quota exceeded (<?=Helpers::e(Helpers::fmtMb($quota['used_mb']).' / '.Helpers::fmtMb((float)$quota['quota_mb']))?>). Delete files/backups or ask for more quota.</div><?php endif;?>
<?php if(!empty($quota) && empty($quota['unlimited']) && $bwBytes !== null && $quota['bw_quota_gb'] > 0 && $bwBytes > $quota['bw_quota_gb']*1073741824):?><div class="alert err">⚠️ Bandwidth quota exceeded this month. Sites may be throttled — contact your provider.</div><?php endif;?>
<p style="color:var(--muted)">KarnakPanel hosting panel · OpenLiteSpeed stack<?php if(!empty($server_ip)):?> · 🖧 Server IP: <code><?=Helpers::e($server_ip)?></code><?php endif;?><?php if(!empty($panelVersion)):?> · <span id="lvVer">v<?=Helpers::e($panelVersion)?></span><?php endif;?></p>
<div class="card" style="display:flex;gap:18px">
<div><b><?=$stats['domains']?><?= $limits ? '/'.$limits['max_domains'] : '' ?></b> Domains</div><div><b><?=$stats['emails']?><?= $limits ? '/'.$limits['max_emails'] : '' ?></b> Emails</div>
<div><b><?=$stats['ftps']?><?= $limits ? '/'.$limits['max_ftp'] : '' ?></b> FTPS</div><div><b><?=$stats['dbs']?><?= $limits ? '/'.$limits['max_dbs'] : '' ?></b> Databases</div>
<?php if($limits):?><div style="margin-left:auto;color:var(--muted)">📦 <?=Helpers::e($limits['name'])?></div><?php endif;?>
</div>
<div class="grid">
<a class="tool" href="/domains"><div class="ic">🌐</div><h3>Domains</h3><p>Addons, subdomains, vhosts</p></a>
<a class="tool" href="/email"><div class="ic">✉️</div><h3>Email</h3><p>Exim4 + Dovecot + SpamAssassin</p></a>
<a class="tool" href="/ftp"><div class="ic">📁</div><h3>FTPS</h3><p>Secure FTP accounts</p></a>
<a class="tool" href="/databases"><div class="ic">🗄️</div><h3>Databases</h3><p>MariaDB wizard</p></a>
<a class="tool" href="/dns"><div class="ic">🧭</div><h3>DNS</h3><p>BIND9 zones</p></a>
<a class="tool" href="/cron"><div class="ic">⏱️</div><h3>Cron</h3><p>Scheduled tasks</p></a>
<a class="tool" href="/files"><div class="ic">🗂️</div><h3>File Manager</h3><p>home directory browser</p></a>
<a class="tool" href="/php"><div class="ic">🐘</div><h3>PHP</h3><p><?=Helpers::e(implode(' / ', PhpVersions::selectable()))?> FPM</p></a>
<a class="tool" href="/domains"><div class="ic">🔒</div><h3>SSL</h3><p>Issue per domain</p></a>
<a class="tool" href="/backups"><div class="ic">💾</div><h3>Backups</h3><p>tar.gz snapshots</p></a>
<a class="tool" href="/software"><div class="ic">🧰</div><h3>Software</h3><p>WordPress 1-click</p></a>
<?php if($u['role']!=='user'):?><a class="tool" href="/accounts"><div class="ic">👥</div><h3>Accounts</h3><p>Create / suspend users</p></a>
<a class="tool" href="/reseller"><div class="ic">🏪</div><h3>Reseller</h3><p>Packages & limits</p></a>
<a class="tool" href="/api-tokens"><div class="ic">🔌</div><h3>API</h3><p>Tokens for integrations</p></a><?php endif;?>
<?php if($u['role']==='admin'):?><a class="tool" href="/admin/services"><div class="ic">⚙️</div><h3>Services</h3><p>Fail2ban · SSH · status</p></a><?php endif;?>
</div>
<?php if(!empty($server)):?><?php $dpct = !empty($disk) && $disk['total'] > 0 ? round($disk['used'] / $disk['total'] * 100, 1) : 0; ?>
<div class="card"><h3>🖥️ Server <small style="color:var(--muted)">admin · <span id="liveDot" title="auto-refreshes every 15s">🟢 live · 15s</span></small></h3>
<table class="svctable"><tr><th>OS</th><td><?=Helpers::e($server['os'])?></td></tr>
<tr><th>Uptime</th><td id="lvUptime"><?=Helpers::e($server['uptime'])?></td></tr>
<tr><th>Load (1/5/15m)</th><td><code id="lvLoad"><?=Helpers::e($server['load'])?></code> on <?=$server['cpus']?> CPU<?= $server['cpus']>1?'s':'' ?></td></tr>
<tr><th>Memory</th><td><span id="lvMemTxt"><?=Helpers::e(Helpers::fmtMb((float)$server['mem_used_mb']).' / '.Helpers::fmtMb((float)$server['mem_total_mb']).' ('.$server['mem_pct'].'%)')?></span><div class="bar" style="margin-top:4px;max-width:220px"><i id="lvMemBar" style="width:<?=min(100,$server['mem_pct'])?>%"></i></div></td></tr>
<?php if(!empty($disk)):?><tr><th>Disk</th><td><span id="lvDiskTxt"><?=Helpers::e(Helpers::fmtMb($disk['used']/1048576).' used / '.Helpers::fmtMb($disk['total']/1048576).' total ('.$dpct.'%)')?></span><div class="bar" style="margin-top:4px;max-width:220px"><i id="lvDiskBar" style="width:<?=min(100,$dpct)?>%"></i></div></td></tr><?php endif;?></table></div>
<?php endif;?>
<?php if(!empty($hist)):?><?php $hmax = max(1, max(array_column($hist, 'load1'))); ?>
<div class="card"><h3>📈 Load history <small style="color:var(--muted)">1-min load, last <?=count($hist)?> samples (24h max)</small></h3>
<div style="display:flex;gap:2px;align-items:flex-end;height:70px">
<?php foreach($hist as $h):?><div title="<?=Helpers::e(substr($h['ts'],11,5).' — load '.$h['load1'].', mem '.$h['mem_pct'].'%')?>" style="flex:1;background:linear-gradient(180deg,var(--sky2),var(--sky));border-radius:2px;min-height:2px;height:<?=max(2, round($h['load1']/$hmax*66))?>px"></div><?php endforeach;?>
</div></div><?php endif;?>
<?php if(!empty($procs)):?>
<div class="card"><h3>🔥 Top processes <small style="color:var(--muted)">by CPU</small></h3>
<table><tr><th>User</th><th>PID</th><th>CPU%</th><th>MEM%</th><th>Command</th></tr>
<tbody id="lvProcs">
<?php foreach($procs as $p):?><tr><td><?=Helpers::e($p[0])?></td><td><?=$p[1]?></td><td><?=$p[2]?></td><td><?=$p[3]?></td><td><code><?=Helpers::e(mb_substr($p[4],0,60))?></code></td></tr><?php endforeach;?></tbody></table></div>
<script>
(function(){var dot=document.getElementById('liveDot');
function fmtMB(mb){return mb>=1024?(mb/1024).toFixed(1)+' GB':Math.round(mb)+' MB';}
async function tick(){if(document.hidden)return;try{var r=await fetch('/live',{credentials:'same-origin'});if(!r.ok)throw 0;var d=await r.json();
document.getElementById('lvUptime').textContent=d.uptime;
document.getElementById('lvLoad').textContent=d.load;
var vv=document.getElementById('lvVer');if(vv&&d.version)vv.textContent='v'+d.version;
document.getElementById('lvMemTxt').textContent=fmtMB(d.mem_used)+' / '+fmtMB(d.mem_total)+' ('+d.mem_pct+'%)';
document.getElementById('lvMemBar').style.width=Math.min(100,d.mem_pct)+'%';
var du=document.getElementById('lvDiskTxt'),db=document.getElementById('lvDiskBar');
if(du&&d.disk_total>0){du.textContent=fmtMB(d.disk_used/1048576)+' used / '+fmtMB(d.disk_total/1048576)+' total ('+d.disk_pct+'%)';db.style.width=Math.min(100,d.disk_pct)+'%';}
var tb=document.getElementById('lvProcs');if(tb&&d.procs){tb.innerHTML='';d.procs.forEach(function(p){var tr=document.createElement('tr');p.slice(0,5).forEach(function(c,i){var td=document.createElement('td');if(i===4){var code=document.createElement('code');code.textContent=String(c).slice(0,60);td.appendChild(code);}else td.textContent=c;tr.appendChild(td);});tb.appendChild(tr);});}
dot.textContent='🟢 live · 15s';}catch(e){dot.textContent='🔴 stale · 15s';}}
setInterval(tick,15000);})();
</script>
<?php endif;?>
<?php if(!empty($services)):?><div class="card"><h3>Services <small style="color:var(--muted)">admin</small></h3><?php foreach($services as $k=>$v):?><div class="svc"><span><i class="dot <?=$v['state']?>"></i> <?=$k?></span>
<span style="display:flex;gap:8px;align-items:center"><span class="pill <?=($v['state']==='active')?'':'err'?>" style="min-width:118px;text-align:left;display:inline-block"><?=$v['state']?><?php if($v['state']==='active'):?> · <?=$v['uptime']?><?php endif;?></span>
<form method="post" action="/admin/restart"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><input type="hidden" name="service" value="<?=$k?>"><button class="btn small" title="Restart <?=$k?>">↻</button></form></span></div><?php endforeach;?>
<div style="margin-top:8px"><a class="btn small" href="/admin/services">Details →</a></div>
</div><?php endif;?>
<?php $bwMax = 1; foreach($bw_hist ?? [] as $b) $bwMax = max($bwMax, $b['mb']); ?>
<div class="card"><h3>📊 Bandwidth <small style="color:var(--muted)">last 14 days · <?=Helpers::e((string)($bw_month_mb ?? 0))?> MB this month</small></h3>
<div style="display:flex;gap:6px;align-items:flex-end;height:90px">
<?php foreach($bw_hist ?? [] as $b):?><div title="<?=Helpers::e($b['day'].': '.$b['mb'].' MB')?>" style="flex:1;background:linear-gradient(180deg,var(--sky2),var(--sky));border-radius:4px;min-height:3px;height:<?=max(3, round($b['mb']/$bwMax*84))?>px"></div><?php endforeach;?>
</div>
<div style="display:flex;gap:6px;color:var(--muted);font-size:11px"><?php foreach($bw_hist ?? [] as $b):?><div style="flex:1;text-align:center"><?=substr($b['day'], 8)?></div><?php endforeach;?></div></div>
<div class="card"><h3>Recent domains</h3><?php if(empty($recent_domains)):?><p style="color:var(--muted)">No domains yet — <a href="/domains">add your first domain</a>.</p>
<?php else:?><table><tr><th>Domain</th><th>PHP</th><th>SSL</th><th>Created</th></tr>
<?php foreach($recent_domains as $d):?><tr><td><?=Helpers::e($d['domain'])?></td><td><span class="pill"><?=Helpers::e($d['php_version'])?></span></td><td><?=!empty($d['ssl_enabled'])?'🔒':'—'?></td><td><?=Helpers::e($d['created_at'])?></td></tr><?php endforeach;?></table><?php endif;?></div>
<div class="card"><h3>Recent backups</h3>
<?php if(empty($recent_backups)):?><p style="color:var(--muted)">No backups yet — <a href="/backups">create one</a>. Daily auto-backups run at 02:00 on production.</p>
<?php else:?><table><tr><th>File</th><th>Size</th><th>Date</th></tr>
<?php foreach($recent_backups as $b):?><tr><td><?=Helpers::e($b['filename'])?></td><td><?=Helpers::e(Helpers::fmtMb((float)$b['size_bytes']/1048576))?></td><td><?=Helpers::e($b['created_at'])?></td></tr><?php endforeach;?></table><?php endif;?></div>

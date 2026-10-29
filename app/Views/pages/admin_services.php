<?php $csrf = Helpers::csrf(); $svcs = $services ?? [];
// what each service is for, where it listens, which log it writes
$info = [
  'openlitespeed' => ['Web server', 'every site + this panel · :80 :443', 'litespeed'],
  'exim4'         => ['Outgoing & incoming mail', 'SMTP · :25 :465 :587', 'exim'],
  'dovecot'       => ['Mailboxes', 'IMAP / POP3 · :993 :995', 'mail'],
  'spamassassin'  => ['Spam filter', 'scores incoming mail', null],
  'clamav'        => ['Virus scanner', 'mail + nightly malware scan', 'malware'],
  'bind9'         => ['DNS server', 'answers for hosted zones · :53', 'named'],
  'mariadb'       => ['Databases', 'MySQL-compatible · local :3306', null],
  'vsftpd'        => ['FTP / FTPS', 'file uploads · :21', 'vsftpd'],
  'ssh'           => ['Remote shell', 'SSH / SFTP · :22', null],
  'fail2ban'      => ['Brute-force bans', 'watches logs, bans attackers', 'fail2ban'],
  'cron'          => ['Scheduler', 'backups, renewals, watchdog, user crons', null],
];
$groups = ['🌐 Web & PHP' => ['openlitespeed'], '✉️ Mail' => ['exim4', 'dovecot', 'spamassassin', 'clamav'], '🧭 DNS & databases' => ['bind9', 'mariadb'], '📁 Files & access' => ['vsftpd', 'ssh'], '🛡 Security & system' => ['fail2ban', 'cron']];
foreach (array_keys($svcs) as $k) if (str_starts_with($k, 'php-fpm ')) { $groups['🌐 Web & PHP'][] = $k; $info[$k] = ['PHP '.substr($k, 8).' · FPM pools for sites', '', null]; }
// restarts that blink something people notice
$warn = ['openlitespeed' => 'Every site and this panel drop for a second or two. The page may show an error once — reload it.',
  'mariadb' => 'Sites that use a database error for a few seconds while it restarts.',
  'exim4' => 'Mail being sent right now is retried, nothing is lost.',
  'ssh' => 'Open SSH sessions stay connected; new logins wait a moment.'];
$down = array_keys(array_filter($svcs, fn($v) => $v['state'] !== 'active'));
$wa = $watchdogAge ?? null; $wdOk = $wa !== null && $wa <= 600;
$mb = fn($b) => $b === null ? null : ($b >= 1073741824 ? round($b / 1073741824, 1).' GB' : round($b / 1048576).' MB'); ?>
<div class="fwhead"><h2>⚙️ Services</h2><small class="muted">the daemons this server runs — status refreshes every 10 s</small>
<span class="svlivetag muted" id="svLive"></span><a class="btn small ghost" href="/admin/abuse" style="margin-left:auto">🔥 Abuse board</a><a class="btn small ghost" href="/admin/firewall">🛡 Firewall</a><a class="btn small ghost" href="/admin/logs">📜 Logs</a></div>

<div class="fwtiles">
<div class="fwtile<?= $down ? ' alert' : '' ?>"><small>Running</small><b id="svCount"><?=count($svcs) - count($down)?><span class="muted" style="font-size:16px"> / <?=count($svcs)?></span></b><span class="dbsub muted" id="svDownTxt"><?= $down ? Helpers::e(implode(', ', $down)).' down' : 'all services up' ?></span></div>
<div class="fwtile"><small>Server uptime</small><b style="font-size:22px"><?=Helpers::e($uptime ?? '?')?></b><span class="dbsub muted">since the last reboot</span></div>
<div class="fwtile<?= $wdOk ? '' : ' alert' ?>"><small>Watchdog</small><b style="font-size:22px"><?= $wa === null ? 'never ran' : ($wdOk ? '✓ ticking' : 'stale') ?></b><span class="dbsub muted"><?= $wa === null ? 'no heartbeat yet' : 'last run '.($wa < 60 ? $wa.' s' : (int)floor($wa / 60).' min').' ago' ?></span></div>
<div class="fwtile<?= ($alertEmail ?? '') === '' ? ' svwarn' : '' ?>"><small>Downtime alerts</small><b style="font-size:22px"><?= ($alertEmail ?? '') !== '' ? '✓ on' : 'default' ?></b><span class="dbsub muted"><?= ($heartbeat ?? '') !== '' ? 'email + external heartbeat' : (($alertEmail ?? '') !== '' ? 'email · no heartbeat' : 'admin emails · no heartbeat') ?></span></div>
</div>

<?php if(!$wdOk):?><div class="alert err">⚠️ Watchdog <?= $wa === null ? 'has never run (no heartbeat yet)' : 'stale — last tick '.(int)floor($wa/60).' min ago' ?>. If cron is dead nothing restarts and nothing alerts — check cron/Local scheduler, or set an external heartbeat below so the outside world pages you.</div><?php endif;?>

<div class="card">
<div class="fwcardhead"><h3>🖥 Services</h3>
<?php if($down):?><form method="post" action="/admin/restart" onsubmit="return confirm('Restart every service that is down (<?=Helpers::e(implode(', ', $down))?>)?')"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="service" value="*down"><button class="btn small">↻ Restart everything that's down</button></form>
<?php else:?><small class="muted">The watchdog restarts a crashed service on its own within 5 minutes and mails you.</small><?php endif;?></div>
<?php if(empty($svcs)):?><p class="muted">systemd isn't available here, so service status can't be read.</p><?php endif;?>
<?php foreach($groups as $g => $keys): $keys = array_values(array_filter($keys, fn($k) => isset($svcs[$k]))); if(!$keys) continue; ?>
<div class="svgroup"><h4><?=$g?></h4><div class="svgrid">
<?php foreach($keys as $k): $v = $svcs[$k]; $on = $v['state'] === 'active'; [$title, $desc, $log] = $info[$k] ?? [$k, '', null]; $e = Helpers::e($k); ?>
<div class="svtile<?= $on ? '' : ' bad' ?>" data-svc="<?=$e?>">
<div class="svtop"><i class="dot <?= $on ? 'active' : 'down' ?>"></i><div class="svname"><b><?=$e?></b><small class="muted"><?=Helpers::e($title)?></small></div>
<span class="pill mini <?= $on ? 'acok' : 'err' ?> svstate"><?= $on ? 'running' : Helpers::e($v['sub'] ?: 'down') ?></span></div>
<?php if($desc !== ''):?><div class="svdesc muted"><?=Helpers::e($desc)?></div><?php endif;?>
<div class="svmeta muted"><span class="svup" title="<?= $v['since'] ? 'running since '.date('Y-m-d H:i', $v['since']) : '' ?>"><?= $on ? '⏱ up '.Helpers::e($v['uptime']) : '⏹ not running' ?></span><span class="svmem"><?= $on && $v['mem'] !== null ? '🧠 '.$mb($v['mem']) : '' ?></span><span class="svtasks"><?= $on && $v['tasks'] !== null ? '⚙ '.(int)$v['tasks'].' task'.((int)$v['tasks'] === 1 ? '' : 's') : '' ?></span><span class="svrst<?= $v['restarts'] > 0 ? '' : ' hide' ?>" title="times systemd restarted it after a crash since boot">↻ <?=(int)$v['restarts']?> auto-restart<?= $v['restarts'] == 1 ? '' : 's' ?></span></div>
<div class="svacts">
<?php if($on):?>
<form method="post" action="/admin/restart"<?= isset($warn[$k]) ? ' onsubmit="return confirm('.Helpers::e(json_encode("Restart $k?\n\n".$warn[$k])).')"' : '' ?>><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="service" value="<?=$e?>"><button class="btn small" name="action" value="restart">↻ Restart</button></form>
<?php if($v['reload']):?><form method="post" action="/admin/restart"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="service" value="<?=$e?>"><button class="btn small ghost" name="action" value="reload" title="Re-read config without dropping connections">⟳ Reload</button></form><?php endif;?>
<?php if(in_array($k, AdminController::STOPPABLE, true)):?><form method="post" action="/admin/restart" onsubmit="return confirm(<?=Helpers::e(json_encode("Stop $k? It stays off until you start it (or the watchdog sees it down and restarts it)."))?>)"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="service" value="<?=$e?>"><button class="btn small ghost acwarn" name="action" value="stop">⏹ Stop</button></form><?php endif;?>
<?php else:?>
<form method="post" action="/admin/restart"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="service" value="<?=$e?>"><button class="btn small" name="action" value="start">▶ Start</button></form>
<?php endif;?>
<?php if($k === 'fail2ban'):?><a class="btn small ghost" href="/admin/firewall">Bans →</a><?php endif;?>
<?php if($log):?><a class="btn small ghost svlog" href="/admin/logs?log=<?=$log?>" title="Recent <?=$e?> log lines">📜 Log</a><?php endif;?>
</div></div>
<?php endforeach;?></div></div>
<?php endforeach;?>
</div>

<div class="svcols">
<div class="card"><div class="fwcardhead"><h3>🔔 Monitoring & alerts</h3></div>
<div class="svset"><div><b>Downtime alert email</b><small class="muted">the watchdog checks every 5 min — one DOWN mail per outage, one RECOVERED mail, plus a bell notification. Also receives root's system mail.</small></div>
<form class="svform" method="post" action="/admin/alert-email"><input type="hidden" name="csrf" value="<?=$csrf?>">
<input name="alert_email" type="email" placeholder="you@example.com" value="<?=Helpers::e($alertEmail ?? '')?>"><button class="btn small">Save</button></form>
<small class="muted">Now: <b><?= ($alertEmail ?? '') !== '' ? Helpers::e($alertEmail) : 'admin account emails (admin@localhost is skipped — it goes nowhere)' ?></b> · save empty to clear.</small></div>
<div class="svset"><div><b>💓 External heartbeat</b><small class="muted">dead-man ping every 5 min — if cron or the whole box dies, only an outside monitor can tell you.</small></div>
<form class="svform" method="post" action="/admin/watchdog-heartbeat"><input type="hidden" name="csrf" value="<?=$csrf?>">
<input name="url" placeholder="https://hc-ping.com/your-uuid" value="<?=Helpers::e($heartbeat ?? '')?>"><button class="btn small">Save</button></form>
<small class="muted">Now: <b><?= ($heartbeat ?? '') !== '' ? Helpers::e($heartbeat) : 'off' ?></b> · healthchecks.io, Uptime Kuma, or any URL that pages when pings stop for 10 min. Save empty to turn off.</small></div>
<div class="svset"><div><b>🦠 Nightly malware scan</b><small class="muted">sweeps every docroot with ClamAV — hits are quarantined and the owner is mailed.</small></div>
<form class="svform" method="post" action="/admin/malware"><input type="hidden" name="csrf" value="<?=$csrf?>">
<label class="svcheck"><input type="checkbox" name="malware_on" value="1" <?= ($malwareOn ?? '1') === '1' ? 'checked' : '' ?>> enabled at</label>
<select name="malware_hour"><?php for($h=0;$h<24;$h++):?><option value="<?=$h?>" <?= ((int)($malwareHour ?? 3)) === $h ? 'selected' : '' ?>><?=sprintf('%02d:30',$h)?></option><?php endfor;?></select>
<button class="btn small">Save</button></form>
<small class="muted"><?= isset($svcs['clamav']) ? 'ClamAV is installed.' : 'ClamAV isn\'t installed on this server, so the scan is skipped.' ?> Last results in <a href="/admin/logs?log=malware">Logs → malware</a>.</small></div>
</div>

<div class="card"><div class="fwcardhead"><h3>🏠 Panel identity</h3></div>
<div class="svset"><div><b>Panel host</b><small class="muted">this server's own domain — the setup-checklist glue step and the panel nameservers need it.</small></div>
<form class="svform" method="post" action="/admin/panel-domain"><input type="hidden" name="csrf" value="<?=$csrf?>">
<input name="panel_domain" placeholder="panel.example.com" value="<?=Helpers::e($panelDomain ?? '')?>"><button class="btn small">Save</button></form>
<small class="muted">Now: <b><?= ($panelDomain ?? '') !== '' ? Helpers::e($panelDomain) : 'unset — step 6 of the checklist stays TODO' ?></b> · save empty to clear.</small></div>
<div class="svset"><div><b>🔒 Panel services certificate</b><small class="muted">which domain's certificate :8090 (panel), :8091 (webmail) and :8092 (phpMyAdmin) serve.</small></div>
<?php if(empty($ssldoms)):?><small class="muted">No SSL-enabled domains yet — issue one on <a href="/domains">Domains</a> first.</small>
<?php else:?><form class="svform" method="post" action="/ssl/services" onsubmit="return confirm('Point panel services at this domain’s cert? Listeners rewrite immediately.')"><input type="hidden" name="csrf" value="<?=$csrf?>">
<select name="id"><?php foreach($ssldoms as $sd):?><option value="<?=$sd['id']?>"<?= ($panelDomain ?? '') === $sd['domain'] ? ' selected' : '' ?>><?=Helpers::e($sd['domain'])?></option><?php endforeach;?></select>
<button class="btn small">Apply cert</button></form><small class="muted">Only domains with SSL are listed.</small><?php endif;?></div>
</div>
</div>
<script>
(function(){
  // live status: dots, state, uptime, memory, tasks every 10 s while visible
  var tag=document.getElementById('svLive'),busy=false,t;
  function mb(b){return b==null?'':'🧠 '+(b>=1073741824?(b/1073741824).toFixed(1)+' GB':Math.round(b/1048576)+' MB');}
  function poll(){if(busy||document.hidden)return;busy=true;
    fetch('/admin/services-live',{credentials:'same-origin',headers:{'Accept':'application/json'}}).then(function(r){if(r.redirected||!r.ok)throw 0;return r.json();}).then(function(d){
      var s=d.services||{},n=0,down=[];
      Object.keys(s).forEach(function(k){var v=s[k],on=v.state==='active';if(on)n++;else down.push(k);
        var el=document.querySelector('.svtile[data-svc="'+CSS.escape(k)+'"]');if(!el)return;
        var was=!el.classList.contains('bad');el.classList.toggle('bad',!on);
        el.querySelector('.dot').className='dot '+(on?'active':'down');
        var p=el.querySelector('.svstate');p.className='pill mini svstate '+(on?'acok':'err');p.textContent=on?'running':(v.sub||'down');
        el.querySelector('.svup').textContent=on?'⏱ up '+v.uptime:'⏹ not running';
        el.querySelector('.svmem').textContent=on?mb(v.mem):'';
        el.querySelector('.svtasks').textContent=on&&v.tasks!=null?'⚙ '+v.tasks+' task'+(v.tasks==1?'':'s'):'';
        var r=el.querySelector('.svrst');r.classList.toggle('hide',!(v.restarts>0));r.textContent='↻ '+v.restarts+' auto-restart'+(v.restarts==1?'':'s');
        if(was!==on)tag.dataset.stale='1';});
      var c=document.getElementById('svCount');c.firstChild.nodeValue=n;
      document.getElementById('svDownTxt').textContent=down.length?down.join(', ')+' down':'all services up';
      c.closest('.fwtile').classList.toggle('alert',down.length>0);
      tag.textContent=tag.dataset.stale?'● state changed — reload for actions':'● live';tag.className='svlivetag '+(tag.dataset.stale?'warn':'ok');
    }).catch(function(){tag.textContent='paused';tag.className='svlivetag err';}).then(function(){busy=false;});}
  poll();t=setInterval(poll,10000);document.addEventListener('visibilitychange',function(){if(!document.hidden)poll();});
})();
</script>

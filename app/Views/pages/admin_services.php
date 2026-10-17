<h2>⚙️ Services <small style="color:var(--muted)">uptime <?=Helpers::e($uptime ?? '?')?></small> <a class="btn small" href="/admin/abuse">🔥 Abuse board</a></h2>
<?php $wa = $watchdogAge ?? null; if($wa === null || $wa > 600):?><div class="alert err">⚠️ Watchdog <?= $wa === null ? 'has never run (no heartbeat yet)' : 'stale — last tick '.(int)floor($wa/60).' min ago' ?>. If cron is dead nothing restarts and nothing alerts — check cron/Local scheduler, or set an external heartbeat below so the outside world pages you.</div>
<?php else:?><div class="card"><small style="color:var(--muted)">🐶 Watchdog ticking (last run <?= $wa < 60 ? $wa.'s' : (int)floor($wa/60).' min' ?> ago).</small></div><?php endif;?>
<div class="card"><h3>🔔 Downtime alerts <small style="color:var(--muted)">watchdog checks every 5 min — one DOWN mail per outage + one RECOVERED mail, plus a bell notification</small></h3>
<form class="inline" method="post" action="/admin/alert-email">
<input type="hidden" name="csrf" value="<?=Helpers::csrf()?>">
<input name="alert_email" type="email" placeholder="you@example.com (blank = admin account emails)" value="<?=Helpers::e($alertEmail ?? '')?>" style="min-width:260px">
<button class="btn">Set alert email</button></form>
<small style="color:var(--muted)">Current: <b><?= ($alertEmail ?? '') !== '' ? Helpers::e($alertEmail) : 'admin account emails (admin@localhost is skipped — it goes nowhere)' ?></b> · Submit empty to clear.</small></div>
<div class="card"><h3>🏠 Panel host <small style="color:var(--muted)">this box's own domain — the setup-checklist glue step needs it</small></h3>
<form class="inline" method="post" action="/admin/panel-domain">
<input type="hidden" name="csrf" value="<?=Helpers::csrf()?>">
<input name="panel_domain" placeholder="panel.example.com (blank = unset)" value="<?=Helpers::e($panelDomain ?? '')?>" style="min-width:260px">
<button class="btn">Set panel host</button></form>
<small style="color:var(--muted)">Current: <b><?= ($panelDomain ?? '') !== '' ? Helpers::e($panelDomain) : 'unset — step 6 of the checklist stays TODO' ?></b> · Submit empty to clear.</small></div>
<div class="card"><h3>💓 Watchdog heartbeat <small style="color:var(--muted)">dead-man ping — if cron dies, only the outside world can tell you</small></h3>
<form class="inline" method="post" action="/admin/watchdog-heartbeat">
<input type="hidden" name="csrf" value="<?=Helpers::csrf()?>">
<input name="url" placeholder="https://hc-ping.com/your-uuid (blank = off)" value="<?=Helpers::e($heartbeat ?? '')?>" style="min-width:300px">
<button class="btn">Set heartbeat</button></form>
<small style="color:var(--muted)">Current: <b><?= ($heartbeat ?? '') !== '' ? Helpers::e($heartbeat) : 'off' ?></b> · healthchecks.io, Uptime Kuma, or any URL that pages when pings stop for 10 min. Submit empty to clear.</small></div>
<div class="card"><h3>🦠 Malware scan <small style="color:var(--muted)">nightly docroot sweep — hits quarantined, owner mailed</small></h3>
<form class="inline" method="post" action="/admin/malware">
<input type="hidden" name="csrf" value="<?=Helpers::csrf()?>">
<label style="display:inline-flex;align-items:center;gap:6px"><input type="checkbox" name="malware_on" value="1" <?= ($malwareOn ?? '1') === '1' ? 'checked' : '' ?> style="width:auto"> enabled</label>
<select name="malware_hour"><?php for($h=0;$h<24;$h++):?><option value="<?=$h?>" <?= ((int)($malwareHour ?? 3)) === $h ? 'selected' : '' ?>><?=sprintf('%02d:30',$h)?></option><?php endfor;?></select>
<button class="btn">Save schedule</button></form>
<small style="color:var(--muted)">Skipped entirely where ClamAV was never installed. Last results in <a href="/admin/logs?log=malware">Logs → malware</a>.</small></div>
<div class="card"><h3>🔒 Panel services certificate <small style="color:var(--muted)">which domain cert :8090 + webmail + phpMyAdmin serve</small></h3>
<form class="inline" method="post" action="/ssl/services" onsubmit="return confirm('Point panel services at this domain\u2019s cert? Listeners rewrite immediately.')">
<input type="hidden" name="csrf" value="<?=Helpers::csrf()?>">
<select name="id"><?php foreach(($ssldoms ?? []) as $sd):?><option value="<?=$sd['id']?>"><?=Helpers::e($sd['domain'])?></option><?php endforeach;?></select>
<button class="btn">Apply cert</button></form>
<small style="color:var(--muted)"><?php if(empty($ssldoms)):?>No SSL-enabled domains yet — issue one on Domains first.<?php else:?>Only domains with SSL list here.<?php endif;?></small></div>
<div class="card">
<?php foreach($services as $k=>$v):?>
<div class="svc"><span style="display:flex;gap:10px;align-items:center"><i class="dot <?=$v['state']?>"></i> <b style="min-width:130px"><?=$k?></b><span class="pill <?=($v['state']==='active')?'':'err'?>" style="min-width:118px;text-align:left;display:inline-block"><?=$v['state']?><?php if($v['state']==='active'):?> · up <?=$v['uptime']?><?php endif;?></span></span>
<form method="post" action="/admin/restart"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><input type="hidden" name="service" value="<?=$k?>"><button class="btn small">Restart</button></form></div>
<?php endforeach;?></div>
<div class="card"><h3>Fail2ban</h3><pre style="white-space:pre-wrap"><?php foreach($bans as $l) echo Helpers::e($l)."\n"; ?></pre>
<small>Jails: sshd, lsws, exim, dovecot, vsftpd — see installer/configs/fail2ban/jail.local</small></div>

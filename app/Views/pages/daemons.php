<?php
$php = $phps ? end($phps) : PhpVersions::DEFAULT;
$tpl = [
    'Laravel queue worker' => ["/usr/bin/php$php artisan queue:work --sleep=3 --tries=3 --max-time=3600", 'Folder = your Laravel app (where artisan is). Exits every hour on purpose and comes straight back fresh.'],
    'Laravel Horizon' => ["/usr/bin/php$php artisan horizon", 'Folder = your Laravel app. Needs Redis and laravel/horizon installed.'],
    'Symfony Messenger' => ["/usr/bin/php$php bin/console messenger:consume async --time-limit=3600", 'Folder = your Symfony app. Restarts hourly, which keeps memory in check.'],
    'Python bot (Discord / Telegram)' => ['/usr/bin/python3 -u bot.py', 'Folder = where bot.py is. -u writes output straight to the log.'],
    'Python worker in a virtualenv' => ['venv/bin/python -u worker.py', 'Change venv/ to the full path, e.g. /home/you/app/venv/bin/python. The command must start with an absolute path.'],
];
$fmtUp = function (int $since): string {
    if ($since <= 0) return '—';
    $s = max(0, time() - $since);
    if ($s < 3600) return intdiv($s, 60).' min';
    if ($s < 86400) return intdiv($s, 3600).' h '.intdiv($s % 3600, 60).' min';
    return intdiv($s, 86400).' d '.intdiv($s % 86400, 3600).' h';
};
$lifeLbl = fn(int $h) => $h === 0 ? 'never' : ($h < 24 ? "every $h h" : 'every '.intdiv($h, 24).' day'.($h >= 48 ? 's' : ''));
$full = $max !== null && $used >= $max;
?>
<h2>⚙️ Daemons <small style="color:var(--muted)">supervised apps — restarted when they stop, started on boot</small></h2>
<div class="card"><div class="fwcardhead"><h3>New daemon</h3><small class="muted"><?php if($max === null):?><?=(int)$used?> in use · no plan limit<?php else:?><?=(int)$used?> of <?=(int)$max?> used<?php endif;?></small></div>
<?php if($full):?><p class="muted"><?= $max === 0 ? 'Your plan does not include daemons.' : 'Your plan’s daemons are all in use — delete one to add another, or ask your host for a bigger plan.' ?></p>
<?php else:?><form class="inline" method="post" action="/daemons/create">
<input type="hidden" name="csrf" value="<?=Helpers::csrf()?>">
<label style="display:flex;flex-direction:column;gap:2px;font-size:12px;color:var(--muted)">Start from<select id="dmTpl"><option value="">Custom command</option><?php foreach($tpl as $lbl => [$c, $h]):?><option value="<?=Helpers::e($c)?>" data-hint="<?=Helpers::e($h)?>"><?=Helpers::e($lbl)?></option><?php endforeach;?></select></label>
<label style="display:flex;flex-direction:column;gap:2px;font-size:12px;color:var(--muted)">Name<input name="name" placeholder="queue" required pattern="[a-z0-9_-]{1,32}" style="max-width:130px"></label>
<label style="display:flex;flex-direction:column;gap:2px;font-size:12px;color:var(--muted)">Command<input id="dmCmd" name="command" placeholder="/usr/bin/php<?=Helpers::e($php)?> artisan queue:work" required maxlength="500" style="min-width:300px"></label>
<label style="display:flex;flex-direction:column;gap:2px;font-size:12px;color:var(--muted)">Folder in your home<input name="workdir" placeholder="example.com/app (empty = home)" style="max-width:200px"></label>
<label style="display:flex;flex-direction:column;gap:2px;font-size:12px;color:var(--muted)">Scheduled restart<select name="life" title="Restart it on a timer — a fix for apps that slowly use more memory"><?php foreach($lifetimes as $h):?><option value="<?=(int)$h?>"><?=Helpers::e($lifeLbl((int)$h))?></option><?php endforeach;?></select></label>
<button class="btn" style="align-self:flex-end">Create &amp; start</button></form>
<small id="dmHint" class="muted"></small><?php endif;?>
<small style="color:var(--muted);display:block;margin-top:6px">The command runs directly, not through a shell: start with the full path of the program (<code>/usr/bin/php<?=Helpers::e($php)?></code>, <code>/usr/bin/python3</code>), plain words after it (no quotes, <code>|</code>, <code>&gt;</code> or <code>$VARS</code>). Output goes to the daemon’s log.
<?php if($phps):?> PHP on this server: <?=Helpers::e(implode(', ', array_map(fn($v) => "/usr/bin/php$v", $phps)))?>.<?php endif;?></small></div>
<div class="card"><h3>Limits</h3><small class="muted">All your daemons share
<?php if($limits):?><b><?=Helpers::e(rtrim(rtrim(number_format($limits['cpu'], 1), '0'), '.'))?> CPU core(s)</b> and <b><?=Helpers::e(Helpers::fmtMb((float)$limits['mem']))?> of RAM</b><?php else:?>a CPU and RAM budget (set when you create the first one)<?php endif;?>, plus 256 processes. An app that goes over the RAM limit is stopped and started again.
If it keeps failing — <b>5 starts within 5 minutes</b> — it is left stopped and you get a notification: check its log, fix it, then press ▶ Start.
Cron jobs have their own, separate budget.</small></div>
<div class="card"><table><tr><th>Name</th><th>Command</th><th>State</th><th>Up for</th><th>RAM</th><th title="Times systemd started it again after it stopped">Restarts</th><th>Scheduled restart</th><th></th></tr>
<?php foreach($daemons as $d): $st = $d['state']; ?><tr><td><?=Helpers::e($d['name'])?><?= $d['auto']==='enabled' ? ' <small style="color:var(--muted)">auto</small>' : '' ?></td>
<td><code style="word-break:break-all"><?=Helpers::e($d['command'])?></code><br><small class="muted"><?=Helpers::e($d['workdir'])?></small></td>
<td><?php if(!empty($d['looped'])):?><span class="pill err" title="Started 5 times within 5 minutes — stopped until you press Start">crash loop — stopped</span>
<?php elseif($st === 'active'):?><span class="pill ok"><?= $d['sub'] === 'running' ? 'running' : Helpers::e($d['sub']) ?></span>
<?php elseif($st === 'activating'):?><span class="pill warn">restarting</span>
<?php elseif($st === 'failed'):?><span class="pill err">failed</span>
<?php else:?><span class="pill"><?=Helpers::e($st)?></span><?php endif;?></td>
<td><?=Helpers::e($fmtUp((int)$d['since']))?></td>
<td><?= $d['mem'] === null || $st !== 'active' ? '—' : Helpers::e(Helpers::fmtMb($d['mem'] / 1048576)) ?></td>
<td><?=(int)$d['restarts']?></td>
<td><form method="post" action="/daemons/act" style="display:flex;gap:4px"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><input type="hidden" name="id" value="<?=(int)$d['id']?>"><input type="hidden" name="op" value="life">
<select name="life"><?php foreach($lifetimes as $h):?><option value="<?=(int)$h?>"<?= (int)$h === (int)($d['max_hours'] ?? 0) ? ' selected' : '' ?>><?=Helpers::e($lifeLbl((int)$h))?></option><?php endforeach;?></select><button class="btn small" title="Save">✓</button></form></td>
<td style="white-space:nowrap"><a class="btn small" href="/daemons/logs?id=<?=(int)$d['id']?>">Logs</a>
<?php foreach(['restart'=>'↻','stop'=>'⏹','start'=>'▶'] as $op=>$ic):?><form method="post" action="/daemons/act" style="display:inline"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><input type="hidden" name="id" value="<?=(int)$d['id']?>"><input type="hidden" name="op" value="<?=$op?>"><button class="btn small" title="<?=$op?>"><?=$ic?></button></form><?php endforeach;?>
<form method="post" action="/daemons/act" style="display:inline"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><input type="hidden" name="id" value="<?=(int)$d['id']?>"><input type="hidden" name="op" value="<?= $d['auto']==='enabled'?'off':'on' ?>"><button class="btn small" title="toggle autostart"><?= $d['auto']==='enabled'?'🔕':'🔔' ?></button></form>
<form method="post" action="/daemons/delete" style="display:inline" onsubmit="return confirm('Delete daemon <?=Helpers::e($d['name'])?>? Stops it and removes autostart.')"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><input type="hidden" name="id" value="<?=(int)$d['id']?>"><button class="btn small danger">Del</button></form></td></tr><?php endforeach;?>
<?php if(empty($daemons)):?><tr><td colspan="8" style="color:var(--muted)">No supervised apps yet. Typical uses: a Laravel or Symfony queue worker, a Discord/Telegram bot, a websocket or game server, a mail or feed poller. Scheduled one-off tasks belong under Cron jobs instead.</td></tr><?php endif;?></table></div>
<script>
(function(){
  var t=document.getElementById('dmTpl'), c=document.getElementById('dmCmd'), h=document.getElementById('dmHint');
  if(!t) return;
  t.addEventListener('change', function(){
    var o=t.options[t.selectedIndex];
    if(o.value) c.value=o.value;
    h.textContent=o.getAttribute('data-hint')||'';
  });
})();
</script>

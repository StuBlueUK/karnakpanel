<?php $csrf = Helpers::csrf(); $php = 'php'.PhpVersions::DEFAULT;
$presets = ['*/5 * * * *' => 'Every 5 minutes', '*/15 * * * *' => 'Every 15 minutes', '0 * * * *' => 'Every hour', '0 2 * * *' => 'Every night at 02:00', '0 3 * * 0' => 'Every Sunday at 03:00', '0 4 1 * *' => 'Monthly, on the 1st at 04:00'];
$when = function(string $sched) { $h = Helpers::cronHuman($sched); ?><code><?=Helpers::e($sched)?></code><?php if($h !== null):?><br><small style="color:var(--muted)"><?=Helpers::e($h)?></small><?php endif;
}; ?>
<h2>⏱️ Cron Jobs</h2>
<p style="color:var(--muted);margin-top:-6px">Scheduled tasks that run as your account on a timetable. Each run's output is kept, and a <b>watch</b> emails you if a job stops finishing on time. Times are server time (now <?=Helpers::e(date('H:i'))?>).</p>
<div class="card"><h3>➕ Add a job</h3>
<form method="post" action="/cron/create" style="display:grid;gap:8px">
<input type="hidden" name="csrf" value="<?=$csrf?>">
<div class="inline" style="display:flex;flex-wrap:wrap;gap:6px;align-items:center">
<select onchange="if(this.value)this.form.schedule.value=this.value" title="Pick a common schedule, or type your own"><option value="">Common schedules…</option><?php foreach($presets as $v => $l):?><option value="<?=$v?>"><?=$l?></option><?php endforeach;?></select>
<input name="schedule" placeholder="*/5 * * * *" required style="max-width:150px" title="minute hour day-of-month month day-of-week">
<input name="command" placeholder="<?=Helpers::e($php)?> /home/you/example.com/public_html/cron.php" required style="flex:1 1 320px;min-width:0">
<button class="btn">Add job</button></div>
<small style="color:var(--muted)">Schedule = <code>minute hour day month weekday</code> (<code>*</code> = every). Use a pinned <code><?=Helpers::e($php)?></code> (or <?=Helpers::e(implode('/', array_map(fn($v) => 'php'.$v, array_slice(PhpVersions::selectable(), 1))))?>) rather than bare <code>php</code>, which follows the server default and can break the app when that moves.</small>
</form>
<details style="margin-top:8px"><summary style="cursor:pointer;color:var(--muted)">Examples</summary>
<div style="margin-top:6px;display:grid;gap:6px;max-width:680px">
<div><code>*/5 * * * *</code> <code><?=Helpers::e($php)?> /home/you/example.com/public_html/wp-cron.php</code><br><small style="color:var(--muted)">WordPress scheduled tasks every 5 minutes (pair with <code>DISABLE_WP_CRON</code>).</small></div>
<div><code>* * * * *</code> <code><?=Helpers::e($php)?> /home/you/example.com/artisan schedule:run</code><br><small style="color:var(--muted)">Laravel scheduler — it expects to be called every minute.</small></div>
<div><code>0 3 * * 0</code> <code>find /home/you/tmp -type f -mtime +7 -delete</code><br><small style="color:var(--muted)">Sundays at 03:00: clear temp files older than a week.</small></div>
</div></details></div>
<div class="card"><h3>📋 Your jobs <small style="color:var(--muted)"><?=(int)($total ?? count($crons))?> total</small></h3>
<table><tr><th>When</th><th>Command</th><th>Last run</th><th>Watch</th><th></th></tr>
<?php foreach($crons as $c): $l = $c['last'] ?? null; $start = $l ? trim((string)preg_replace('/^=== START | ===$/', '', $l['start'] ?? '')) : '';
$exit = ($l && preg_match('/^=== END exit=(\d+) /', (string)($l['end'] ?? ''), $em)) ? (int)$em[1] : null; ?>
<tr><td style="white-space:nowrap"><?php $when($c['schedule']); ?></td><td style="word-break:break-all"><code><?=Helpers::e($c['command'])?></code></td>
<td><small><?php if($start === ''):?><span style="color:var(--muted)">never</span><?php else:?><?=Helpers::e(str_replace('T', ' ', substr($start, 0, 16)))?><br><?php if($exit === null):?><span class="pill">running…</span><?php elseif($exit === 0):?><span class="pill">✓ ok</span><?php else:?><span class="pill err">failed (exit <?=$exit?>)</span><?php endif;?><?php endif;?></small>
<?php if(!empty($l['out'])):?><details><summary style="cursor:pointer;color:var(--muted);font-size:12px">output</summary><pre style="white-space:pre-wrap;word-break:break-all;font-size:12px;max-width:420px;max-height:220px;overflow:auto;margin:4px 0 0"><?=Helpers::e($l['out'])?></pre></details><?php endif;?></td>
<td><small><?php if(empty($c['expect_min'])):?><span style="color:var(--muted)">not watched</span><?php elseif(($c['watch'] ?? null) === 'overdue'):?><span class="pill err">overdue</span><?php elseif(($c['watch'] ?? null) === 'ok'):?><span class="pill">on time</span><?php else:?><span class="pill">waiting for first run</span><?php endif;?></small>
<details><summary style="cursor:pointer;color:var(--muted);font-size:12px"><?= empty($c['expect_min']) ? 'set a watch' : 'every '.(int)$c['expect_min'].' min +'.(int)($c['grace_min'] ?? 15).' grace' ?></summary>
<form method="post" action="/cron/expect" style="margin-top:4px;display:flex;flex-wrap:wrap;gap:4px;align-items:center"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="id" value="<?=$c['id']?>">
<small>must finish every</small><input name="expect_min" placeholder="min" value="<?=Helpers::e((string)($c['expect_min'] ?? ''))?>" pattern="[0-9]*" style="max-width:64px" title="Minutes (5 or more); blank = stop watching"><small>min, plus</small><input name="grace_min" value="<?=Helpers::e((string)($c['grace_min'] ?? 15))?>" pattern="[0-9]*" style="max-width:48px" title="Grace minutes"><small>grace</small><button class="btn small">Save</button></form></details></td>
<td><form method="post" action="/cron/delete" onsubmit="return confirm('Delete this cron job?')"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="id" value="<?=$c['id']?>"><button class="btn small danger">Delete</button></form></td></tr>
<?php endforeach;?>
<?php if(empty($crons)):?><tr><td colspan="5" style="color:var(--muted)">No cron jobs yet — add one above.</td></tr><?php endif;?></table>
<?php $pagerBase = '/cron?'; $pagerLabel = 'jobs'; require __DIR__.'/_pager.php'; ?></div>
<?php if(!empty($syscrons ?? [])): $groups = []; foreach($syscrons as $s) $groups[$s['group']][] = $s; ?>
<div class="card"><h3>🖥️ System schedule <small style="color:var(--muted)">what the server runs by itself (admin view, read-only — installed and updated with the panel)</small></h3>
<?php foreach(['Monitoring & alerts', 'Backups', 'Security', 'Email', 'Usage & quotas', 'Housekeeping', 'Other'] as $g): if(empty($groups[$g])) continue; ?>
<h4 style="margin:14px 0 4px"><?=Helpers::e($g)?></h4>
<table><tr><th style="width:30%">Job</th><th>What it does</th><th style="width:170px">When</th></tr>
<?php foreach($groups[$g] as $s):?><tr><td><b><?=Helpers::e($s['name'])?></b><br><small class="clip" style="color:var(--muted);max-width:100%" title="<?=Helpers::e($s['command'])?>"><?=Helpers::e(preg_replace('#^/usr/local/skypanel/scripts/|(\s*(>>?\S+|2>&1))+\s*$#', '', $s['command']))?></small></td><td><small><?=Helpers::e($s['about'])?></small></td><td style="white-space:nowrap"><?php $when($s['schedule']); ?></td></tr><?php endforeach;?></table>
<?php endforeach;?></div><?php endif;?>

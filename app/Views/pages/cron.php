<h2>⏱️ Cron Jobs</h2>
<div class="card"><form class="inline" method="post" action="/cron/create">
<input type="hidden" name="csrf" value="<?=Helpers::csrf()?>">
<input name="schedule" placeholder="*/5 * * * *" required style="max-width:160px">
<input name="command" placeholder="/usr/bin/php /home/user/script.php" required>
<small style="color:var(--muted)">Prefer a pinned <code>php<?=Helpers::e(PhpVersions::DEFAULT)?></code> (or <?=Helpers::e(implode('/', array_slice(PhpVersions::selectable(), 1)))?>) over bare <code>php</code> — bare follows the server default and can break the app when it moves.</small>
<button class="btn">Add</button></form>
<details style="margin-top:8px"><summary style="cursor:pointer;color:var(--muted)">Examples — pin the PHP version</summary>
<div style="margin-top:6px;display:grid;gap:6px;max-width:640px">
<div><code>*/5 * * * *</code> <code>php<?=Helpers::e(PhpVersions::DEFAULT)?> /home/user/example.com/public_html/crons/cron.php</code><br><small style="color:var(--muted)">App cron every 5 min — pinned so a server default change can't break it.</small></div>
<div><code>0 2 * * *</code> <code>php<?=Helpers::e(PhpVersions::DEFAULT)?> /home/user/example.com/public_html/artisan schedule:run</code><br><small style="color:var(--muted)">Laravel scheduler nightly. Bare <code>php</code> follows the server default — fine until it moves, then a lottery.</small></div>
</div></details></div>
<div class="card"><table><tr><th>Schedule</th><th>Command</th><th>Last run</th><th>Watch</th><th></th></tr>
<?php foreach($crons as $c):?><tr><td><code><?=Helpers::e($c['schedule'])?></code></td><td><code><?=Helpers::e($c['command'])?></code></td>
<td><small><?php if(!empty($c['last']['start'])):?><?=Helpers::e(preg_replace('/^=== START | ===$/','',$c['last']['start']))?><br><?=Helpers::e(preg_replace('/^=== END /','',$c['last']['end'] ?? ''))?><?php else:?>never<?php endif;?></small></td>
<td style="white-space:nowrap"><small><?php if(empty($c['expect_min'])):?>—<?php elseif(($c['watch'] ?? null)==='overdue'):?>🔴 overdue<?php elseif(($c['watch'] ?? null)==='ok'):?>🟢 ok<?php else:?>⚪ never ran<?php endif;?></small>
<form method="post" action="/cron/expect" style="display:inline"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><input type="hidden" name="id" value="<?=$c['id']?>"><input name="expect_min" placeholder="every N min" value="<?=Helpers::e((string)($c['expect_min'] ?? ''))?>" pattern="[0-9]*" style="max-width:70px" title="Must finish at least this often (blank = unwatched)"><input name="grace_min" value="<?=Helpers::e((string)($c['grace_min'] ?? 15))?>" pattern="[0-9]*" style="max-width:44px" title="Grace minutes"><button class="btn small">Set</button></form></td>
<td><form method="post" action="/cron/delete"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><input type="hidden" name="id" value="<?=$c['id']?>"><button class="btn small danger">Del</button></form></td></tr>
<?php if(!empty($c['last']['out'])):?><tr><td colspan="4"><pre style="white-space:pre-wrap;font-size:12px"><?=Helpers::e($c['last']['out'])?></pre></td></tr><?php endif;?>
<?php endforeach;?>
<?php if(empty($crons)):?><tr><td colspan="4" style="color:var(--muted)">No cron jobs — e.g. <code>*/5 * * * * /usr/bin/php /home/you/script.php</code>.</td></tr><?php endif;?></table>
<?php $pagerBase = '/cron?'; $pagerLabel = 'jobs'; require __DIR__.'/_pager.php'; ?></div>
<?php if(!empty($syscrons ?? [])):?><div class="card"><h3>🖥️ System schedule <small style="color:var(--muted)">box-level jobs (backups, watchdog, monitors) — read-only, installer-owned</small></h3>
<table><tr><th>Schedule</th><th>Command</th></tr>
<?php foreach($syscrons as $s):?><tr><td><code><?=Helpers::e($s['schedule'])?></code></td><td><code><?=Helpers::e($s['command'])?></code></td></tr><?php endforeach;?></table></div><?php endif;?>

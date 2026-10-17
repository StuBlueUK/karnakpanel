<h2>🧹 Disk cleanup <small style="color:var(--muted)">safe targets only</small></h2>
<div class="card"><table><tr><th>Area</th><th>Size</th><th></th></tr>
<?php $targets = ['staging'=>'orphaned upload fragments (>1 day)','sessions'=>'dead PHP sessions (>7 days)','releases'=>'old panel versions (live + newest kept)']; ?>
<?php foreach($targets as $t=>$desc):?><tr><td><b><?=$t?></b><br><small style="color:var(--muted)"><?=$desc?></small></td>
<td><?=Helpers::e($usage[$t] ?? '—')?><?= $t==='sessions' ? '' : ' MB' ?></td>
<td><form method="post" action="/admin/disk-clean" onsubmit="return confirm('Purge <?=$t?>?')"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><input type="hidden" name="target" value="<?=$t?>"><button class="btn small danger">Purge</button></form></td></tr><?php endforeach;?>
<tr><td><b>backups</b><br><small style="color:var(--muted)">pruned automatically (14 days)</small></td><td><?=Helpers::e($usage['backups'] ?? '—')?> MB</td><td></td></tr>
<tr><td><b>weblogs</b><br><small style="color:var(--muted)">rotated by OLS</small></td><td><?=Helpers::e($usage['weblogs'] ?? '—')?> MB</td><td></td></tr>
<tr><td><b>syslog</b><br><small style="color:var(--muted)">system logs (read-only here)</small></td><td><?=Helpers::e($usage['syslog'] ?? '—')?> MB</td><td></td></tr>
</table></div>

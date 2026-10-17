<h2>💿 Disk usage<?php if(!empty($isAdminView)):?> <small style="color:var(--muted)">viewing <?=Helpers::e($targetName ?? '')?></small><?php endif;?></h2>
<div class="card" style="display:flex;gap:18px;flex-wrap:wrap;align-items:center">
<div class="stat-card"><h4>Disk</h4><div class="bar"><i style="width:<?= $quota ? $quota['pct'] : 0 ?>%"></i></div><small><?= $quota ? Helpers::e(Helpers::quotaText($quota)) : '—' ?></small></div>
<div><small style="color:var(--muted)"><?= $measuredAgo ? 'Measured '.$measuredAgo : 'Never measured — press Refresh for the breakdown' ?></small></div>
<div><form method="post" action="/disk/refresh" class="inline"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><?php if(!empty($isAdminView)):?><input type="hidden" name="user" value="<?=Helpers::e($targetName ?? '')?>"><?php endif;?><?php if(($path ?? '') !== ''):?><input type="hidden" name="path" value="<?=Helpers::e($path)?>"><?php endif;?><button class="btn small">🔄 Refresh</button></form></div>
<?php if(!empty($isAdminView)):?><div><form method="get" action="/disk" class="inline"><input name="user" placeholder="username" value="<?=Helpers::e($targetName ?? '')?>"> <button class="btn small">Inspect</button></form></div><?php endif;?>
</div>
<?php
$adminQs = !empty($isAdminView) ? '&user='.urlencode($targetName ?? '') : '';
$adminQ = !empty($isAdminView) ? '?user='.urlencode($targetName ?? '') : '';
?>
<?php if(($path ?? '') !== ''):?>
<?php
$segs = explode('/', $path);
$crumbs = [];
$acc = '';
foreach ($segs as $i => $s) { $acc .= ($i ? '/' : '').$s; $crumbs[] = ['label' => $s, 'path' => $acc]; }
$drows = ($drill['rows'] ?? []);
?>
<div class="card"><a class="btn small" href="/disk<?=$adminQ?>">← All folders</a>
<?php foreach($crumbs as $i => $c):?> / <?php if($i < count($crumbs)-1):?><a href="/disk?path=<?=urlencode($c['path'])?><?=$adminQs?>"><?=Helpers::e($c['label'])?></a><?php else:?><b><?=Helpers::e($c['label'])?></b><?php endif;?><?php endforeach;?>
 <small style="color:var(--muted)">biggest first — click a folder to go deeper</small></div>
<div class="card"><table class="list"><tr><th>Name</th><th>Size</th><th></th></tr>
<?php foreach($drows as $r):?><?php if($r['kind'] === 'total') continue;?><tr><td><small class="clip" title="<?=Helpers::e($r['label'])?>"><?=Helpers::e(basename($r['label']))?></small></td><td><?=Helpers::e(Helpers::fmtMb($r['mb']))?></td>
<td style="white-space:nowrap"><?php if($r['kind'] === 'dir'):?><a class="btn small" href="/disk?path=<?=urlencode($r['label'])?><?=$adminQs?>">Drill down</a><?php else:?>—<?php endif;?></td></tr><?php endforeach;?>
<?php if(empty($drows)):?><tr><td colspan="3" style="color:var(--muted)">Empty folder.</td></tr><?php endif;?></table>
<?php if(!empty($drill['total'])):?><small style="color:var(--muted)">Folder total <?=Helpers::e(Helpers::fmtMb($drill['total']['mb']))?> · <?=number_format($drill['total']['files'])?> file(s)<?=($drill['total']['files'] >= 100001 ? '+' : '')?></small><?php endif;?></div>
<?php elseif(!empty($tree)):?>
<?php
$rows = $tree['rows'] ?? []; $total = $tree['total'] ?? null;
$ov = array_values(array_filter($rows, fn($r) => $r['kind'] === 'domain'));
?>
<div class="card"><table class="list"><tr><th>Folder</th><th>Size</th><th>Mail</th><th></th></tr>
<?php foreach($ov as $r):?><tr><td><small class="clip" title="<?=Helpers::e($r['label'])?>"><?=Helpers::e($r['label'])?></small></td><td><?=Helpers::e(Helpers::fmtMb($r['mb']))?></td>
<td><?=isset($mailByDomain[$r['label']]) ? Helpers::e(Helpers::fmtMb($mailByDomain[$r['label']])) : '<span style="color:var(--muted)">—</span>'?></td>
<td style="white-space:nowrap"><a class="btn small" href="/disk?path=<?=urlencode($r['label'])?><?=$adminQs?>">Drill down</a></td></tr><?php endforeach;?>
<?php if(empty($ov)):?><tr><td colspan="4" style="color:var(--muted)">Nothing measured yet — press Refresh.</td></tr><?php endif;?></table>
<?php if($total):?><small style="color:var(--muted)">Home total <?=Helpers::e(Helpers::fmtMb($total['mb']))?> · <?=number_format($total['files'])?> file(s)<?=($total['files'] >= 100001 ? '+' : '')?></small><?php endif;?>
<?php if(!empty($mailCapped)):?><br><small style="color:var(--muted)">Mailbox list truncated (60+) — mail column partial.</small><?php endif;?></div>
<?php if(!empty($dbSizes)):?><div class="card"><h3>🗄️ Databases</h3><table class="list"><tr><th>Database</th><th>Size</th></tr>
<?php foreach($dbSizes as $n => $mb):?><tr><td><?=Helpers::e((string)$n)?></td><td><?=Helpers::e(Helpers::fmtMb($mb))?></td></tr><?php endforeach;?></table></div><?php endif;?>
<?php else:?>
<div class="card" style="color:var(--muted)">No breakdown yet — press Refresh to measure (takes a few seconds, cached 5 minutes).</div>
<?php endif;?>

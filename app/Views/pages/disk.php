<h2>💿 Disk usage<?php if(!empty($isAdminView)):?> <small style="color:var(--muted)">viewing <?=Helpers::e($targetName ?? '')?></small><?php endif;?></h2>
<?php
$adminQs = !empty($isAdminView) ? '&user='.urlencode($targetName ?? '') : '';
$adminQ = !empty($isAdminView) ? '?user='.urlencode($targetName ?? '') : '';
$rows = $tree['rows'] ?? []; $total = $tree['total'] ?? null;
$of = fn(string $k) => array_values(array_filter($rows, fn($r) => $r['kind'] === $k));
$sites = array_flip($siteNames ?? []);
// what each home folder is, in words (the old page called them all "domains")
$known = ['Maildir' => 'local system mail', '.trash' => 'File Manager trash (emptied after 30 days)', '.tmp' => 'temporary files (your private /tmp)',
  '.php-sessions' => 'PHP sessions', '.karnakbuilder' => 'Site Builder projects', '.cache' => 'app caches', '.local' => 'app data',
  '.config' => 'app settings', '.ssh' => 'SSH keys', '.wp-cli' => 'WP-CLI cache', '.composer' => 'Composer cache', '.npm' => 'npm cache',
  'backup' => 'your own folder — panel backups are counted separately below', 'public_html' => 'legacy web folder', '(more folders)' => 'smaller folders, summed'];
$mailBy = []; foreach ($of('mail') as $m) $mailBy[$m['label']] = $m['mb'];
$siteMb = 0.0; $otherMb = 0.0;
foreach ($of('top') as $r) { if (isset($sites[$r['label']])) $siteMb += $r['mb']; }
$homeMb = $total['mb'] ?? 0.0; $mailMb = array_sum($mailBy); $otherMb = max(0.0, $homeMb - $siteMb);
$usedMb = $homeMb + $mailMb;
$bk = $of('backups')[0] ?? null; $snap = $of('snapshots')[0] ?? null;
$dbMb = array_sum($dbSizes ?? []);
$unl = !empty($quota['unlimited']); $qMb = (float)($quota['quota_mb'] ?? 0);
$scale = (!$unl && $qMb > 0) ? max($qMb, $usedMb) : max($usedMb, 0.01);
$pct = fn(float $mb) => round(min(100, $mb / $scale * 100), 2);
?>
<div class="card" style="display:flex;gap:14px;flex-wrap:wrap;align-items:center">
<div style="flex:1 1 320px;min-width:0">
<div style="display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap"><b>Quota</b>
<small><?php if($tree):?><?=Helpers::e(Helpers::fmtMb($usedMb))?> used<?= $unl ? ' · unlimited' : ' of '.Helpers::e(Helpers::fmtMb($qMb)).' ('.round($usedMb / max($qMb, 0.01) * 100, 1).'%)' ?><?php else:?><?= $quota ? Helpers::e(Helpers::quotaText($quota)) : '—' ?><?php endif;?></small></div>
<div class="bar" style="display:flex;height:12px;margin-top:6px" role="img" aria-label="quota use by type">
<?php if($tree):?><i style="width:<?=$pct($siteMb)?>%;background:var(--sky)" title="Websites"></i><i style="width:<?=$pct($mailMb)?>%;background:#a78bfa" title="Email"></i><i style="width:<?=$pct($otherMb)?>%;background:#f59e0b" title="Other files"></i><?php endif;?>
</div>
<small style="color:var(--muted)"><?= $measuredAgo ? 'Measured '.Helpers::e($measuredAgo) : 'Not measured yet' ?> · the quota counts your home folder and your mail</small>
</div>
<div style="display:flex;gap:8px;flex-wrap:wrap">
<form method="post" action="/disk/refresh" class="inline"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><?php if(!empty($isAdminView)):?><input type="hidden" name="user" value="<?=Helpers::e($targetName ?? '')?>"><?php endif;?><?php if(($path ?? '') !== ''):?><input type="hidden" name="path" value="<?=Helpers::e($path)?>"><?php endif;?><button class="btn small">🔄 Refresh</button></form>
<?php if(!empty($isAdminView)):?><form method="get" action="/disk" class="inline"><input name="user" placeholder="username" value="<?=Helpers::e($targetName ?? '')?>"> <button class="btn small">Inspect</button></form><?php endif;?>
</div></div>

<?php if(($path ?? '') !== ''):?>
<?php
$segs = explode('/', $path);
$crumbs = [];
$acc = '';
foreach ($segs as $i => $s) { $acc .= ($i ? '/' : '').$s; $crumbs[] = ['label' => $s, 'path' => $acc]; }
$drows = ($drill['rows'] ?? []);
$dtot = max((float)($drill['total']['mb'] ?? 0), 0.01);
?>
<div class="card"><a class="btn small" href="/disk<?=$adminQ?>">← Overview</a>
<?php foreach($crumbs as $i => $c):?> / <?php if($i < count($crumbs)-1):?><a href="/disk?path=<?=urlencode($c['path'])?><?=$adminQs?>"><?=Helpers::e($c['label'])?></a><?php else:?><b><?=Helpers::e($c['label'])?></b><?php endif;?><?php endforeach;?>
 <small style="color:var(--muted)">biggest first (top 30) — click a folder to go deeper</small></div>
<div class="card"><table class="list"><tr><th>Name</th><th>Size</th><th>Share</th><th></th></tr>
<?php foreach($drows as $r):?><tr><td><?= $r['kind'] === 'dir' ? '📁' : '📄' ?> <small class="clip" title="<?=Helpers::e($r['label'])?>"><?=Helpers::e(basename($r['label']))?></small></td><td><?=Helpers::e(Helpers::fmtMb($r['mb']))?></td>
<td><div class="bar mbar"><i style="width:<?=round(min(100, $r['mb'] / $dtot * 100), 1)?>%"></i></div></td>
<td style="white-space:nowrap"><?php if($r['kind'] === 'dir'):?><a class="btn small" href="/disk?path=<?=urlencode($r['label'])?><?=$adminQs?>">Open</a><?php endif;?></td></tr><?php endforeach;?>
<?php if(empty($drows)):?><tr><td colspan="4" style="color:var(--muted)">Empty folder.</td></tr><?php endif;?></table>
<?php if(!empty($drill['total'])):?><small style="color:var(--muted)">Folder total <?=Helpers::e(Helpers::fmtMb($drill['total']['mb']))?> · <?=number_format($drill['total']['files'])?> file(s)<?=($drill['total']['files'] >= 100001 ? '+' : '')?></small><?php endif;?></div>

<?php elseif(!empty($tree)):?>
<div class="fwtiles">
<div class="fwtile"><small>🌐 Websites</small><b style="font-size:22px"><?=Helpers::e(Helpers::fmtMb($siteMb))?></b><span class="dbsub muted"><?=count(array_filter($of('top'), fn($r) => isset($sites[$r['label']])))?> site folder(s) · counted</span></div>
<div class="fwtile"><small>📬 Email</small><b style="font-size:22px"><?=Helpers::e(Helpers::fmtMb($mailMb))?></b><span class="dbsub muted"><?=count($mailBy)?> mail domain(s) · counted</span></div>
<div class="fwtile"><small>📁 Other files</small><b style="font-size:22px"><?=Helpers::e(Helpers::fmtMb($otherMb))?></b><span class="dbsub muted">trash, temp, builder, loose files · counted</span></div>
<div class="fwtile"><small>🗄️ Databases</small><b style="font-size:22px"><?=Helpers::e(Helpers::fmtMb($dbMb))?></b><span class="dbsub muted"><?=count($dbSizes ?? [])?> database(s) · not counted</span></div>
<div class="fwtile"><small>💾 Backups</small><b style="font-size:22px"><?=Helpers::e(Helpers::fmtMb((float)($bk['mb'] ?? 0) + (float)($snap['mb'] ?? 0)))?></b><span class="dbsub muted"><?=(int)($bk['label'] ?? 0)?> backup(s)<?= $snap ? ' + snapshots' : '' ?> · not counted · <a href="/backups">view</a></span></div>
</div>

<div class="card"><h3 style="margin-top:0">📂 Your home folder <small style="color:var(--muted)">biggest first</small></h3>
<table class="list"><tr><th>Folder</th><th>Size</th><th>Share</th><th>Mail</th><th></th></tr>
<?php $hdiv = max($homeMb, 0.01); foreach($of('top') as $r): $isSite = isset($sites[$r['label']]); $more = $r['label'] === '(more folders)';?>
<tr><td><?= $isSite ? '🌐' : (str_starts_with($r['label'], '.') ? '⚙️' : '📁') ?> <small class="clip" title="<?=Helpers::e($r['label'])?>"><?=Helpers::e($r['label'])?></small>
<?php if(!$isSite && isset($known[$r['label']])):?><br><small style="color:var(--muted)"><?=Helpers::e($known[$r['label']])?></small><?php endif;?></td>
<td><?=Helpers::e(Helpers::fmtMb($r['mb']))?></td>
<td><div class="bar mbar"><i style="width:<?=round(min(100, $r['mb'] / $hdiv * 100), 1)?>%"></i></div></td>
<td><?= isset($mailBy[$r['label']]) ? Helpers::e(Helpers::fmtMb($mailBy[$r['label']])) : '<span style="color:var(--muted)">—</span>' ?></td>
<td style="white-space:nowrap"><?php if(!$more):?><a class="btn small" href="/disk?path=<?=urlencode($r['label'])?><?=$adminQs?>">Open</a><?php endif;?></td></tr>
<?php endforeach;?>
<?php foreach($of('loose') as $r): if ($r['mb'] < 0.01) continue;?><tr><td>📄 <small>files in the home folder itself</small></td><td><?=Helpers::e(Helpers::fmtMb($r['mb']))?></td><td></td><td></td><td></td></tr><?php endforeach;?>
<?php if(empty($of('top'))):?><tr><td colspan="5" style="color:var(--muted)">Empty home folder.</td></tr><?php endif;?></table>
<?php if($total):?><small style="color:var(--muted)">Home total <?=Helpers::e(Helpers::fmtMb($total['mb']))?> · <?=number_format($total['files'])?> file(s)<?=($total['files'] >= 100001 ? '+' : '')?> · mail is stored outside the home and added to the quota</small><?php endif;?></div>

<?php if(!empty($dbSizes)):?><div class="card"><h3 style="margin-top:0">🗄️ Databases <small style="color:var(--muted)">not counted toward the quota</small></h3><table class="list"><tr><th>Database</th><th>Size</th></tr>
<?php foreach($dbSizes as $n => $mb):?><tr><td><?=Helpers::e((string)$n)?></td><td><?=Helpers::e(Helpers::fmtMb($mb))?></td></tr><?php endforeach;?></table></div><?php endif;?>

<?php else:?>
<div class="card" style="color:var(--muted)">Couldn't measure this account just now — press Refresh to try again.</div>
<?php endif;?>

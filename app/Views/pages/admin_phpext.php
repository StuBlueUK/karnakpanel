<?php $csrf = Helpers::csrf(); ?>
<h2>🧩 PHP extensions <small style="color:var(--muted)">extra modules for hosted sites — installed on every PHP version at once</small></h2>
<p style="color:var(--muted);margin-top:-6px">Each install covers PHP <?=Helpers::e(implode(', ', $vers ?: PhpVersions::known()))?> together, so moving a site between versions never loses an extension. Installs are server-wide: every account's sites and cron jobs get the module (PHP runs one shared process manager per version, so a module can't be limited to some accounts). The panel and webmail run on their own PHP and are not affected. A version added later gets every extension installed here. Each install or removal restarts PHP for a moment on every version (a second or two of failed requests).</p>
<?php if(!empty($err)):?><div class="card"><span class="pill err">status failed</span> <small><?=Helpers::e($err)?></small></div><?php endif;?>
<?php if(!empty($running)):?><div class="card" data-reloadin="5"><span class="spin" aria-hidden="true"></span> <b>Working…</b> <small style="color:var(--muted)">apt is installing or removing an extension — this page refreshes by itself. Buttons are paused until it finishes.</small></div><?php endif;?>
<?php foreach(PhpExtensions::CATALOG as $g => $exts):?>
<div class="card"><h3><?=Helpers::e($g)?></h3>
<table><tr><th style="width:120px">Extension</th><th>Used for</th><th style="width:<?=max(160, 70 * count($vers))?>px">PHP versions</th><th style="width:110px"></th></tr>
<?php foreach($exts as $e => $about): $st = $grid[$e] ?? []; $vals = array_values($st);
$any = in_array('on', $vals, true) || in_array('off', $vals, true); $gap = in_array('missing', $vals, true); ?>
<tr><td><b><?=Helpers::e($e)?></b></td><td><small><?=Helpers::e($about)?></small></td>
<td><?php if(!$st):?><small style="color:var(--muted)">unknown</small><?php elseif(!$any):?><small style="color:var(--muted)">not installed</small><?php elseif(!$gap && !in_array('off', $vals, true)):?><span class="pill">✓ all versions</span><?php else: foreach($st as $v => $s):?>
<?php if($s === 'on'):?><span class="pill"><?=$v?></span>
<?php elseif($s === 'off'):?><span class="pill err" title="Installed but PHP <?=$v?> does not load it — Install again to re-enable"><?=$v?> off</span>
<?php elseif($s === 'missing'):?><span class="pill err" title="Missing on PHP <?=$v?> — sites moved to it lose this extension"><?=$v?> missing</span>
<?php else:?><span class="pill" style="opacity:.6" title="No package for PHP <?=$v?>"><?=$v?> n/a</span><?php endif;?>
<?php endforeach; endif;?></td>
<td style="white-space:nowrap"><?php if($gap || in_array('off', $vals, true)):?><form method="post" action="/admin/php-ext" style="display:inline"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="act" value="install"><input type="hidden" name="ext" value="<?=Helpers::e($e)?>"><button class="btn small"<?=!empty($running) ? ' disabled' : ''?>><?=$any ? 'Install on all' : 'Install'?></button></form><?php endif;?>
<?php if($any):?><form method="post" action="/admin/php-ext" style="display:inline" onsubmit="return confirm('Remove <?=Helpers::e($e)?> from every PHP version? Sites that use it will show errors until it is installed again.')"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="act" value="remove"><input type="hidden" name="ext" value="<?=Helpers::e($e)?>"><button class="btn small danger"<?=!empty($running) ? ' disabled' : ''?>>Remove</button></form><?php endif;?></td></tr>
<?php endforeach;?></table></div>
<?php endforeach;?>
<div class="card"><details<?=!empty($running) ? ' open' : ''?>><summary style="cursor:pointer"><b>Recent activity</b> <small style="color:var(--muted)">— full log in <a href="/admin/logs?log=phpext">Logs → PHP extensions</a></small></summary>
<?php if(empty($log)):?><p style="color:var(--muted)">Nothing yet.</p><?php else:?><pre style="white-space:pre-wrap;font-size:12px;max-height:260px;overflow:auto"><?=Helpers::e(implode("\n", $log))?></pre><?php endif;?></details>
<small style="color:var(--muted)">Not offered on purpose: xdebug, pcov, xhprof and similar debuggers (slow, can expose data), uopz and swoole (can redefine the functions the hosting lockdown turns off), apcu (one cache shared by every account on a PHP version — they could read and overwrite each other's data), and closed-source loaders such as ionCube.</small></div>

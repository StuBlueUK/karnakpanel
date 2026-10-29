<h2>🐘 PHP Versions <small style="color:var(--muted)">per-user PHP-FPM pools (isolated)</small></h2>
<div class="card"><p>Each account runs its own FPM pool as its own system user (<?php foreach(PhpVersions::selectable() as $v):?><span class="badge"><?=Helpers::e($v)?></span> <?php endforeach;?>) — one customer's scripts cannot read another's files. Switching moves the pool; the web config stays put.</p>
<form class="inline" method="post" action="/php/set">
<input type="hidden" name="csrf" value="<?=Helpers::csrf()?>">
<select name="domain_id"><?php foreach($domains as $d):?><option value="<?=$d['id']?>"><?=Helpers::e($d['domain'])?> (now <?=Helpers::e($d['php_version'])?>)</option><?php endforeach;?></select>
<select name="php_version"><?=PhpVersions::options()?></select>
<button class="btn">Switch version</button></form>
<small style="color:var(--muted)">Pools are per-account: switching moves <b>all</b> your domains to the new version together.</small></div>
<?php foreach($stale ?? [] as $st): $eol = PhpVersions::eol((string)$st['v']);?><div class="card" style="border-left:4px solid var(--warn,#d97706)"><b>PHP <?=Helpers::e($st['v'])?> <?=$eol ? 'reaches end of life '.Helpers::e($eol) : 'is being removed from this server'?></b> — <?=(int)$st['n']?> account(s) still on it: <?=Helpers::e(mb_substr((string)$st['who'], 0, 400))?>
<form class="inline" method="post" action="/php/move-all" onsubmit="return confirm('Move every account on PHP <?=Helpers::e($st['v'])?> to <?=Helpers::e(PhpVersions::DEFAULT)?>? Their pools restart; test the sites afterwards.')">
<input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><input type="hidden" name="from" value="<?=Helpers::e($st['v'])?>">
<button class="btn small">Move all PHP <?=Helpers::e($st['v'])?> sites to <?=Helpers::e(PhpVersions::DEFAULT)?></button></form></div><?php endforeach;?>
<div class="card"><table><tr><th>Domain</th><th>Version</th></tr>
<?php foreach($domains as $d):?><tr><td><?=Helpers::e($d['domain'])?></td><td><span class="pill"><?=Helpers::e($d['php_version'])?></span><?php if($eol = PhpVersions::eol((string)$d['php_version'])):?> <span class="pill warn" title="Still served, no longer offered — switch before it is removed">EOL <?=Helpers::e($eol)?></span><?php elseif(!PhpVersions::isSelectable((string)$d['php_version'])):?> <span class="pill err" title="Not offered any more — switch to a current version">retiring</span><?php endif;?></td></tr><?php endforeach;?></table></div>
<div class="card"><h3>📜 PHP error log <small style="color:var(--muted)">your pool — all your domains, newest first, max 200</small></h3>
<form method="get" action="/php" class="inline"><input name="q" value="<?=Helpers::e($q ?? '')?>" placeholder="filter text"> <button class="btn small">Filter</button><?php if(!empty($q ?? '')):?> <a class="btn small" href="/php">Clear</a><?php endif;?></form>
<pre style="white-space:pre-wrap;font-size:12px"><?php foreach($phplog ?? [] as $l):?><?=Helpers::e($l)?>
<?php endforeach;?><?php if(empty($phplog ?? [])):?>No PHP errors logged.<?php endif;?></pre></div>

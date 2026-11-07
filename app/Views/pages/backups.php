<h2>💾 Backups</h2>
<?php
$isAdm = ($user['role'] ?? '') === 'admin';
$us = $usage ?? [];
$tbN = (int)($us['tarballs'][0] ?? 0); $tbB = (float)($us['tarballs'][1] ?? 0);
$snB = (float)($us['snapshots'][0] ?? 0) * 1024;
$dmN = (int)($us['dumps'][0] ?? 0); $dmB = (float)($us['dumps'][1] ?? 0);
$rsB = (float)($us['restic'][0] ?? 0) * 1024;
$allB = $tbB + $snB + $dmB + $rsB;
$mbOf = fn(float $b) => Helpers::e(Helpers::fmtMb($b / 1048576));
?>
<?php if(!empty($us)):?><div class="card"><h3 style="margin-top:0">📦 Backup storage <small style="color:var(--muted)"><?= $isAdm ? 'whole server' : 'your account' ?> · backups don't count toward your disk quota</small></h3>
<div class="fwtiles">
<div class="fwtile"><small>Total</small><b style="font-size:22px"><?=$mbOf($allB)?></b><span class="dbsub muted">all backup data<?= $isAdm ? '' : ' of yours' ?></span></div>
<div class="fwtile"><small>💾 Full backups</small><b style="font-size:22px"><?=$mbOf($tbB)?></b><span class="dbsub muted"><?=$tbN?> file(s) · kept 14 days</span></div>
<?php if(isset($us['snapshots'])):?><div class="fwtile"><small>⚡ Snapshots</small><b style="font-size:22px"><?=$mbOf($snB)?></b><span class="dbsub muted">incremental · kept 7 days</span></div><?php endif;?>
<?php if($isAdm):?><div class="fwtile"><small>🗄️ Database dumps</small><b style="font-size:22px"><?=$mbOf($dmB)?></b><span class="dbsub muted"><?=$dmN?> file(s) · one per night · kept 14 days</span></div>
<?php if(isset($us['restic'])):?><div class="fwtile"><small>🗿 Restic repo</small><b style="font-size:22px"><?=$mbOf($rsB)?></b><span class="dbsub muted">14d / 8w / 6m</span></div><?php endif;?><?php endif;?>
</div>
<?php if($isAdm && isset($us['free'][1]) && $us['free'][1] > 0): $fr = (float)$us['free'][0]; $sz = (float)$us['free'][1]; $usedPct = round(100 - $fr / $sz * 100, 1);?>
<div style="margin-top:10px"><small>Backup disk: <?=$mbOf($fr)?> free of <?=$mbOf($sz)?> (<?=$usedPct?>% used)</small>
<div class="bar mbar"><i class="<?= $usedPct >= 90 ? 'hot' : ($usedPct >= 75 ? 'warm' : '') ?>" style="width:<?=$usedPct?>%"></i></div></div><?php endif;?>
</div><?php endif;?>

<?php if(!empty($delPreview)): $dp = $delPreview; $dn = count($dp['rows']);?>
<div class="card" id="delprev" style="border-color:var(--err)"><h3 style="margin-top:0">🗑️ Delete backups — check before confirming</h3>
<?php if($dn):?>
<div class="alert">⚠️ <b><?=$dn?> backup(s), <?=$mbOf((float)$dp['bytes'])?></b> (<?=Helpers::e($dp['label'])?>) will be <b>permanently deleted</b>. They cannot be restored afterwards<?= $isAdm && !empty($off['configured']) ? ', and the offsite copy drops them on its next sync' : '' ?>.</div>
<table class="list"><tr><th>File</th><?php if($isAdm):?><th>Owner</th><?php endif;?><th>Size</th><th>Date</th></tr>
<?php foreach(array_slice($dp['rows'], 0, 50) as $r):?><tr><td><small><?=Helpers::e($r['filename'])?></small></td><?php if($isAdm):?><td><?=Helpers::e($r['owner'])?></td><?php endif;?><td><?=$mbOf((float)$r['size_bytes'])?></td><td><?=Helpers::e($r['created_at'])?></td></tr><?php endforeach;?>
<?php if($dn > 50):?><tr><td colspan="4" style="color:var(--muted)">…and <?=$dn - 50?> more</td></tr><?php endif;?></table>
<?php else:?><p style="color:var(--muted)">Nothing to delete for <?=Helpers::e($dp['label'])?>.</p><?php endif;?>
<?php if(!empty($dp['kept'])):?><p style="margin-bottom:4px"><b>Kept:</b></p><ul style="margin-top:0"><?php foreach(array_slice($dp['kept'], 0, 20) as [$r, $why]):?><li><small><?=Helpers::e($r['filename'])?> — <?=Helpers::e($why)?></small></li><?php endforeach;?></ul><?php endif;?>
<?php if($dn):?>
<form method="post" action="/backups/delete" onsubmit="return confirm('Permanently delete <?=$dn?> backup(s)? This cannot be undone.')" style="display:grid;gap:8px;max-width:480px">
<input type="hidden" name="csrf" value="<?=Helpers::csrf()?>">
<?php foreach($dp['rows'] as $r):?><input type="hidden" name="ids[]" value="<?=(int)$r['id']?>"><?php endforeach;?>
<label><input type="checkbox" name="ack" value="1" required> I understand these backups are gone for good</label>
<label>Type <b>DELETE</b> to confirm <input name="confirm" required pattern="DELETE" autocomplete="off" placeholder="DELETE" style="max-width:140px"></label>
<div><button class="btn danger">Delete <?=$dn?> backup(s)</button> <a class="btn" href="/backups">Cancel</a></div>
</form>
<?php else:?><a class="btn" href="/backups">Back</a><?php endif;?>
</div><?php endif;?>
<div class="card"><h3>🔐 Encryption <small style="color:var(--muted)">protects downloaded/stored copies at rest — restores work either way</small></h3>
<form class="inline" method="post" action="/backups/key" onsubmit="return confirm('<?=!empty($keyOn)?'Turn encryption OFF? Backups decrypt in background.':'Generate a backup key? It is shown ONCE on the next page — write it down before closing it. Without it, downloaded backups can NEVER be decrypted.'?>')">
<input type="hidden" name="csrf" value="<?=Helpers::csrf()?>">
<input type="hidden" name="mode" value="<?=!empty($keyOn)?'disable':'enable'?>">
<button class="btn<?=!empty($keyOn)?' danger':''?>"><?=!empty($keyOn)?'Disable encryption':'Enable encryption'?></button></form>
<small style="color:var(--muted)">Status: <b><?=!empty($keyOn)?'🔒 on — new backups encrypted':'open'?></b> · Incrementals stay plaintext (local only).</small>
<?php if(!empty($keyOn)):?><div class="alert" style="margin-top:6px">⚠️ The key was shown <b>once</b> when you enabled this. If you didn't write it down, turn encryption OFF and back ON to get a fresh key — old downloads stay locked to a lost key forever.</div><?php endif;?>
<?php if(!empty($keyOn)):?><pre style="white-space:pre-wrap;font-size:12px;margin:8px 0 0">Decrypt a download on your own machine (it asks for the key shown at enable time):

  openssl enc -d -aes-256-cbc -pbkdf2 -in admin-20240101-120000.tar.gz -out restored.tar.gz
  tar tzf restored.tar.gz            # verify, then extract as normal

Same command works for database dumps (*.sql.gz). Panel restores need no
command — they decrypt automatically.</pre><?php endif;?></div>
<div class="card"><?php if(!empty($running)):?><div class="alert ok">⏳ A backup of your account is running — it appears in the list below when done (this page refreshes itself until you start typing in a form).</div><script>(function(){let t=false;document.addEventListener("input",()=>{t=true;});setInterval(()=>{if(!t&&!(document.activeElement&&/^(INPUT|TEXTAREA|SELECT)$/.test(document.activeElement.tagName)))location.reload();},20000);})();</script><?php else:?><form method="post" action="/backups/create"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><button class="btn">+ Create backup now</button></form><?php endif;?>
<small>Stored in /backup/skypanel + listed below. Automatic nightly backups run at 02:00.</small></div>
<?php if(($user['role'] ?? '') === 'admin'):?><div class="card"><h3>⏰ Nightly schedule <small style="color:var(--muted)">whole-box backup run</small></h3>
<p>Status: <?= ($sched ?? '') === 'disabled' ? '<span class="pill err">⚠️ DISABLED — no automatic backups</span>' : '<span class="pill">enabled · '.Helpers::e(($sched ?? '02:00').' daily').'</span>' ?></p>
<form class="inline" method="post" action="/backups/schedule"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><input type="hidden" name="action" value="set">
<input name="time" placeholder="02:00" pattern="[0-9]{1,2}:[0-9]{2}" maxlength="5" required style="max-width:80px" title="24h HH:MM"> <button class="btn small">Set time</button></form>
<?php if(($sched ?? '') === 'disabled'):?>
<form method="post" action="/backups/schedule" style="margin-top:12px"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><input type="hidden" name="action" value="enable"><button class="btn small">Re-enable nightly backups</button></form>
<?php else:?>
<form method="post" action="/backups/schedule" style="margin-top:12px" onsubmit="return confirm('DISABLE automatic nightly backups? No new backups will be made until re-enabled. Existing backups stay.')"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><input type="hidden" name="action" value="disable"><button class="btn small danger">Disable nightly backups</button></form>
<?php endif;?></div><?php endif;?>
<div class="card"><h3 style="margin-top:0">🧹 Remove old backups <small style="color:var(--muted)">you see what goes before anything is deleted</small></h3>
<form class="inline" method="get" action="/backups#delprev"><input type="hidden" name="del" value="older">
Older than <select name="days"><?php foreach([3,7,14,30,60,90] as $d):?><option value="<?=$d?>"<?= $d === 7 ? ' selected' : '' ?>><?=$d?> days</option><?php endforeach;?></select>
<?php if($isAdm):?> for <select name="acct"><option value="">all accounts</option><?php foreach($owners ?? [] as $o):?><option><?=Helpers::e($o)?></option><?php endforeach;?></select><?php endif;?>
<button class="btn small">Preview</button></form>
<small style="color:var(--muted)">Or tick backups in the list below. The newest backup of each account is always kept. Nightly runs already remove full backups after 14 days.</small></div>
<form id="delsel" method="get" action="/backups#delprev"><input type="hidden" name="del" value="sel"></form>
<div class="card"><table><tr><th><input type="checkbox" aria-label="select all" onclick="document.querySelectorAll('.bksel').forEach(c=>c.checked=this.checked)"></th><th>File</th><?php if(!empty($backups) && isset($backups[0]['owner'])):?><th>Owner</th><?php endif;?><th>Size</th><th>Date</th><th></th></tr>
<?php foreach($backups as $b):?><tr><td><input type="checkbox" class="bksel" form="delsel" name="ids[]" value="<?=(int)$b['id']?>" aria-label="select <?=Helpers::e($b['filename'])?>"></td><td><?=Helpers::e($b['filename'])?></td><?php if(isset($b['owner'])):?><td><?=Helpers::e($b['owner'])?></td><?php endif;?><td><?=Helpers::e(Helpers::fmtMb((float)$b['size_bytes']/1048576))?></td><td><?=Helpers::e($b['created_at'])?></td>
<td><a class="btn small" href="/backups/download?id=<?=$b['id']?>">Download</a> <a class="btn small" href="/backups/browse?id=<?=$b['id']?>">Browse</a> <form style="display:inline" method="post" action="/backups/restore" onsubmit="return confirm('Restore <?=Helpers::e($b['filename'])?>? Overwrites sites + mail with the backup copy.')"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><input type="hidden" name="id" value="<?=$b['id']?>"><button class="btn small">Restore</button></form></td></tr><?php endforeach;?>
<?php if(empty($backups)):?><tr><td colspan="6" style="color:var(--muted)">No backups yet. On dev the system tar is skipped — the entry is still logged here.</td></tr><?php endif;?></table>
<?php if(!empty($backups)):?><button class="btn small danger" form="delsel" style="margin-top:8px">🗑️ Delete selected…</button> <small style="color:var(--muted)">shows a preview first</small><?php endif;?>
<?php $pagerBase = '/backups?'; $pagerLabel = 'backups'; require __DIR__.'/_pager.php'; ?></div>
<div class="card"><h3>📬 Restore one mailbox <small style="color:var(--muted)">whole Maildir back into a live mailbox</small></h3>
<form class="inline" method="post" action="/backups/mailbox" onsubmit="return confirm('Restore this mailbox? Current mail in it stays — backup messages are added.')"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>">
<select name="mailbox_id"><?php foreach($emails as $e):?><option value="<?=$e['id']?>"><?=Helpers::e($e['localpart'].'@'.$e['domain'])?></option><?php endforeach;?></select>
<select name="backup_id"><?php foreach($backups as $b):?><option value="<?=$b['id']?>"><?=Helpers::e($b['filename'])?></option><?php endforeach;?></select>
<button class="btn">Restore mailbox</button></form></div>
<div class="card"><h3>🗄️ Restore one database <small style="color:var(--muted)">replaces data — users/passwords untouched</small></h3><form class="inline" method="post" action="/backups/db" onsubmit="return confirm('Replace this database with the dump copy? Current data goes.')"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>">
<select name="db_id"><?php foreach($dbs as $d):?><option value="<?=$d['id']?>"><?=Helpers::e($d['db_name'])?></option><?php endforeach;?></select>
<select name="dump"><?php foreach($dumps as $f):?><option><?=Helpers::e($f)?></option><?php endforeach;?></select>
<button class="btn">Restore database</button></form></div>
<div class="card"><h3>⚡ Incremental snapshots <small style="color:var(--muted)">daily rsync, keep 7 days — single-path restore</small></h3>
<form class="inline" method="post" action="/backups/inc" onsubmit="return confirm('Restore this path? It overwrites the live copy.')"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>">
<select name="date"><?php foreach($snaps ?? [] as $s):?><option><?=Helpers::e($s)?></option><?php endforeach;?></select>
<input name="relpath" placeholder="home/you/domain/public_html/index.html" required style="min-width:300px">
<button class="btn">Restore path</button></form>
<small style="color:var(--muted)">Paths mirror tarball layout: <code>home/&lt;user&gt;/…</code> or <code>var/mail/virtual/&lt;domain&gt;/…</code></small></div>
<?php if(($user['role'] ?? '') === 'admin'):?><div class="card"><h3>🗿 Restic engine <small style="color:var(--muted)">incremental snapshots instead of nightly full tarballs</small></h3>
<form class="inline" method="post" action="/backups/engine" onsubmit="return confirm('Switch backup engine? Snapshots stay put either way.')"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><input type="hidden" name="engine" value="<?=!empty($resticOn)?'0':'1'?>"><button class="btn"><?=!empty($resticOn)?'Back to tarballs':'Enable restic'?></button></form>
<small style="color:var(--muted)">Current: <b><?=!empty($resticOn)?'restic (14 daily / 8 weekly / 6 monthly pins, weekly integrity check)':'tarballs (14-day window)'?></b> · Offsite copies are encrypted mirrors, <b>not</b> append-only — remote history follows local prune.</small>
<?php if(!empty($resticOn)):?><form class="inline" method="post" action="/backups/restic-offsite-sync" style="margin-top:8px"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><button class="btn small">Sync restic repo now</button></form><?php endif;?></div><?php endif;?>
<?php if(!empty($resticOn) && !empty($rsnaps)):?><div class="card"><h3>📸 Snapshots <small style="color:var(--muted)">newest first — restores verify ownership twice</small></h3>
<table><tr><th>When</th><th>Kind</th><?php if(($user['role'] ?? '') === 'admin'):?><th>Account</th><?php endif;?><th>Paths</th><th>ID</th></tr>
<?php foreach(array_slice($rsnaps, 0, 30) as $s):?><tr><td><?=Helpers::e($s['time'])?></td><td><?=Helpers::e($s['kind'])?></td><?php if(($user['role'] ?? '') === 'admin'):?><td><?=Helpers::e($s['account'])?></td><?php endif;?><td><small><?=Helpers::e($s['paths'])?></small></td><td><code><?=Helpers::e($s['id'])?></code></td></tr><?php endforeach;?></table>
<div style="display:flex;gap:16px;flex-wrap:wrap;margin-top:8px">
<form class="inline" method="post" action="/backups/rdb" onsubmit="return confirm('Replace this database with the snapshot copy? Current data goes.')"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>">
<select name="db_id"><?php foreach($dbs as $d):?><option value="<?=$d['id']?>"><?=Helpers::e($d['db_name'])?></option><?php endforeach;?></select>
<select name="snapshot"><?php foreach(array_filter($rsnaps, fn($s)=>$s['kind']==='db') as $s):?><option value="<?=Helpers::e($s['id'])?>"><?=Helpers::e($s['time'])?> (<?=Helpers::e($s['id'])?>)</option><?php endforeach;?></select>
<button class="btn small">Restore DB from snapshot</button></form>
<form class="inline" method="get" action="/backups/rfile">
<select name="snapshot"><?php foreach(array_filter($rsnaps, fn($s)=>$s['kind']==='files') as $s):?><option value="<?=Helpers::e($s['id'])?>"><?=Helpers::e($s['time'])?> (<?=Helpers::e($s['id'])?>)</option><?php endforeach;?></select>
<input name="path" placeholder="/home/you/file.txt" required style="min-width:260px">
<button class="btn small">Download file from snapshot</button></form>
</div></div><?php endif;?>
<?php if(($user['role'] ?? '') === 'admin'):?><div class="card"><h3>☁️ Offsite copy <small style="color:var(--muted)">encrypted mirror of /backup/skypanel — nightly after local backups, plus on demand</small></h3>
<?php $o = $off ?? ['configured' => false]; ?>
<?php if(!empty($o['configured'])):?>
<p>Status: <span class="pill ok">✅ <?=Helpers::e($o['provider'])?> → <?=Helpers::e($o['target'])?></span> <small style="color:var(--muted)"><?=Helpers::e($o['count'])?> files · <?=Helpers::e($o['size'])?> · <?=Helpers::e($o['last'])?></small></p>
<form class="inline" method="post" action="/backups/offsite-sync"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><button class="btn">Sync now</button></form>
<form class="inline" method="post" action="/backups/offsite-fetch"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><input name="filename" placeholder="user-20240101-120000.tar.gz" required style="min-width:240px" pattern="[a-zA-Z0-9_.-]+\.tar\.gz"><button class="btn small">Fetch back</button></form>
<small style="color:var(--muted)">Fetched files land in local storage and appear above for download/restore.</small>
<form method="post" action="/backups/offsite-disable" onsubmit="return confirm('Stop offsite syncing and forget the remote? Remote data stays at the provider; the crypt password dies with the config.')"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><button class="btn small danger">Disable offsite</button></form>
<?php else:?>
<form method="post" action="/backups/offsite">
<input type="hidden" name="csrf" value="<?=Helpers::csrf()?>">
<div style="display:grid;gap:6px;max-width:520px">
<select name="provider" id="off-prov" onchange="document.querySelectorAll('.off-f').forEach(e=>{e.style.display='none';e.querySelectorAll('input,select').forEach(i=>i.disabled=true)});var s=document.getElementById('off-'+this.value);s.style.display='grid';s.querySelectorAll('input,select').forEach(i=>i.disabled=false)">
<option value="s3">S3-compatible (AWS · Backblaze B2 · Wasabi · Hetzner · R2 · MinIO)</option>
<option value="sftp">SFTP server</option>
<option value="local">Local path (USB mount…)</option>
</select>
<div class="off-f" id="off-s3" style="display:grid;gap:6px">
<select name="preset"><option>AWS</option><option>Backblaze</option><option>Wasabi</option><option>Hetzner</option><option>Cloudflare</option><option>Other</option></select>
<input name="bucket" placeholder="bucket name" required>
<input name="endpoint" placeholder="endpoint URL (blank for AWS; B2 console shows yours)">
<input name="region" placeholder="region (blank if unsure)">
<input name="key" placeholder="access key" required>
<input name="secret" type="password" autocomplete="new-password" placeholder="secret key" required>
</div>
<div class="off-f" id="off-sftp" style="display:none;gap:6px">
<input disabled name="host" placeholder="host">
<input disabled name="port" value="22" style="max-width:80px">
<input disabled name="user" placeholder="username">
<input disabled name="password" type="password" placeholder="password">
<input disabled name="path" value="/backups">
</div>
<div class="off-f" id="off-local" style="display:none;gap:6px">
<input disabled name="path" placeholder="/mnt/usb/backups">
</div>
<button class="btn">Connect &amp; verify</button>
</div></form>
<small style="color:var(--muted)">Everything is encrypted before it leaves (provider sees ciphertext only). The recovery password is shown <b>once</b> after connecting — without it offsite copies can never be read back.</small>
<?php endif;?></div><?php endif;?>

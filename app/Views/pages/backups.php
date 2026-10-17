<h2>💾 Backups</h2>
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
<div class="card"><form method="post" action="/backups/create"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><button class="btn">+ Create backup now</button></form>
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
<div class="card"><table><tr><th>File</th><?php if(!empty($backups) && isset($backups[0]['owner'])):?><th>Owner</th><?php endif;?><th>Size</th><th>Date</th><th></th></tr>
<?php foreach($backups as $b):?><tr><td><?=Helpers::e($b['filename'])?></td><?php if(isset($b['owner'])):?><td><?=Helpers::e($b['owner'])?></td><?php endif;?><td><?=Helpers::e(Helpers::fmtMb((float)$b['size_bytes']/1048576))?></td><td><?=Helpers::e($b['created_at'])?></td>
<td><a class="btn small" href="/backups/download?id=<?=$b['id']?>">Download</a> <a class="btn small" href="/backups/browse?id=<?=$b['id']?>">Browse</a> <form style="display:inline" method="post" action="/backups/restore" onsubmit="return confirm('Restore <?=Helpers::e($b['filename'])?>? Overwrites sites + mail with the backup copy.')"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><input type="hidden" name="id" value="<?=$b['id']?>"><button class="btn small">Restore</button></form></td></tr><?php endforeach;?>
<?php if(empty($backups)):?><tr><td colspan="4" style="color:var(--muted)">No backups yet. On dev the system tar is skipped — the entry is still logged here.</td></tr><?php endif;?></table>
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

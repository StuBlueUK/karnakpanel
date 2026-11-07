<h2>🧪 App Toolkit <small style="color:var(--muted)">staging clones — test updates off the live site</small></h2>
<?php if(!empty($confirm)): $cc = $confirm['clone']; $cm = $confirm['mode']; ?>
<div class="card"><h3>Push <?= $cm==='full'?'FULL':'files-only' ?> to live: <?=Helpers::e($cc['live_domain'])?></h3>
<?php if($cm==='full'):?>
<p>Staging <code><?=Helpers::e($cc['staging_domain'])?></code> overwrites the live site <b>including its database</b>.</p>
<?php if($confirm['div']):?><p>⚠️ Live moved since this clone: <b><?=$confirm['div']['posts']?> posts</b>, <b><?=$confirm['div']['comments']?> comments</b> — a full push discards them.</p>
<?php else:?><p><small style="color:var(--muted)">Divergence unknown (live DB unreadable) — a full push still overwrites the live database.</small></p><?php endif;?>
<p>A restore point is taken automatically first (kept: newest 2).</p>
<form method="post" action="/toolkit/push"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><input type="hidden" name="clone_id" value="<?=(int)$cc['id']?>"><input type="hidden" name="mode" value="full"><input name="confirm" placeholder="type PUSH" required style="max-width:120px"><button class="btn danger">Push full</button> <a class="btn" href="/toolkit">Cancel</a></form>
<?php else:?>
<p>Staging files overwrite the live files. The live database is untouched; the live <code>wp-config.php</code> (DB creds) is preserved.</p>
<form method="post" action="/toolkit/push" onsubmit="return confirm('Push staging FILES to live? Live file changes are overwritten.')"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><input type="hidden" name="clone_id" value="<?=(int)$cc['id']?>"><input type="hidden" name="mode" value="files"><input type="hidden" name="confirm" value="1"><button class="btn">Push files</button> <a class="btn" href="/toolkit">Cancel</a></form>
<?php endif;?></div>
<?php endif;?>
<?php if(empty($installs)):?><div class="card"><p class="empty">No installed apps yet — install WordPress from 🧰 Software first.</p></div>
<?php else:?><?php foreach($installs as $s): $cl = $byInstall[(int)$s['id']] ?? null; $soon = (($s['app']??'')!=='wordpress' && !$cl); ?>
<div class="card"<?=$soon?' style="opacity:.6"':''?>><h3><?=Helpers::e($s['app'])?> — <?=Helpers::e($s['domain'])?><?=($s['path']??'')!==''?'/'.Helpers::e(ltrim($s['path'],'/')):''?></h3>
<p><small style="color:var(--muted)"><a class="domlink" href="<?=Helpers::e($s['url'])?>" target="_blank" rel="noopener" title="Open site in new window"><b><?=Helpers::e($s['url'])?></b> <small>↗</small></a></small></p>
<?php if(!$cl):?>
<?php if(($s['app']??'')==='wordpress'):?>
<form method="post" action="/toolkit/clone"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><input type="hidden" name="install_id" value="<?=(int)$s['id']?>"><button class="btn">Clone to staging</button></form>
<p><small style="color:var(--muted)">Creates <code>staging.<?=Helpers::e($s['domain'])?></code> (own DB, password-locked, mail gated, hidden from search).</small></p>
<?php else:?><p><span class="pill">coming soon</span> <small style="color:var(--muted)">Staging isn't available for <?=Helpers::e($s['app'])?> yet.</small></p><?php endif;?>
<?php else:?>
<p>Staging: <a class="domlink" href="https://<?=Helpers::e($cl['staging_domain'])?>" target="_blank" rel="noopener" title="Open site in new window"><b>https://<?=Helpers::e($cl['staging_domain'])?></b> <small>↗</small></a>
<?php if(($cl['status']??'')==='ready'):?> <span class="pill">ready</span><?php else:?> <span class="pill warn">cloning… watch Logs → apptoolkit</span><?php endif;?>
<?php if(!empty($cl['basic_auth'])):?> <small style="color:var(--muted)">lock: <code><?=Helpers::e($cl['basic_auth'])?></code></small><?php endif;?></p>
<?php if(($cl['status']??'')==='ready'):?>
<div class="btnrow">
<form class="inline" method="post" action="/toolkit/clone"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><input type="hidden" name="install_id" value="<?=(int)$s['id']?>"><button class="btn small">Refresh</button></form>
<form class="inline" method="post" action="/toolkit/push"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><input type="hidden" name="clone_id" value="<?=(int)$cl['id']?>"><input type="hidden" name="mode" value="files"><button class="btn small">Push files…</button></form>
<form class="inline" method="post" action="/toolkit/push"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><input type="hidden" name="clone_id" value="<?=(int)$cl['id']?>"><input type="hidden" name="mode" value="full"><button class="btn small">Push full…</button></form>
<form class="inline" method="post" action="/toolkit/destroy" onsubmit="return confirm('Destroy staging <?=Helpers::e($cl['staging_domain'])?>? Files, DB, vhost and DNS go. Live is untouched.')"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><input type="hidden" name="clone_id" value="<?=(int)$cl['id']?>"><button class="btn small danger">Destroy</button></form>
</div>
<?php $ss = $snaps[(int)$cl['id']] ?? []; if(!empty($ss)):?><p><small style="color:var(--muted)">Restore points (newest 2 kept):</small></p>
<div class="btnrow">
<?php foreach($ss as $snap):?><form class="inline" method="post" action="/toolkit/restore" onsubmit="return confirm('Roll live back to snapshot #<?=(int)$snap['id']?> (<?=Helpers::e($snap['created_at'])?>)? Current live state is snapshotted first.')"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><input type="hidden" name="clone_id" value="<?=(int)$cl['id']?>"><input type="hidden" name="snapshot_id" value="<?=(int)$snap['id']?>"><input type="hidden" name="confirm" value="RESTORE"><button class="btn small">#<?=(int)$snap['id']?> <?=Helpers::e($snap['created_at'])?></button></form><?php endforeach;?>
</div>
<?php endif;?>
<?php else:?>
<div class="btnrow">
<form class="inline" method="post" action="/toolkit/dismiss"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><input type="hidden" name="clone_id" value="<?=(int)$cl['id']?>"><button class="btn small">Dismiss stuck record</button></form>
<form class="inline" method="post" action="/toolkit/destroy" onsubmit="return confirm('Destroy partial staging <?=Helpers::e($cl['staging_domain'])?>? Whatever was built (files, DB, vhost, DNS) goes too.')"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><input type="hidden" name="clone_id" value="<?=(int)$cl['id']?>"><button class="btn small danger">Destroy</button></form>
</div>
<?php endif;?>
<?php endif;?></div>
<?php endforeach;?><?php endif;?>
<div class="card"><p><small style="color:var(--muted)">Staging copies are password-locked, hidden from search engines, and cannot mail customers (all outbound goes to your account email). Progress: Logs → apptoolkit.</small></p></div>

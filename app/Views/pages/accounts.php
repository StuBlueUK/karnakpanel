<?php $isAdmin = ($me['role'] ?? '') === 'admin'; $csrf = Helpers::csrf(); ?>
<div class="fwhead achead"><h2>👥 Accounts</h2><span class="pill"><?= (int)($total ?? 0) ?> <?= ($q ?? '') !== '' ? 'match'.((int)($total ?? 0) === 1 ? '' : 'es') : 'account'.((int)($total ?? 0) === 1 ? '' : 's') ?></span>
<form method="get" action="/accounts" class="acsearch"><input type="search" name="q" value="<?=Helpers::e($q ?? '')?>" placeholder="🔍 search username or email"><?php if(($q ?? '')!==''):?><a class="btn small ghost" href="/accounts">Clear</a><?php endif;?></form>
<button type="button" class="btn small" id="acNewBtn" aria-expanded="false" aria-controls="acNew">＋ New account</button></div>

<div class="card" id="acNew" hidden><div class="fwcardhead"><h3>➕ Create account</h3><?php if($isAdmin):?><small class="muted">Importing from cPanel/Hestia or another server? Use <a href="/admin/migrate">Migrate</a>.</small><?php else:?><small class="muted">Resellers can create user accounts.</small><?php endif;?></div>
<form method="post" action="/accounts/create" class="acform">
<input type="hidden" name="csrf" value="<?=$csrf?>">
<label>Username<input name="username" placeholder="e.g. alice" required autocomplete="off"></label>
<label>Email<input name="email" type="email" placeholder="alice@example.com" required></label>
<label>Password<input name="password" type="password" autocomplete="new-password" placeholder="8+ characters" required minlength="8"></label>
<label>Role<select name="role"><option value="user">user</option><option value="reseller">reseller<?= $isAdmin ? '' : ' (admin only)' ?></option><?php if($isAdmin):?><option value="admin">admin (full power)</option><?php endif;?></select></label>
<label>Package<select name="package_id"><?php foreach($packages as $p):?><option value="<?=$p['id']?>"><?=Helpers::e($p['name'])?></option><?php endforeach;?></select></label>
<button class="btn">Create account</button></form></div>

<div class="card"><div class="tablewrap"><table class="accts"><thead><tr><th>Account</th><th>Role</th><th>Status</th><th>Disk</th><th>Bandwidth (month)</th><th class="acr">Actions</th></tr></thead><tbody>
<?php foreach($users as $x):
  $self = (int)$x['id'] === (int)($me['id'] ?? 0); $u = Helpers::e($x['username']); $act = $x['status'] === 'active'; $susp = $x['status'] === 'suspended' || !empty($x['bw_suspended']);
  $use = $x['use'] ?? null; $unl = !empty($use['unlimited']); $pct = $use && !$unl ? min(100, (int)($use['pct'] ?? 0)) : 0; ?>
<tr class="<?= $susp ? 'acoff' : '' ?>">
<td><div class="acwho"><span class="acav r-<?=Helpers::e($x['role'])?>"><?=Helpers::e(strtoupper(substr($x['username'], 0, 1)))?></span><div><b><?=$u?></b><?php if($self):?> <span class="badge fwjail">you</span><?php endif;?><div class="muted acmail"><?=Helpers::e($x['email'])?></div></div></div></td>
<td data-l="Role"><span class="acrole r-<?=Helpers::e($x['role'])?>"><?=Helpers::e($x['role'])?></span><?php if(!empty($x['totp_enabled'])):?> <span class="muted" title="2FA on">🔐</span><?php endif;?></td>
<td data-l="Status"><span class="pill mini <?= $susp ? 'err' : 'acok' ?>"><?=Helpers::e($x['status'])?></span><?php if(!empty($x['bw_suspended'])):?><div class="muted acnote">auto: bandwidth (lifts monthly)</div><?php endif;?></td>
<td class="acuse" data-l="Disk"><?php if($use):?><b><?=Helpers::e(Helpers::fmtMb((float)$use['used_mb']))?></b> <span class="muted">/ <?= $unl ? 'unlimited' : Helpers::e(Helpers::fmtMb((float)$x['disk_quota_mb'])) ?></span><?php if(!$unl):?><span class="abbar sm<?= $pct >= 90 ? ' err' : ($pct >= 70 ? ' warn' : '') ?>"><i style="width:<?=$pct?>%"></i></span><?php endif;?><?php else:?><span class="muted">—</span><?php endif;?></td>
<td data-l="Bandwidth (month)"><?= $x['bwmonth'] === null ? '<span class="muted">—</span>' : Helpers::e(Helpers::fmtMb($x['bwmonth']/1048576)) ?></td>
<td class="acr"><div class="acbtns">
<?php if($x['role']!=='admin' && !$self):?><form method="post" action="/accounts/impersonate" title="See the panel exactly as <?=$u?> sees it (logged)"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="id" value="<?=$x['id']?>"><button class="btn small ghost">👁 Login as</button></form><?php endif;?>
<?php if(!$self):?><form method="post" action="/accounts/suspend"<?= $act ? " onsubmit=\"return confirm('Suspend $u? Sites, mail and FTP stop until unsuspended.')\"" : '' ?>><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="username" value="<?=$u?>"><?php if($act):?><button class="btn small ghost acwarn" name="action" value="suspend">Suspend</button><?php else:?><button class="btn small" name="action" value="unsuspend">Unsuspend</button><?php endif;?></form><?php endif;?>
<details class="acmore"><summary class="btn small ghost" title="More actions for <?=$u?>" aria-label="More actions">⋯</summary><div class="acmenu">
  <form method="post" action="/accounts/email" class="acmrow"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="id" value="<?=$x['id']?>"><label class="muted">✉️ Contact email</label><div class="acinl"><input name="email" type="email" value="<?=Helpers::e($x['email'])?>" required><button class="btn small">Save</button></div></form>
  <?php if($isAdmin && !$self):?><form method="post" action="/accounts/role" class="acmrow" onsubmit="return confirm('Change <?=$u?> to the selected role? Live sessions end; demotions apply immediately.')"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="id" value="<?=$x['id']?>"><label class="muted">🎭 Role</label><div class="acinl"><select name="role"><?php foreach(['user','reseller','admin'] as $rr):?><option value="<?=$rr?>"<?=($x['role'] ?? '') === $rr ? ' selected' : ''?>><?=$rr?></option><?php endforeach;?></select><button class="btn small">Set</button></div></form><?php endif;?>
  <?php if(!$self):?><hr>
  <form method="post" action="/accounts/password" onsubmit="return confirm('Reset the password for <?=$u?>?\nA new one is generated and shown ONCE. Their panel login and FTP/system password both change, and they are logged out.')"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="id" value="<?=$x['id']?>"><button class="acitem" title="Generate a new password (shown once)">🔑 Reset password</button></form>
  <?php if(!empty($x['totp_enabled'])):?><form method="post" action="/accounts/2fa-reset" onsubmit="return confirm('Reset 2FA for <?=$u?>?\nUse this when they lost their authenticator. 2FA is switched off, they are logged out, and they set it up again after logging in with their password.')"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="id" value="<?=$x['id']?>"><button class="acitem" title="Lost authenticator: switch their 2FA off">📵 Reset 2FA</button></form><?php endif;?>
  <hr>
  <form method="post" action="/accounts/delete" onsubmit="return confirm('DELETE <?=$u?>?\nRemoves system user, sites, DNS, mailboxes, databases, cron. Backup files are kept.')"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="id" value="<?=$x['id']?>"><button class="acitem acdel">🗑 Delete account</button></form><?php endif;?>
</div></details>
</div></td></tr><?php endforeach;?>
<?php if(empty($users)):?><tr><td colspan="6" class="muted"><?= ($q ?? '') !== '' ? 'No accounts match that search.' : 'No accounts yet — create one above.' ?></td></tr><?php endif;?>
</tbody></table></div>
<?php $pagerBase = '/accounts?'.(($q ?? '') !== '' ? 'q='.urlencode($q).'&' : ''); $pagerLabel = 'accounts'; require __DIR__.'/_pager.php'; ?></div>
<script>
(function(){
  var nb=document.getElementById('acNewBtn'),nc=document.getElementById('acNew');
  function setNew(o){nc.hidden=!o;nb.setAttribute('aria-expanded',o?'true':'false');nb.textContent=o?'✕ Close':'＋ New account';try{localStorage.setItem('acNew',o?'1':'');}catch(e){}if(o)nc.querySelector('input[name=username]').focus();}
  nb.addEventListener('click',function(){setNew(nc.hidden);});
  try{if(localStorage.getItem('acNew'))setNew(true);}catch(e){}
  // "⋯" menus: fixed-positioned so the card's horizontal scroll can't clip them
  var menus=[].slice.call(document.querySelectorAll('details.acmore'));
  function place(d){var m=d.querySelector('.acmenu'),r=d.querySelector('summary').getBoundingClientRect(),w=m.offsetWidth,h=m.offsetHeight;
    var left=Math.max(8,Math.min(r.right-w,innerWidth-w-8)),top=r.bottom+6;if(top+h>innerHeight-8)top=Math.max(8,r.top-h-6);m.style.left=left+'px';m.style.top=top+'px';}
  function closeAll(except){menus.forEach(function(d){if(d!==except)d.open=false;});}
  menus.forEach(function(d){d.addEventListener('toggle',function(){if(d.open){closeAll(d);place(d);}});});
  document.addEventListener('click',function(e){if(!e.target.closest('details.acmore'))closeAll();});
  document.addEventListener('keydown',function(e){if(e.key==='Escape')closeAll();});
  // follow the button (page/card scroll, phone keyboard resizing) instead of closing mid-typing
  function follow(){menus.forEach(function(d){if(d.open)place(d);});}
  window.addEventListener('resize',follow);window.addEventListener('scroll',follow,true);
})();
</script>

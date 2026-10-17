<h2>📁 FTPS <small style="color:var(--muted)">Secure FTP over TLS — plaintext is refused</small></h2>
<div class="card" style="color:var(--muted)">Default login: <b><?=Helpers::e($u['username'])?></b> with your panel password. Extra logins below are jailed to your home. Connect with TLS/SSL enabled (port 21).</div>
<div class="card"><b>Login format:</b> extra logins are <code><?=Helpers::e($u['username'])?>_suffix</code> — e.g. <code><?=Helpers::e($u['username'])?>_shop</code>. Type the <b>full</b> login into your FTP client, not just the suffix.</div>
<div class="card"><form class="inline" method="post" action="/ftp/create">
<input type="hidden" name="csrf" value="<?=Helpers::csrf()?>">
<input name="ftp_user" placeholder="suffix (user_suffix)" required>
<input name="password" type="password" autocomplete="new-password" placeholder="password 8+" required>
<button class="btn">Create FTPS login</button></form></div>
<div class="card"><table><tr><th>Login</th><th>Homedir</th><th></th></tr>
<?php foreach($ftps as $f):?><tr><td><?=Helpers::e($f['ftp_user'])?></td><td><?=Helpers::e($f['homedir'])?></td>
<td style="white-space:nowrap"><form method="post" action="/ftp/reset" style="display:inline"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><input type="hidden" name="id" value="<?=$f['id']?>"><button class="btn small" title="Generate a new password (shown once)">Reset PW</button></form>
<form method="post" action="/ftp/delete" style="display:inline" onsubmit="return confirm('Delete FTPS login <?=Helpers::e($f['ftp_user'])?>?')"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><input type="hidden" name="id" value="<?=$f['id']?>"><button class="btn small danger">Delete</button></form></td></tr><?php endforeach;?>
<?php if(empty($ftps)):?><tr><td colspan="3" style="color:var(--muted)">No FTPS logins yet — create one above. Login format: youruser_suffix.</td></tr><?php endif;?></table>
<?php $pagerBase = '/ftp?'; $pagerLabel = 'logins'; require __DIR__.'/_pager.php'; ?></div>

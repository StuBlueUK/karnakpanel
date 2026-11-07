<h2>🔐 Two-factor authentication</h2>
<?php $pks = $passkeys ?? []; $nPk = count($pks); $on = $enabled || $nPk > 0; ?>
<div class="card"><p><?php if($on):?>✅ 2FA is <b>on</b>. After your password, logins need <?= $enabled && $nPk ? 'a passkey <b>or</b> an app code' : ($nPk ? 'a passkey' : 'an authenticator app code') ?>.<?php else:?>2FA is <b>off</b>: logins need only your password. Add a passkey or an authenticator app below — either one turns 2FA on.<?php endif;?></p></div>

<div class="card"><h3>🔑 Passkeys <small style="color:var(--muted)">fingerprint, face, screen lock or a security key — nothing to type, and fake login pages can't use them</small></h3>
<?php if($pks):?>
<table class="list"><thead><tr><th>Name</th><th>Kind</th><th>Added</th><th>Last used</th><th></th></tr></thead><tbody>
<?php foreach($pks as $k):?><tr>
<td><b><?=Helpers::e($k['name'])?></b><?php if(($pkRp ?? null) !== null && $k['rp_id'] !== $pkRp):?> <span class="pill warn" title="made for <?=Helpers::e($k['rp_id'])?>">other address</span><?php endif;?></td>
<td><?= !empty($k['synced']) ? '<span class="pill ok" title="Copied to your other devices by your password manager">synced</span>' : '<span class="pill" title="Lives on one device or security key only">this device only</span>' ?></td>
<td><?=Helpers::e(substr((string)$k['created_at'], 0, 10))?></td>
<td><?= $k['last_used_at'] ? Helpers::e(substr((string)$k['last_used_at'], 0, 16)) : '<span class="muted">never</span>' ?></td>
<td><?php if(empty($impersonating)):?><form class="inline" method="post" action="/2fa/passkey/delete" onsubmit="return confirm('Remove passkey <?=Helpers::e(addslashes($k['name']))?>?<?= $nPk === 1 && !$enabled ? '\\nIt is your only second factor: 2FA turns OFF.' : '' ?>')"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><input type="hidden" name="id" value="<?=(int)$k['id']?>"><input type="password" name="password" placeholder="Password" autocomplete="current-password" required style="max-width:130px"> <button class="btn small danger">Remove</button></form><?php endif;?></td>
</tr><?php endforeach;?></tbody></table>
<?php else:?><p class="muted">No passkeys yet.</p><?php endif;?>
<?php if(!empty($impersonating)):?><p class="muted">Passkeys can't be added or removed while logged in as another account.</p>
<?php elseif(empty($pkRp)):?><p class="muted">Passkeys work only at the panel's own address over HTTPS<?php if(!empty($pkHome)):?>: open <a href="<?=Helpers::e($pkHome)?>/2fa"><?=Helpers::e($pkHome)?></a> to add one<?php else:?> — an admin sets it under Services → Panel host<?php endif;?>.</p>
<?php else:?>
<form id="pk-add" class="inline"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><input name="name" maxlength="64" placeholder="Name, e.g. Work laptop" required> <input type="password" name="password" placeholder="Confirm password" autocomplete="current-password" required> <button class="btn">Add passkey</button> <span id="pk-msg" class="muted"></span></form>
<small class="muted">Tip: keep two — a synced passkey (Google Password Manager, iCloud Keychain, 1Password…) follows you to a new phone; a second device or the app below covers losing one.</small>
<script src="/assets/js/passkey.js?v=<?=filemtime(__DIR__.'/../../public/assets/js/passkey.js')?>"></script>
<?php endif;?></div>

<?php if($enabled):?>
<div class="card"><h3>📱 Authenticator app</h3><p>✅ On — you can log in with a 6-digit code from your app.</p>
<form method="post" action="/2fa/disable" onsubmit="return confirm('<?= $nPk ? 'Remove the authenticator app? Logins will use your passkeys only.' : 'Turn 2FA off? Password-only logins are weaker.' ?>')"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><input type="password" name="password" placeholder="Confirm password" autocomplete="current-password" required> <button class="btn danger"><?= $nPk ? 'Remove app' : 'Disable 2FA' ?></button></form></div>
<?php elseif($pending):?>
<div class="card"><h3>Step 2 — scan into your authenticator app</h3>
<div id="totpQr" style="background:#fff;display:inline-block;padding:12px;border-radius:8px"></div>
<script src="/assets/js/qrcode.min.js?v=<?=filemtime(__DIR__.'/../../public/assets/js/qrcode.min.js')?>"></script>
<script>
(function(){
  var qr = qrcode(0, 'M');
  qr.addData(<?=json_encode(Totp::otpauthUrl($pending,$username))?>);
  qr.make();
  document.getElementById('totpQr').innerHTML = qr.createImgTag(5, 8);
})();
</script>
<p>Manual entry key (no camera needed):</p>
<pre style="font-size:1.2em;letter-spacing:2px"><?=Helpers::e($pending)?></pre>
<p style="color:var(--muted)">Or add this URL manually: <code style="font-size:.8em"><?=Helpers::e(Totp::otpauthUrl($pending,$username))?></code></p>
<form method="post" action="/2fa/enable"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><input name="code" inputmode="numeric" autocomplete="one-time-code" placeholder="6-digit code" required maxlength="6"> <button class="btn">Verify &amp; enable</button></form></div>
<?php else:?>
<div class="card"><h3>📱 Authenticator app</h3><p>Not set up. With it, logins can use a 6-digit code from Google/Microsoft Authenticator, Authy, 1Password…<?= $nPk ? ' — a good backup for your passkeys.' : '' ?></p>
<form method="post" action="/2fa/setup"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><input type="password" name="password" placeholder="Confirm password" autocomplete="current-password" required> <button class="btn">Start setup</button></form></div>
<?php endif;?>
<div class="card"><h3>Locked out?</h3><p style="color:var(--muted)">An admin can reset 2FA from Accounts (logged; clears the app and every passkey). There are no printable backup codes by design — keep your authenticator backed up. If no admin can log in, root can run <code>karnakpanel 2fa-reset &lt;user&gt;</code> over SSH.</p></div>
<?php if(!empty($isAdmin)):?><div class="card"><h3>🛡️ Enforcement <small style="color:var(--muted)">admin setting — herd privileged logins to this page until they enable 2FA</small></h3>
<form class="inline" method="post" action="/2fa/enforce"<?php if(($required ?? '') !== '1'):?> onsubmit="return confirm('Require 2FA for ALL admin + reseller logins? Anyone without 2FA (possibly you) gets sent here until they enable it.')"<?php endif;?>><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>">
<label style="display:inline-flex;align-items:center;gap:6px;margin-right:8px"><input type="checkbox" name="on" value="1" <?=($required ?? '') === '1' ? 'checked' : ''?>> Require 2FA for admin + reseller accounts</label>
<button class="btn">Save</button></form>
<small style="color:var(--muted)">Status: <b><?=($required ?? '') === '1' ? 'enforcing' : 'opt-in'?></b> · plain users are never forced · lockout recovery: another admin resets via Accounts, or console/DB access.</small></div><?php endif;?>

<h2>🔐 Two-factor authentication</h2>
<?php if($enabled):?>
<div class="card"><p>✅ 2FA is <b>on</b> for this account. Logins need password + authenticator code.</p>
<form method="post" action="/2fa/disable" onsubmit="return confirm('Turn 2FA off? Password-only logins are weaker.')"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><input type="password" name="password" placeholder="Confirm password" autocomplete="current-password" required> <button class="btn danger">Disable 2FA</button></form></div>
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
<div class="card"><p>2FA is <b>off</b>. After enabling, every login needs a 6-digit code from your authenticator app (Google/Microsoft Authenticator, Authy, 1Password…).</p>
<form method="post" action="/2fa/setup"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><input type="password" name="password" placeholder="Confirm password" autocomplete="current-password" required> <button class="btn">Start setup</button></form></div>
<?php endif;?>
<div class="card"><h3>Locked out?</h3><p style="color:var(--muted)">An admin can reset 2FA from Accounts (logged). There are no printable backup codes by design — keep your authenticator backed up.</p></div>
<?php if(!empty($isAdmin)):?><div class="card"><h3>🛡️ Enforcement <small style="color:var(--muted)">admin setting — herd privileged logins to this page until they enable 2FA</small></h3>
<form class="inline" method="post" action="/2fa/enforce"<?php if(($required ?? '') !== '1'):?> onsubmit="return confirm('Require 2FA for ALL admin + reseller logins? Anyone without 2FA (possibly you) gets sent here until they enable it.')"<?php endif;?>><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>">
<label style="display:inline-flex;align-items:center;gap:6px;margin-right:8px"><input type="checkbox" name="on" value="1" <?=($required ?? '') === '1' ? 'checked' : ''?>> Require 2FA for admin + reseller accounts</label>
<button class="btn">Save</button></form>
<small style="color:var(--muted)">Status: <b><?=($required ?? '') === '1' ? 'enforcing' : 'opt-in'?></b> · plain users are never forced · lockout recovery: another admin resets via Accounts, or console/DB access.</small></div><?php endif;?>

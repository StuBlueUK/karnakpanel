<h2>🔐 Two-factor code</h2>
<div class="card"><p>Enter the 6-digit code from your authenticator app.</p>
<form method="post" action="/login/totp"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><input name="code" inputmode="numeric" autocomplete="one-time-code" placeholder="000000" required maxlength="6" autofocus style="max-width:200px"><br><br><button class="btn">Verify</button></form>
<p><form method="post" action="/logout" style="display:inline"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><button class="linkbtn">Cancel</button></form></p></div>

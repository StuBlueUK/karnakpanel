<h2>🔑 Change password</h2>
<div class="card" style="max-width:480px">
<form method="post" action="/password/update" style="display:grid;gap:10px">
<input type="hidden" name="csrf" value="<?=Helpers::csrf()?>">
<input name="current" type="password" placeholder="Current password" required autocomplete="current-password">
<input name="new" type="password" placeholder="New password (10+ chars)" required autocomplete="new-password">
<input name="confirm" type="password" placeholder="Confirm new password" required autocomplete="new-password">
<button class="btn">Change password</button></form>
<small style="color:var(--muted)">Panel login only — database/FTPS/mail passwords are managed on their own pages.</small></div>

<div class="login-card">
<img src="/assets/img/logo.svg?v=<?=filemtime(__DIR__.'/../../../public/assets/img/logo.svg')?>" width="64"><h1>KarnakPanel</h1>
<p style="color:var(--muted)">Linux Hosting Control — LiteSpeed · BIND · Exim · Dovecot</p>
<?php if(!empty($flash_err)):?><div class="alert err"><?=Helpers::e($flash_err)?></div><?php endif;?>
<form method="post" action="/login" style="display:grid;gap:10px">
<input type="hidden" name="csrf" value="<?=Helpers::csrf()?>">
<input name="username" placeholder="Username" autocomplete="username" required>
<input name="password" type="password" placeholder="Password" autocomplete="current-password" required>
<button class="btn">Log in ☁️</button>
</form>
</div>

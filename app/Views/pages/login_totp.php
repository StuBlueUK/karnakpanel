<h2>🔐 Second step</h2>
<?php $pkHere = (int)($pkHere ?? 0); $pkAll = (int)($pkAll ?? 0); ?>
<?php if($pkHere > 0):?>
<div class="card"><p>Use your passkey — fingerprint, face, screen lock or security key.</p>
<button type="button" class="btn" id="pk-login" data-csrf="<?=Helpers::e(Helpers::csrf())?>" autofocus>🔑 Use passkey</button> <span id="pk-msg" class="muted"></span>
<script src="/assets/js/passkey.js?v=<?=filemtime(__DIR__.'/../../../public/assets/js/passkey.js')?>"></script></div>
<?php elseif($pkAll > 0):?>
<div class="card"><p>🔑 Your passkeys work only at <?php if(!empty($pkHome)):?><a href="<?=Helpers::e($pkHome)?>/login"><?=Helpers::e($pkHome)?></a><?php else:?>the panel's own address<?php endif;?>.<?= empty($hasApp) ? ' Log in there, or ask an admin to reset your 2FA.' : ' Log in there, or use your app code below.' ?></p></div>
<?php endif;?>
<?php if(!empty($hasApp)):?>
<div class="card"><p><?= $pkHere > 0 ? 'Or enter' : 'Enter' ?> the 6-digit code from your authenticator app.</p>
<form method="post" action="/login/totp"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><input name="code" inputmode="numeric" autocomplete="one-time-code" placeholder="000000" required maxlength="6"<?= $pkHere > 0 ? '' : ' autofocus' ?> style="max-width:200px"><br><br><button class="btn">Verify</button></form></div>
<?php endif;?>
<div class="card"><form method="post" action="/logout" style="display:inline"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><button class="linkbtn">Cancel</button></form></div>

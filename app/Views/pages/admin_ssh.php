<h2>🔑 SSH <small style="color:var(--muted)">root shell access — keys first, passwords last</small></h2>
<?php $st = $st ?? []; ?>
<div class="card"><h3>Status</h3>
<table><tr><th>Password logins</th><td><b><?=Helpers::e($st['pw'] ?? '?')?></b></td></tr>
<tr><th>Root login</th><td><?=Helpers::e($st['root'] ?? '?')?></td></tr>
<tr><th>Deployed keys</th><td><?=Helpers::e($st['keys'] ?? '0')?><?php if(!empty($st['fps'])):?> <small style="color:var(--muted)">(<?=Helpers::e($st['fps'])?>)</small><?php endif;?></td></tr>
<tr><th>Panel-generated keypair</th><td><?=Helpers::e($st['keypair'] ?? 'no')?></td></tr></table>
<form class="inline" method="post" action="/admin/ssh-password"<?php if(($st['pw'] ?? '') !== 'no'):?> onsubmit="return confirm('Disable SSH password logins? Only key holders get in. Refused automatically if no key is deployed.')"<?php endif;?>><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><input type="hidden" name="mode" value="<?=($st['pw'] ?? '') === 'no' ? 'on' : 'off'?>"><button class="btn<?=($st['pw'] ?? '') === 'no' ? '' : ' danger'?>"><?=($st['pw'] ?? '') === 'no' ? 'Allow password logins' : 'Keys only (disable passwords)'?></button></form>
<small style="color:var(--muted)">Fail2ban guards brute force either way. Disabling is refused while zero keys are deployed — and always verify a key login in a second terminal BEFORE closing this session.</small></div>
<div class="card"><h3>➕ Add your public key <small style="color:var(--muted)">from your own machine — the safe path</small></h3>
<form method="post" action="/admin/ssh-addkey"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>">
<input name="key" placeholder="ssh-ed25519 AAAA… you@machine" required style="min-width:320px" maxlength="2048"> <button class="btn">Add key</button></form>
<small style="color:var(--muted)">Then: <code>ssh root@this-server</code> must log in without a password. Only then press “Keys only” above.</small></div>
<div class="card"><h3>🎲 Generate a keypair here <small style="color:var(--muted)">no passphrase — for owner/automation use</small></h3>
<form method="post" action="/admin/ssh-keygen" onsubmit="return confirm('Create a root keypair on this server? The private half is shown ONCE — save it immediately.')"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><button class="btn">Generate keypair</button></form>
<small style="color:var(--muted)">Refused if one already exists (never replaced blindly). Prefer your own passphrase-protected key? <code>ssh-keygen -t ed25519</code> on your machine, then paste the <code>.pub</code> above.</small></div>
<div class="card"><h3>📖 How keys work <small style="color:var(--muted)">60-second version</small></h3>
<pre style="white-space:pre-wrap;font-size:12px">On YOUR machine (once):
  ssh-keygen -t ed25519            # Enter = no passphrase, or set one
  cat ~/.ssh/id_ed25519.pub        # paste this above, or:
  ssh-copy-id root@this-server     # same thing over SSH

Then verify (keep this page open until it works):
  ssh root@this-server             # should skip the password prompt

Only then: press “Keys only” at the top.
Provider console stays as the break-glass if keys are ever lost.</pre></div>

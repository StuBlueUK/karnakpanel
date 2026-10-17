<h2>🧱 Firewall <small style="color:var(--muted)">UFW — status: <b><?=Helpers::e($active)?></b></small></h2>
<div class="card"><h3>Allow a port</h3>
<form class="inline" method="post" action="/admin/firewall-allow">
<input type="hidden" name="csrf" value="<?=Helpers::csrf()?>">
<input name="port" type="number" min="1" max="65535" placeholder="port" required style="max-width:120px">
<select name="proto"><option value="tcp">tcp</option><option value="udp">udp</option></select>
<button class="btn">Allow</button></form></div>
<div class="card"><h3>Deny a port <small style="color:var(--muted)">closed even if a service listens</small></h3>
<form class="inline" method="post" action="/admin/firewall-deny">
<input type="hidden" name="csrf" value="<?=Helpers::csrf()?>">
<input name="port" type="number" min="1" max="65535" placeholder="port (not 22)" required style="max-width:140px">
<select name="proto"><option value="tcp">tcp</option><option value="udp">udp</option></select>
<button class="btn danger">Deny</button></form></div>
<div class="card"><h3>Block an IP <small style="color:var(--muted)">all ports, both directions</small></h3>
<form class="inline" method="post" action="/admin/firewall-block" onsubmit="return confirm('Block this IP entirely?')">
<input type="hidden" name="csrf" value="<?=Helpers::csrf()?>">
<input name="ip" placeholder="203.0.113.45" required style="max-width:180px">
<button class="btn danger">Block IP</button></form>
<small style="color:var(--muted)">SSH (22) rules are undeletable — lockout protection lives in the script, not just the UI.</small></div>
<div class="card"><table><tr><th>#</th><th>To</th><th>Action</th><th>From</th><th></th></tr>
<?php foreach($rules as $r):?><tr><td><?=$r['n']?></td><td><code><?=Helpers::e($r['to'])?></code></td><td><?=Helpers::e($r['action'])?></td><td><?=Helpers::e($r['from'])?></td>
<td><form method="post" action="/admin/firewall-delete" style="display:inline" onsubmit="return confirm('Delete rule <?=$r['n']?> (<?=Helpers::e($r['to'])?>)? Remote users may lose access.')"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><input type="hidden" name="n" value="<?=$r['n']?>"><button class="btn small danger">Delete</button></form></td></tr><?php endforeach;?>
<?php if(empty($rules)):?><tr><td colspan="5" style="color:var(--muted)">No rules or firewall inactive.</td></tr><?php endif;?></table></div>

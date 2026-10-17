<h2>⚙️ Daemons <small style="color:var(--muted)">supervised apps — restarted on failure, started on boot</small></h2>
<div class="card"><form class="inline" method="post" action="/daemons/create">
<input type="hidden" name="csrf" value="<?=Helpers::csrf()?>">
<input name="name" placeholder="name (mybot)" required style="max-width:130px">
<input name="command" placeholder="/usr/bin/node server.js --port 3000" required style="min-width:280px">
<input name="workdir" placeholder="folder in home (empty = home)" style="max-width:190px">
<button class="btn">Create &amp; start</button></form>
<small style="color:var(--muted)">Command runs directly (no shell): binary must exist, workdir inside your home. Logs stream below per daemon.</small></div>
<div class="card"><table><tr><th>Name</th><th>Command</th><th>State</th><th></th></tr>
<?php foreach($daemons as $d):?><tr><td><?=Helpers::e($d['name'])?><?= $d['auto']==='enabled' ? ' <small style="color:var(--muted)">auto</small>' : '' ?></td>
<td><code style="word-break:break-all"><?=Helpers::e($d['command'])?></code></td>
<td><i class="dot <?=$d['state']==='active'?'active':'down'?>"></i> <?=Helpers::e($d['state'])?></td>
<td style="white-space:nowrap"><a class="btn small" href="/daemons/logs?id=<?=$d['id']?>">Logs</a>
<?php foreach(['restart'=>'↻','stop'=>'⏹','start'=>'▶'] as $op=>$ic):?><form method="post" action="/daemons/act" style="display:inline"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><input type="hidden" name="id" value="<?=$d['id']?>"><input type="hidden" name="op" value="<?=$op?>"><button class="btn small" title="<?=$op?>"><?=$ic?></button></form><?php endforeach;?>
<form method="post" action="/daemons/act" style="display:inline"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><input type="hidden" name="id" value="<?=$d['id']?>"><input type="hidden" name="op" value="<?= $d['auto']==='enabled'?'off':'on' ?>"><button class="btn small" title="toggle autostart"><?= $d['auto']==='enabled'?'🔕':'🔔' ?></button></form>
<form method="post" action="/daemons/delete" style="display:inline" onsubmit="return confirm('Delete daemon <?=Helpers::e($d['name'])?>? Stops it and removes autostart.')"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><input type="hidden" name="id" value="<?=$d['id']?>"><button class="btn small danger">Del</button></form></td></tr><?php endforeach;?>
<?php if(empty($daemons)):?><tr><td colspan="4" style="color:var(--muted)">No supervised apps yet — create one above. (System processes live under Top processes on the dashboard.)</td></tr><?php endif;?></table></div>

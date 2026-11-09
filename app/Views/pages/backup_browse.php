<h2>🔍 Backup: <?=Helpers::e($b['filename'])?> <small style="color:var(--muted)">max 300 entries</small></h2>
<div class="card"><form method="get" action="/backups/browse" class="inline">
<input type="hidden" name="id" value="<?=$b['id']?>">
<input name="q" value="<?=Helpers::e($q)?>" placeholder="filter path (e.g. public_html)"> <button class="btn small">Filter</button></form></div>
<div class="card"><table><tr><th>Path in backup</th><th></th></tr>
<?php foreach($members as $m):?><tr><td style="word-break:break-all"><code><?=Helpers::e($m)?></code></td>
<td><?php if(!str_ends_with($m, '/')):?><form method="post" action="/backups/file" style="display:inline" onsubmit="return confirm('Restore this file? It overwrites the live copy.')"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><input type="hidden" name="id" value="<?=$b['id']?>"><input type="hidden" name="member" value="<?=Helpers::e($m)?>"><button class="btn small">Restore file</button></form><?php endif;?></td></tr><?php endforeach;?>
<?php if(empty($members)):?><tr><td colspan="2" style="color:var(--muted)">Nothing listed.</td></tr><?php endif;?></table></div>
<p><a href="/backups">← Back to backups</a></p>

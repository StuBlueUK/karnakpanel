<h2>👥 Active sessions <small style="color:var(--muted)">admin — killing logs them out on next click</small></h2>
<div class="card"><table><tr><th>User</th><th>Role</th><th>Last activity</th><th></th></tr>
<?php foreach($list as $s):?><tr><td><?=Helpers::e($s['user'])?></td><td><?=Helpers::e($s['role'])?></td><td><?=Helpers::e($s['active'])?></td>
<td><?php if($s['current']):?><span class="badge">you</span><?php else:?><form method="post" action="/admin/session-kill" style="display:inline" onsubmit="return confirm('Kill session for <?=Helpers::e($s['user'])?>?')"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><input type="hidden" name="sid" value="<?=Helpers::e($s['sid'])?>"><button class="btn small danger">Kill</button></form><?php endif;?></td></tr><?php endforeach;?>
<?php if(empty($list)):?><tr><td colspan="4" style="color:var(--muted)">No sessions.</td></tr><?php endif;?></table>
<?php if(!empty($anon)):?><small style="color:var(--muted)">Plus <?=(int)$anon?> not-logged-in visit(s) to the login page (browsers, uptime checks, bots) — no account access; they expire on their own after ~24 min idle.</small><?php endif;?></div>

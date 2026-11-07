<h2>🗄️ Databases <small style="color:var(--muted)">MariaDB</small>
<a class="btn small" href="<?=Helpers::e(Helpers::serviceUrl(8092, 'app.phpmyadmin_url'))?>" target="_blank" rel="noopener">🗄️ Open phpMyAdmin</a></h2>
<div class="card"><form class="inline" method="post" action="/databases/create">
<input type="hidden" name="csrf" value="<?=Helpers::csrf()?>">
<input name="name" placeholder="dbname suffix" required>
<input name="password" placeholder="password (blank=random)">
<button class="btn">Create DB</button></form>
<small>Host: localhost · Charset utf8mb4 · phpMyAdmin optional</small></div>
<div class="card"><h3>🔐 phpMyAdmin gate <small style="color:var(--muted)">password before the login screen</small></h3>
<form class="inline" method="post" action="/databases/gate">
<input type="hidden" name="csrf" value="<?=Helpers::csrf()?>">
<input name="gate_password" type="password" placeholder="new gate password (10+; blank removes gate)">
<button class="btn">Set gate password</button></form>
<small style="color:var(--muted)">Status: <b><?= $gate ? '🔒 gated — browsers must pass it first' : 'open (login screen public)' ?></b> · Submit empty to revoke. Each panel user has their own.</small></div>
<?php if($caps !== null): $capF = ['db_cap_conn_hour' => 'connections / hour', 'db_cap_queries_hour' => 'queries / hour', 'db_cap_updates_hour' => 'updates / hour', 'db_cap_user_conn' => 'at once'];?><div class="card"><h3>🚦 Database user limits <small style="color:var(--muted)">admin · every customer database user · 0 = no limit</small></h3>
<form class="inline" method="post" action="/databases/caps">
<input type="hidden" name="csrf" value="<?=Helpers::csrf()?>">
<?php foreach($capF as $k => $lbl):?><label><input name="<?=$k?>" value="<?=(int)$caps[$k]?>" inputmode="numeric" pattern="[0-9]{1,9}" size="9" required> <?=$lbl?></label> <?php endforeach;?>
<label><input type="checkbox" name="apply" value="1"> also apply to existing users</label>
<button class="btn">Save limits</button></form>
<small style="color:var(--muted)">Each PHP page view opens one connection, so connections / hour must cover peak traffic (36000 = 10 a second). Stops a compromised site hammering the shared MariaDB. Defaults: <?php $d = MysqlDb::CAP_DEFAULTS;?><?=number_format($d['db_cap_conn_hour'])?> connections, <?=number_format($d['db_cap_queries_hour'])?> queries and <?=number_format($d['db_cap_updates_hour'])?> updates an hour, <?=(int)$d['db_cap_user_conn']?> at once.</small></div>
<?php endif;?>
<div class="card"><table><tr><th>Database</th><th>User</th><th>Password</th><th></th></tr>
<?php foreach($dbs as $d):?><tr><td><?=Helpers::e($d['db_name'])?></td><td><?=Helpers::e($d['db_user'])?></td>
<td><form method="post" action="/databases/reset" style="display:flex;gap:6px;align-items:center"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><input type="hidden" name="id" value="<?=$d['id']?>"><button class="btn small">Reset password</button></form></td>
<td><form method="post" action="/databases/delete" onsubmit="return confirm('DELETE <?=Helpers::e($d['db_name'])?>?\nDrops the database and its user. This cannot be undone.')"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><input type="hidden" name="id" value="<?=$d['id']?>"><button class="btn small danger">Delete</button></form></td></tr><?php endforeach;?>
<?php if(empty($dbs)):?><tr><td colspan="4" style="color:var(--muted)">No databases yet — the wizard creates the MariaDB database + user + grants.</td></tr><?php endif;?></table>
<?php $pagerBase = '/databases?'; $pagerLabel = 'databases'; require __DIR__.'/_pager.php'; ?></div>

<h2>🔥 Abuse board <small style="color:var(--muted)">who is eating the server — snapshot + sustained alerts</small></h2>
<div class="card"><h3>Live CPU/MEM <small style="color:var(--muted)">top processes aggregated per user, refreshed every 5 min — alerts fire past 150% CPU or 25% RAM twice running</small> <button class="btn small" id="abuseRefresh" type="button">↻ Refresh</button> <small style="color:var(--muted)" id="abuseTs"></small></h3>
<table><tr><th>User</th><th>CPU%</th><th>MEM%</th><th>Procs</th><th>Top processes</th></tr>
<tbody id="abuseRows">
<?php foreach($rows as $r):?><tr><td><?=Helpers::e($r[0])?></td><td><?=$r[1]?></td><td><?=$r[2]?></td><td><?= (int)$r[3] ?></td><td><small style="word-break:break-all"><?=Helpers::e(str_replace('|', ' · ', trim($r[4] ?? '', '|')))?></small></td></tr><?php endforeach;?>
<?php if(empty($rows)):?><tr><td colspan="5" style="color:var(--muted)">No snapshot yet (checker runs every 5 min).</td></tr><?php endif;?></tbody></table></div>
<script>
document.getElementById('abuseRefresh').addEventListener('click', async function(){var b=this;b.disabled=true;b.textContent='…';
try{var r=await fetch('/admin/abuse-data',{credentials:'same-origin'});var d=await r.json();var tb=document.getElementById('abuseRows');tb.innerHTML='';
if(!d.rows.length)tb.innerHTML='<tr><td colspan="5" style="color:var(--muted)">No snapshot yet (checker runs every 5 min).</td></tr>';
d.rows.forEach(function(row){var tr=document.createElement('tr');[row[0],row[1],row[2],parseInt(row[3],10),(row[4]||'').replace(/\|/g,' · ').replace(/^\s*·\s*|\s*·\s*$/g,'')].forEach(function(c,i){var td=document.createElement('td');if(i===4){var s=document.createElement('small');s.style.wordBreak='break-all';s.textContent=c;td.appendChild(s);}else td.textContent=c;tr.appendChild(td);});tb.appendChild(tr);});
document.getElementById('abuseTs').textContent=d.snapshot?('snapshot '+d.snapshot):'';}catch(e){b.textContent='⚠ retry';b.disabled=false;return;}
b.textContent='↻ Refresh';b.disabled=false;});
</script>
<div class="card"><h3>Customers vs quotas</h3>
<table><tr><th>User</th><th>Disk</th><th>BW month</th><th>Sent today</th><th></th></tr>
<?php foreach($users as $x):?><tr><td><?=Helpers::e($x['username'])?> <small style="color:var(--muted)"><?=Helpers::e($x['status'])?></small></td>
<td><?=Helpers::e(Helpers::fmtMb((float)$x['disk_used_mb']))?> / <?=Helpers::e(Helpers::fmtMb((float)$x['disk_quota_mb']))?></td>
<td><?= $x['bw_month_mb'] === null ? '—' : Helpers::e($x['bw_month_mb'].' MB').' / '.(int)$x['bandwidth_quota_gb'].' GB' ?></td>
<td><?= ($x['sent_today'] ?? null) === null ? '—' : number_format((int)$x['sent_today']) ?></td>
<td><form method="post" action="/accounts/suspend" style="display:inline"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><input type="hidden" name="username" value="<?=Helpers::e($x['username'])?>"><button class="btn small" name="action" value="suspend" onclick="return confirm('Suspend <?=Helpers::e($x['username'])?>? Sites, mail and FTP stop until unsuspended.')">Suspend</button></form></td></tr><?php endforeach;?>
<?php if(empty($users)):?><tr><td colspan="5" style="color:var(--muted)">No customer accounts.</td></tr><?php endif;?></table>
<small style="color:var(--muted)">Guardrails already in place: per-user FPM worker caps, 256M per-script memory, niced pools, MySQL query/update/connection caps on new DBs.</small></div>

<h2>🧭 DNS <small style="color:var(--muted)">BIND9</small></h2>
<div class="card"><form method="get" action="/dns" class="inline">
<input name="domain" list="dns-domains" value="<?=Helpers::e($viewing['domain'] ?? '')?>" placeholder="type a domain…" style="min-width:240px" autocomplete="off">
<datalist id="dns-domains"><?php foreach($domains as $d):?><option value="<?=Helpers::e($d['domain'])?>"><?php endforeach;?></datalist>
<button class="btn small">Open zone</button><?php if(empty($domains)):?> <span style="color:var(--muted)">No domains yet — <a href="/domains">add a domain first</a>, then its BIND zone appears here.</span><?php endif;?></form></div>
<div class="card"><h3>Zones <small style="color:var(--muted)"><?=Helpers::e((string)($dtotal ?? 0))?> domains</small></h3>
<form method="get" action="/dns" class="inline" style="margin-bottom:6px">
<?php if(!empty($viewing)):?><input type="hidden" name="domain" value="<?=Helpers::e($viewing['domain'])?>"><?php endif;?>
<input name="lq" value="<?=Helpers::e($lq ?? '')?>" placeholder="filter domains" style="min-width:200px"> <button class="btn small">Filter</button><?php if(($lq ?? '')!==''):?> <a class="btn small" href="/dns<?=!empty($viewing)?'?domain='.urlencode($viewing['domain']):''?>">Clear</a><?php endif;?></form>
<div style="display:flex;gap:8px;flex-wrap:wrap">
<?php foreach($domlist ?? [] as $d):?><a class="btn small" href="/dns?domain=<?=urlencode($d['domain'])?><?=($lq ?? '')!==''?'&lq='.urlencode($lq):''?>"><?=Helpers::e($d['domain'])?></a><?php endforeach;?></div>
<div style="display:flex;gap:10px;align-items:center;margin-top:8px;color:var(--muted)">
<?php if(($dpg ?? 1) > 1):?><a class="btn small" href="/dns?<?=!empty($viewing)?'domain='.urlencode($viewing['domain']).'&':''?><?=($lq ?? '')!==''?'lq='.urlencode($lq).'&':''?>dpage=<?=($dpg-1)?>">« Prev</a><?php endif;?>
<span>Page <?=($dpg ?? 1)?> of <?=($dpages ?? 1)?> (<?=($dtotal ?? 0)?> domains)</span>
<?php if(($dpg ?? 1) < ($dpages ?? 1)):?><a class="btn small" href="/dns?<?=!empty($viewing)?'domain='.urlencode($viewing['domain']).'&':''?><?=($lq ?? '')!==''?'lq='.urlencode($lq).'&':''?>dpage=<?=($dpg+1)?>">Next »</a><?php endif;?>
</div></div>
<?php if(!empty($viewing) && !empty($parentZone)):?><div class="card"><h3>↪ <?=Helpers::e($viewing['domain'])?> has no zone of its own</h3>
<p>Its records live in the <b><?=Helpers::e($parentZone)?></b> zone<?php if(!empty($parentHosted)):?> — <a class="btn small" href="/dns?domain=<?=urlencode($parentZone)?>">open <?=Helpers::e($parentZone)?></a><?php else:?>, which isn't hosted on this panel: add/change them wherever <?=Helpers::e($parentZone)?>'s DNS is managed (registrar or DNS provider), or add <?=Helpers::e($parentZone)?> as a domain here and they're created automatically.<?php endif;?></p>
<small style="color:var(--muted)">Subdomains are a record in their parent zone, not a separate zone — a second zone would be ignored or contradict the parent.</small>
<?php if(!empty($templates)):?><h4 style="margin:12px 0 6px">⚡ Mail quick setup <small style="color:var(--muted)">written into <?=Helpers::e($parentZone)?> under this name</small></h4>
<div style="display:flex;gap:8px;flex-wrap:wrap">
<?php foreach($templates as $key=>$tp): $what = implode(', ', array_map(fn($x) => $x[0].' '.$x[1].' '.$x[2], $tp['rows'])); ?><form method="post" action="/dns/template" onsubmit="return confirm(<?=Helpers::e(json_encode($tp['label'].' ('.$parentZone."):\n".implode("\n", array_map(fn($x) => '  + '.$x[0].' '.$x[1].' '.$x[2], $tp['rows'])).(!empty($tp['mail']) ? "\n\nREPLACES the current MX and SPF records of ".$viewing['domain'].'.' : '').(!empty($tp['replace']) ? "\n\nReplaces the current ".$tp['replace'][0].' '.$tp['replace'][1].' record.' : '')."\n\nApply?"))?>)"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><input type="hidden" name="domain" value="<?=Helpers::e($viewing['domain'])?>"><input type="hidden" name="template" value="<?=Helpers::e($key)?>"><button class="btn small" title="<?=Helpers::e($what)?>"><?=Helpers::e($tp['label'])?></button></form><?php endforeach;?></div><?php endif;?></div>
<?php elseif(!empty($viewing)):?>
<?php if(!empty($nameservers)):?><div class="card"><h3>🌐 Nameservers <small style="color:var(--muted)">set these two at the domain's registrar</small></h3>
<p style="margin:4px 0"><code><?=Helpers::e($nameservers[0])?></code> &nbsp; <code><?=Helpers::e($nameservers[1])?></code></p>
<?php if(str_ends_with($nameservers[0], '.'.$viewing['domain'])):?><small style="color:var(--muted)">These live inside this zone, so the registrar also needs <b>glue</b> (ns1/ns2 → this server's IP) and the <code>ns1</code>/<code>ns2</code> A records below must stay.</small>
<?php else:?><small style="color:var(--muted)">Managed by the panel — the NS records are written into the zone automatically; no ns1/ns2 A records are needed here.</small><?php endif;?>
<?php if(($user['role'] ?? '')==='admin' && empty($glueOk) && !str_ends_with($nameservers[0], '.'.$viewing['domain'])):?><br><small style="color:var(--muted)">Admin: the nightly glue check hasn't seen ns1/ns2 of the panel host resolving to this server yet — until it does, these nameservers won't work at a registrar (setup checklist, glue step).</small><?php endif;?>
<?php if(!empty($nsSwitchTo)):?><form method="post" action="/dns/use-server-ns" style="margin-top:8px" onsubmit="return confirm('Switch <?=Helpers::e($viewing['domain'])?> to <?=Helpers::e($nsSwitchTo[0].' / '.$nsSwitchTo[1])?>? Removes the ns1/ns2 A records. Update the registrar to the same nameservers.')"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><input type="hidden" name="domain" value="<?=Helpers::e($viewing['domain'])?>">
<small style="color:var(--muted)">This zone uses its own per-domain nameservers (older default).</small> <button class="btn small">Use server nameservers (<?=Helpers::e($nsSwitchTo[0])?>)</button></form><?php endif;?>
<div style="margin-top:8px"><a class="btn small" href="/dns?domain=<?=urlencode($viewing['domain'])?>&check=1#dns-check">🔎 Check delegation</a> <small style="color:var(--muted)">asks public DNS whether the registrar really points here</small></div>
<?php if(is_array($check ?? null)):?><div id="dns-check" style="margin-top:8px"><?php if(isset($check['error'])):?><span class="pill err">check failed</span> <small><?=Helpers::e($check['error'])?></small>
<?php else: $nm = $check['ns_match'] ?? 'unknown'; $sip = $check['server_ip'] ?? ''; ?>
<table><tr><th>Check</th><th>Seen publicly</th><th></th></tr>
<tr><td>Nameservers</td><td><code><?=Helpers::e(str_replace(',', ', ', $check['public_ns'] ?? ''))?></code></td><td><?=$nm==='yes'?'<span class="pill">matches</span>':($nm==='no'?'<span class="pill err">registrar points elsewhere</span>':'<span class="pill err">no answer yet</span>')?></td></tr>
<?php foreach(['ns1_a'=>$nameservers[0], 'ns2_a'=>$nameservers[1], 'public_a'=>$viewing['domain']] as $k=>$label): $v = $check[$k] ?? ''; ?><tr><td><code><?=Helpers::e($label)?></code> A</td><td><code><?=Helpers::e($v !== '' ? $v : '—')?></code></td><td><?=($v !== '' && $v === $sip)?'<span class="pill">this server</span>':($v === '' ? '<span class="pill err">no answer</span>' : '<span class="pill err">other IP</span>')?></td></tr><?php endforeach;?>
<tr><td>MX</td><td colspan="2"><code><?=Helpers::e(str_replace(',', ', ', ($check['public_mx'] ?? '') ?: '—'))?></code></td></tr>
</table><small style="color:var(--muted)">Registrar changes can take up to 48 h to show. "Other IP" on the domain itself is fine if the site is hosted elsewhere on purpose.</small><?php endif;?></div><?php endif;?>
</div><?php endif;?>
<div class="card"><h3>Records: <?=Helpers::e($viewing['domain'])?></h3>
<form method="get" action="/dns" class="inline" style="margin-bottom:6px"><input type="hidden" name="domain" value="<?=Helpers::e($viewing['domain'])?>">
<input name="rq" value="<?=Helpers::e($rq ?? '')?>" placeholder="search host or value" style="min-width:200px"> <button class="btn small">Search</button><?php if(($rq ?? '')!==''):?> <a class="btn small" href="/dns?domain=<?=urlencode($viewing['domain'])?>">Clear</a><?php endif;?></form>
<?php $types = array_values(array_unique(array_map(fn($r) => $r['type'], $records))); sort($types); if(count($types) > 1):?><div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:6px"><a href="#" class="btn small dns-tf active" data-type="">All</a><?php foreach($types as $t):?><a href="#" class="btn small dns-tf" data-type="<?=Helpers::e($t)?>"><?=Helpers::e($t)?></a><?php endforeach;?></div><?php endif;?>
<table id="dns-rows"><tr><th>Type</th><th>Host</th><th>Value</th><th>Prio</th><th>TTL</th><th></th></tr>
<?php if(($rq ?? '')==='' && ($pg ?? 1)===1): foreach($nameservers ?? [] as $ns):?><tr style="color:var(--muted)" data-type="NS">
<td><span class="badge">NS</span></td><td><code>@</code></td><td><?=Helpers::e($ns)?></td><td></td><td>3600</td><td><small>managed</small></td></tr>
<?php endforeach; endif;?>
<?php foreach($records as $r): if($r['type']==='NS' && $r['host']==='@' && in_array(rtrim(strtolower($r['value']),'.'), $nameservers ?? [], true)) continue; // older seeds stored the managed pair too
$prot = $protected[$r['type'].'|'.strtolower($r['host'])] ?? null; ?><tr data-type="<?=Helpers::e($r['type'])?>">
<td><span class="badge"><?=Helpers::e($r['type'])?></span></td>
<td><code><?=Helpers::e($r['host'])?></code><?php if($prot):?> <span class="pill" title="Removing or changing this breaks <?=$prot==='panel host'?'access to the panel':'every zone that uses these nameservers'?>"><?=Helpers::e($prot)?></span><?php endif;?></td>
<td style="word-break:break-all"><?=Helpers::e($r['value'])?></td>
<td><?=$r['priority']?></td><td><?=$r['ttl']?></td>
<td style="white-space:nowrap"><button class="btn small dns-edit" data-id="<?=$r['id']?>" data-type="<?=Helpers::e($r['type'])?>" data-host="<?=Helpers::e($r['host'])?>" data-value="<?=Helpers::e($r['value'])?>" data-priority="<?=$r['priority']?>" data-ttl="<?=$r['ttl']?>">Edit</button>
<form method="post" action="/dns/delete" style="display:inline" onsubmit="return confirm('<?=$prot ? Helpers::e('WARNING: '.$r['type'].' '.$r['host'].' is '.$prot.' — deleting it breaks '.($prot==='panel host'?'access to the panel':'every zone using these nameservers').'. Delete anyway?') : Helpers::e('Delete '.$r['type'].' '.$r['host'].'?')?>')"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><input type="hidden" name="id" value="<?=$r['id']?>"><button class="btn small danger">Del</button></form></td></tr>
<?php endforeach;?></table>
<?php if(empty($records)):?><p style="color:var(--muted)">No records in panel DB yet — add some below, import from disk, or reset to default.</p><?php endif;?>
<?php $pagerBase = '/dns?domain='.urlencode($viewing['domain']).(($rq ?? '') !== '' ? '&rq='.urlencode($rq) : '').'&'; $pagerLabel = 'records'; require __DIR__.'/_pager.php'; ?></div>
<div class="card"><h3 id="dns-form-title">Add record</h3>
<form class="inline" id="dns-record-form" method="post" action="/dns/add">
<input type="hidden" name="csrf" value="<?=Helpers::csrf()?>">
<input type="hidden" name="domain" value="<?=Helpers::e($viewing['domain'])?>">
<input type="hidden" name="id" id="dns-f-id" value="">
<select name="type" id="dns-f-type"><option>A</option><option>AAAA</option><option>CNAME</option><option>MX</option><option>TXT</option><option>NS</option><option>CAA</option><option>SRV</option><option>SSHFP</option></select>
<input name="host" id="dns-f-host" placeholder="@ / www / mail" required style="max-width:150px">
<input name="value" id="dns-f-value" placeholder="1.2.3.4 / host / text — CAA: 0 issue letsencrypt.org · SRV: weight port target · SSHFP: alg type hex" required style="min-width:280px">
<input name="priority" id="dns-f-prio" type="number" min="0" max="65535" value="10" title="MX priority" style="max-width:80px">
<input name="ttl" id="dns-f-ttl" type="number" min="60" value="3600" style="max-width:90px">
<button class="btn" id="dns-f-submit">Add & apply</button>
<a href="#" id="dns-f-cancel" class="btn small" style="display:none">Cancel</a></form>
<small id="dns-f-hint" style="color:var(--muted)"></small> <small style="color:var(--muted)">· host <code>@</code> = the domain itself, <code>*</code> = wildcard</small></div>
<div class="card"><h3>⚡ Quick setup <small style="color:var(--muted)">common provider records in one click — validated + applied together, rolled back if BIND refuses</small></h3>
<div style="display:flex;gap:8px;flex-wrap:wrap">
<?php foreach($templates ?? [] as $key=>$tp): $what = implode(', ', array_map(fn($x) => $x[0].' '.$x[1].' '.$x[2], $tp['rows'])); ?><form method="post" action="/dns/template" onsubmit="return confirm(<?=Helpers::e(json_encode($tp['label'].":
".implode("
", array_map(fn($x) => '  + '.$x[0].' '.$x[1].' '.$x[2], $tp['rows'])).(!empty($tp['mail']) ? "

REPLACES the current MX and SPF records." : '').(!empty($tp['replace']) ? "

Replaces the current ".$tp['replace'][0].' '.$tp['replace'][1].' record.' : '').($key==='m365' ? "

Check the MX value against your Microsoft 365 admin centre." : '')."

Apply?"))?>)"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><input type="hidden" name="domain" value="<?=Helpers::e($viewing['domain'])?>"><input type="hidden" name="template" value="<?=Helpers::e($key)?>"><button class="btn small" title="<?=Helpers::e($what)?>"><?=Helpers::e($tp['label'])?></button></form><?php endforeach;?></div>
<small style="color:var(--muted)">Moving mail to Google/Microsoft? Also set Mail to 📮 Remote on the Domains page so this server stops treating the domain's mail as local.</small></div>
<div class="card" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
<form method="post" action="/dns/reset" onsubmit="return confirm('Reset <?=Helpers::e($viewing['domain'])?> to KarnakPanel defaults? All custom records go.')"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><input type="hidden" name="domain" value="<?=Helpers::e($viewing['domain'])?>"><button class="btn">↺ Reset to default</button></form>
<form method="post" action="/dns/import"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><input type="hidden" name="domain" value="<?=Helpers::e($viewing['domain'])?>"><button class="btn small">⇪ Import from disk</button></form>
<a class="btn small" href="/dns/export?domain=<?=urlencode($viewing['domain'])?>">⇩ Export zone file</a>
<small style="color:var(--muted)">Every change validates + reloads BIND immediately. Invalid zones are rejected, current zone kept.</small></div>
<div class="card"><h3>📋 Import records <small style="color:var(--muted)">paste a zone file / export from Cloudflare, cPanel, another BIND server</small></h3>
<form method="post" action="/dns/import"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><input type="hidden" name="domain" value="<?=Helpers::e($viewing['domain'])?>">
<textarea name="zone" rows="6" style="width:100%;font-family:monospace;font-size:12px" placeholder="www.<?=Helpers::e($viewing['domain'])?>. 3600 IN A 192.0.2.10&#10;@ IN MX 10 mail.<?=Helpers::e($viewing['domain'])?>.&#10;_dmarc IN TXT &quot;v=DMARC1; p=none&quot;"></textarea>
<button class="btn small">Import & apply</button> <small style="color:var(--muted)">Adds new records only — duplicates, SOA, the apex NS (managed by the panel) and other domains' names are skipped. All-or-nothing: if BIND refuses the result, nothing is kept.</small></form></div>
<?php if(($user['role'] ?? '') === 'admin'):?><div class="card"><h3>🔁 Secondary nameservers <small style="color:var(--muted)">external BIND secondaries allowed zone transfers + notified on change</small></h3>
<form class="inline" method="post" action="/dns/secondary"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><input type="hidden" name="domain" value="<?=Helpers::e($viewing['domain'])?>">
<input name="secondaries" value="<?=Helpers::e($viewing['secondary_ns'] ?? '')?>" placeholder="203.0.113.7, 198.51.100.9 (blank = primary only)" style="min-width:260px">
<button class="btn">Set secondaries</button></form>
<small style="color:var(--muted)">Current: <b><?=Helpers::e(($viewing['secondary_ns'] ?? '') !== '' ? $viewing['secondary_ns'] : 'primary only')?></b> · Secondary box setup (BIND secondary stanza + registrar glue) is documented, not managed here.</small></div><?php endif;?>
<div class="card"><h3>🔏 DNSSEC <small style="color:var(--muted)">signed responses — give the DS to your registrar to complete the chain</small></h3>
<?php if(!empty($dnssecSigned)):?><p>✅ <b>Signed.</b> Keys live in <code>/etc/bind/keys</code> and are reused on re-enable, so this DS stays valid:</p>
<pre style="white-space:pre-wrap;overflow-wrap:anywhere;font-size:12px"><?=Helpers::e($dnssecDs ?? '')?></pre>
<form method="post" action="/dns/dnssec-disable" onsubmit="return confirm('Stop signing <?=Helpers::e($viewing['domain'])?>? Validators will treat it as unsigned again.')"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><input type="hidden" name="domain" value="<?=Helpers::e($viewing['domain'])?>"><button class="btn small danger">Disable signing</button></form>
<?php else:?><p style="color:var(--muted)">Unsigned — resolvers get plain answers.</p>
<form method="post" action="/dns/dnssec-enable"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><input type="hidden" name="domain" value="<?=Helpers::e($viewing['domain'])?>"><button class="btn small">Sign zone</button></form><?php endif;?></div>
<div class="card"><h3>Live zone file <small style="color:var(--muted)">read-only preview</small></h3>
<pre style="white-space:pre-wrap;overflow-wrap:anywhere;font-size:12px"><?=Helpers::e($zone ?? '')?></pre></div>
<?php endif;?>

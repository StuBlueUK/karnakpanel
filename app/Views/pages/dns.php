<?php $csrf = Helpers::csrf(); $isAdmin = ($user['role'] ?? '') === 'admin';
$vd = $viewing['domain'] ?? ''; $keep = ($lq ?? '') !== '' ? '&lq='.urlencode($lq) : '';
$dnPage = function (int $p) use ($vd, $lq) { return '/dns?'.($vd !== '' ? 'domain='.urlencode($vd).'&' : '').(($lq ?? '') !== '' ? 'lq='.urlencode($lq).'&' : '').'dpage='.$p; };
$hid = '<input type="hidden" name="csrf" value="'.$csrf.'"><input type="hidden" name="domain" value="'.Helpers::e($vd).'">'; ?>
<div class="fwhead"><h2>🧭 DNS</h2><span class="pill"><?=(int)($dtotal ?? 0)?> zone<?=(int)($dtotal ?? 0) === 1 ? '' : 's'?></span><small class="muted">BIND9 · every change is validated and live at once</small></div>
<?php if(empty($domains) && ($lq ?? '') === ''):?><div class="card"><p class="muted" style="margin:0">No domains yet — <a href="/domains">add a domain first</a>, then its DNS zone appears here.</p></div><?php else:?>
<div class="dnwrap<?= $vd !== '' ? ' viewing' : '' ?>">

<aside class="card dnlist">
<form method="get" action="/dns" class="dnfind"><?php if($vd !== ''):?><input type="hidden" name="domain" value="<?=Helpers::e($vd)?>"><?php endif;?>
<input type="search" name="lq" id="dnFilter" value="<?=Helpers::e($lq ?? '')?>" placeholder="🔍 find a zone…" autocomplete="off" aria-label="Find a zone"><?php if(($lq ?? '') !== ''):?><a class="btn small ghost" href="/dns<?= $vd !== '' ? '?domain='.urlencode($vd) : '' ?>" title="Clear search">✕</a><?php endif;?></form>
<nav class="dnzones" id="dnZones">
<?php foreach($domlist ?? [] as $d): $n = $rcount[(int)$d['id']] ?? 0; $on = strtolower($d['domain']) === strtolower($vd); ?>
<a class="dnzone<?= $on ? ' on' : '' ?><?= isset($lparent[(int)$d['id']]) ? ' child' : '' ?>" href="/dns?domain=<?=urlencode($d['domain'])?><?=$keep?>" data-name="<?=Helpers::e(strtolower($d['domain']))?>"<?= $on ? ' aria-current="page"' : '' ?>><span class="nm"><?=Helpers::e($d['domain'])?></span><small class="muted"><?php if($isAdmin && !empty($d['username'])):?><?=Helpers::e($d['username'])?> · <?php endif;?><?php if(isset($lparent[(int)$d['id']])):?>↪ in <?=Helpers::e($lparent[(int)$d['id']])?><?php else:?><?=$n?> record<?=$n === 1 ? '' : 's'?><?php endif;?></small></a>
<?php endforeach;?>
<?php if(empty($domlist)):?><p class="muted dnnone">No zones match “<?=Helpers::e($lq ?? '')?>”.</p><?php endif;?>
<p class="muted dnnone" id="dnNoHit" hidden>No zone on this page matches — press Enter to search all.</p>
</nav>
<?php if(($dpages ?? 1) > 1):?><div class="dnpager"><?php if($dpg > 1):?><a class="btn small ghost" href="<?=Helpers::e($dnPage($dpg - 1))?>">‹</a><?php else:?><span></span><?php endif;?><span class="muted">page <?=$dpg?> of <?=$dpages?></span><?php if($dpg < $dpages):?><a class="btn small ghost" href="<?=Helpers::e($dnPage($dpg + 1))?>">›</a><?php else:?><span></span><?php endif;?></div><?php endif;?>
</aside>

<section class="dnmain">
<?php if($vd === ''):?>
<div class="card dnempty"><div class="ic">🧭</div><h3>Pick a zone</h3><p class="muted">Choose a domain on the left to see and edit its DNS records, set up mail providers in one click, check the registrar's nameservers, or sign it with DNSSEC.</p>
<p class="muted"><small>Subdomains don't get their own zone — their records live in the parent domain's zone.</small></p></div>

<?php elseif(!empty($parentZone)):?>
<div class="card"><div class="dnzhead"><div><h3>↪ <?=Helpers::e($vd)?></h3><small class="muted">has no zone of its own</small></div></div>
<p>Its records live in the <b><?=Helpers::e($parentZone)?></b> zone<?php if(!empty($parentHosted)):?> — <a class="btn small" href="/dns?domain=<?=urlencode($parentZone)?>">open <?=Helpers::e($parentZone)?></a><?php else:?>, which isn't hosted on this panel: add/change them wherever <?=Helpers::e($parentZone)?>'s DNS is managed (registrar or DNS provider), or add <?=Helpers::e($parentZone)?> as a domain here and they're created automatically.<?php endif;?></p>
<small class="muted">Subdomains are a record in their parent zone, not a separate zone — a second zone would be ignored or contradict the parent.</small>
<?php if(!empty($templates)):?><h4 style="margin:16px 0 8px">⚡ Mail quick setup <small class="muted">written into <?=Helpers::e($parentZone)?> under this name</small></h4>
<div class="dntpls">
<?php foreach($templates as $key=>$tp): $what = implode(', ', array_map(fn($x) => $x[0].' '.$x[1].' '.$x[2], $tp['rows'])); ?><form method="post" action="/dns/template" onsubmit="return confirm(<?=Helpers::e(json_encode($tp['label'].' ('.$parentZone."):\n".implode("\n", array_map(fn($x) => '  + '.$x[0].' '.$x[1].' '.$x[2], $tp['rows'])).(!empty($tp['mail']) ? "\n\nREPLACES the current MX and SPF records of ".$vd.'.' : '').(!empty($tp['replace']) ? "\n\nReplaces the current ".$tp['replace'][0].' '.$tp['replace'][1].' record.' : '')."\n\nApply?"))?>)"><?=$hid?><input type="hidden" name="template" value="<?=Helpers::e($key)?>"><button class="dntpl" title="<?=Helpers::e($what)?>"><b><?=Helpers::e($tp['label'])?></b><small><?=count($tp['rows'])?> record<?=count($tp['rows']) === 1 ? '' : 's'?></small></button></form><?php endforeach;?></div><?php endif;?></div>

<?php else: $selfNs = !empty($nameservers) && str_ends_with($nameservers[0], '.'.$vd); $cnt = (int)($total ?? 0) + (($rq ?? '') === '' ? count($nameservers ?? []) : 0); ?>
<div class="card dnzone-card">
<div class="dnzhead"><div><h3><?=Helpers::e($vd)?></h3>
<div class="dnchips"><span class="pill mini"><?=$cnt?> record<?=$cnt === 1 ? '' : 's'?></span><?php if(!empty($dnssecSigned)):?><span class="pill mini acok">🔏 DNSSEC signed</span><?php else:?><span class="pill mini">DNSSEC off</span><?php endif;?><?php if(!empty($nameservers)):?><span class="muted dnns">NS <code><?=Helpers::e($nameservers[0])?></code> <code><?=Helpers::e($nameservers[1] ?? '')?></code></span><?php endif;?></div></div>
<a class="btn small ghost" href="/dns/export?domain=<?=urlencode($vd)?>" title="Download the BIND zone file">⇩ Export</a></div>

<div class="dntabs" role="tablist">
<?php foreach(['records' => '📄 Records', 'setup' => '⚡ Quick setup', 'ns' => '🌐 Nameservers', 'import' => '⇅ Import / reset', 'security' => '🔏 Security', 'zone' => '🗒 Zone file'] as $k => $lbl):?><button type="button" class="dntab" role="tab" data-tab="<?=$k?>" aria-controls="dnp-<?=$k?>"><?=$lbl?></button><?php endforeach;?>
</div>
<?php if((int)($viewing['ns_external'] ?? 0) === 1):?><div class="alert warn" style="margin:10px 0">🌐 <b>Your nameservers are with your domain registrar</b> (<?=Helpers::e(str_replace(',', ', ', (string)($viewing['ns_public'] ?? '')))?>), not this server — the records below are only this server's copy and the internet doesn't see them. Add or change records at your registrar, or set the nameservers in the 🌐 Nameservers tab there to manage DNS here. <small class="muted">checked <?=Helpers::e(substr((string)($viewing['ns_checked_at'] ?? ''), 0, 16))?></small></div><?php endif;?>

<div class="dnpanel" id="dnp-records" role="tabpanel">
<div class="dnbar"><form method="get" action="/dns" class="dnrq"><input type="hidden" name="domain" value="<?=Helpers::e($vd)?>"><input type="search" name="rq" value="<?=Helpers::e($rq ?? '')?>" placeholder="search host or value"><?php if(($rq ?? '')!==''):?><a class="btn small ghost" href="/dns?domain=<?=urlencode($vd)?>">Clear</a><?php endif;?></form>
<?php $types = array_values(array_unique(array_map(fn($r) => $r['type'], $records))); sort($types); if(count($types) > 1):?><div class="dntypes"><a href="#" class="fwchip dns-tf active" data-type="">All</a><?php foreach($types as $t):?><a href="#" class="fwchip dns-tf" data-type="<?=Helpers::e($t)?>"><?=Helpers::e($t)?></a><?php endforeach;?></div><?php endif;?>
<a href="#dns-record-form" class="btn small dnaddbtn" id="dnAddBtn">＋ Add record</a></div>
<div class="tablewrap"><table id="dns-rows" class="dnrec"><thead><tr><th>Type</th><th>Host</th><th>Value</th><th>TTL</th><th></th></tr></thead><tbody>
<?php if(($rq ?? '')==='' && ($pg ?? 1)===1): foreach($nameservers ?? [] as $ns):?><tr class="dnmanaged" data-type="NS">
<td><span class="dnt t-NS">NS</span></td><td><code>@</code></td><td class="dnval"><?=Helpers::e($ns)?></td><td class="muted">3600</td><td class="acr"><small class="muted" title="Written by the panel from the Nameservers tab">managed</small></td></tr>
<?php endforeach; endif;?>
<?php foreach($records as $r): if($r['type']==='NS' && $r['host']==='@' && in_array(rtrim(strtolower($r['value']),'.'), $nameservers ?? [], true)) continue; // older seeds stored the managed pair too
$prot = $protected[$r['type'].'|'.strtolower($r['host'])] ?? null; ?><tr data-type="<?=Helpers::e($r['type'])?>">
<td><span class="dnt t-<?=Helpers::e($r['type'])?>"><?=Helpers::e($r['type'])?></span></td>
<td><code><?=Helpers::e($r['host'])?></code><?php if($prot):?> <span class="pill mini warn" title="Removing or changing this breaks <?=$prot==='panel host'?'access to the panel':'every zone that uses these nameservers'?>"><?=Helpers::e($prot)?></span><?php endif;?></td>
<td class="dnval"><div class="dnv"><?php if(in_array($r['type'], ['MX','SRV'], true)):?><span class="muted" title="priority">[<?=(int)$r['priority']?>]</span> <?php endif;?><?=Helpers::e($r['value'])?></div></td>
<td class="muted"><?=(int)$r['ttl']?></td>
<td class="acr"><div class="acbtns"><button class="btn small ghost dns-edit" data-id="<?=$r['id']?>" data-type="<?=Helpers::e($r['type'])?>" data-host="<?=Helpers::e($r['host'])?>" data-value="<?=Helpers::e($r['value'])?>" data-priority="<?=$r['priority']?>" data-ttl="<?=$r['ttl']?>" title="Edit">✎ Edit</button>
<form method="post" action="/dns/delete" onsubmit="return confirm('<?=$prot ? Helpers::e('WARNING: '.$r['type'].' '.$r['host'].' is '.$prot.' — deleting it breaks '.($prot==='panel host'?'access to the panel':'every zone using these nameservers').'. Delete anyway?') : Helpers::e('Delete '.$r['type'].' '.$r['host'].'?')?>')"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="id" value="<?=$r['id']?>"><button class="btn small ghost dndel" title="Delete <?=Helpers::e($r['type'].' '.$r['host'])?>" aria-label="Delete">🗑</button></form></div></td></tr>
<?php endforeach;?></tbody></table></div>
<?php if(empty($records)):?><p class="muted"><?= ($rq ?? '') !== '' ? 'No records match that search.' : 'No records in the panel yet — add one below, use Quick setup, or Import / reset.' ?></p><?php endif;?>
<?php $pagerBase = '/dns?domain='.urlencode($vd).(($rq ?? '') !== '' ? '&rq='.urlencode($rq) : '').'&'; $pagerLabel = 'records'; require __DIR__.'/_pager.php'; ?>
<div class="dnform"><h4 id="dns-form-title">Add record</h4>
<form id="dns-record-form" method="post" action="/dns/add">
<?=$hid?><input type="hidden" name="id" id="dns-f-id" value="">
<label>Type<select name="type" id="dns-f-type"><option>A</option><option>AAAA</option><option>CNAME</option><option>MX</option><option>TXT</option><option>NS</option><option>CAA</option><option>SRV</option><option>SSHFP</option></select></label>
<label>Host<input name="host" id="dns-f-host" placeholder="@ / www / mail" required></label>
<label class="wide">Value<input name="value" id="dns-f-value" placeholder="1.2.3.4 / host / text" required></label>
<label>Priority<input name="priority" id="dns-f-prio" type="number" min="0" max="65535" value="10" title="MX priority"></label>
<label>TTL<input name="ttl" id="dns-f-ttl" type="number" min="60" value="3600"></label>
<div class="dnfbtns"><button class="btn" id="dns-f-submit">Add & apply</button><a href="#" id="dns-f-cancel" class="btn small ghost" style="display:none">Cancel</a></div></form>
<small id="dns-f-hint" class="muted"></small> <small class="muted">· host <code>@</code> = the domain itself, <code>*</code> = wildcard</small></div>
</div>

<div class="dnpanel" id="dnp-setup" role="tabpanel">
<p class="muted" style="margin-top:0">Common provider records in one click — validated and applied together, rolled back if BIND refuses. Hover a button to see exactly what it writes.</p>
<div class="dntpls">
<?php foreach($templates ?? [] as $key=>$tp): $what = implode(', ', array_map(fn($x) => $x[0].' '.$x[1].' '.$x[2], $tp['rows'])); ?><form method="post" action="/dns/template" onsubmit="return confirm(<?=Helpers::e(json_encode($tp['label'].":
".implode("
", array_map(fn($x) => '  + '.$x[0].' '.$x[1].' '.$x[2], $tp['rows'])).(!empty($tp['mail']) ? "

REPLACES the current MX and SPF records." : '').(!empty($tp['replace']) ? "

Replaces the current ".$tp['replace'][0].' '.$tp['replace'][1].' record.' : '').($key==='m365' ? "

Check the MX value against your Microsoft 365 admin centre." : '')."

Apply?"))?>)"><?=$hid?><input type="hidden" name="template" value="<?=Helpers::e($key)?>"><button class="dntpl" title="<?=Helpers::e($what)?>"><b><?=Helpers::e($tp['label'])?></b><small><?=count($tp['rows'])?> record<?=count($tp['rows']) === 1 ? '' : 's'?><?= !empty($tp['mail']) ? ' · replaces MX/SPF' : '' ?></small></button></form><?php endforeach;?></div>
<small class="muted">Moving mail to Google/Microsoft? Also set Mail to 📮 Remote on the Domains page so this server stops treating the domain's mail as local.</small>
</div>

<div class="dnpanel" id="dnp-ns" role="tabpanel">
<?php if(!empty($nameservers)):?>
<p style="margin-top:0">Set these two nameservers at the domain's <b>registrar</b>:</p>
<div class="dnnsbox"><code><?=Helpers::e($nameservers[0])?></code><code><?=Helpers::e($nameservers[1] ?? '')?></code></div>
<?php if($selfNs):?><small class="muted">These live inside this zone, so the registrar also needs <b>glue</b> (ns1/ns2 → this server's IP) and the <code>ns1</code>/<code>ns2</code> A records must stay.</small>
<?php else:?><small class="muted">Managed by the panel — the NS records are written into the zone automatically; no ns1/ns2 A records are needed here.</small><?php endif;?>
<?php if($isAdmin && empty($glueOk) && !$selfNs):?><br><small class="muted">Admin: the nightly glue check hasn't seen ns1/ns2 of the panel host resolving to this server yet — until it does, these nameservers won't work at a registrar (setup checklist, glue step).</small><?php endif;?>
<?php if(!empty($nsSwitchTo)):?><form method="post" action="/dns/use-server-ns" style="margin-top:10px" onsubmit="return confirm('Switch <?=Helpers::e($vd)?> to <?=Helpers::e($nsSwitchTo[0].' / '.$nsSwitchTo[1])?>? Removes the ns1/ns2 A records. Update the registrar to the same nameservers.')"><?=$hid?>
<small class="muted">This zone uses its own per-domain nameservers (older default).</small> <button class="btn small">Use server nameservers (<?=Helpers::e($nsSwitchTo[0])?>)</button></form><?php endif;?>
<div style="margin-top:14px"><a class="btn small" href="/dns?domain=<?=urlencode($vd)?>&check=1#ns">🔎 Check delegation</a> <small class="muted">asks public DNS whether the registrar really points here</small></div>
<?php if(is_array($check ?? null)):?><div id="dns-check" style="margin-top:10px"><?php if(isset($check['error'])):?><span class="pill err">check failed</span> <small><?=Helpers::e($check['error'])?></small>
<?php else: $nm = $check['ns_match'] ?? 'unknown'; $sip = $check['server_ip'] ?? ''; ?>
<div class="tablewrap"><table><tr><th>Check</th><th>Seen publicly</th><th></th></tr>
<tr><td>Nameservers</td><td><code><?=Helpers::e(str_replace(',', ', ', $check['public_ns'] ?? ''))?></code></td><td><?=$nm==='yes'?'<span class="pill ok">matches</span>':($nm==='no'?'<span class="pill err">registrar points elsewhere</span>':'<span class="pill err">no answer yet</span>')?></td></tr>
<?php foreach(['ns1_a'=>$nameservers[0], 'ns2_a'=>$nameservers[1], 'public_a'=>$vd] as $k=>$label): $v = $check[$k] ?? ''; ?><tr><td><code><?=Helpers::e($label)?></code> A</td><td><code><?=Helpers::e($v !== '' ? $v : '—')?></code></td><td><?=($v !== '' && $v === $sip)?'<span class="pill ok">this server</span>':($v === '' ? '<span class="pill err">no answer</span>' : '<span class="pill err">other IP</span>')?></td></tr><?php endforeach;?>
<tr><td>MX</td><td colspan="2"><code><?=Helpers::e(str_replace(',', ', ', ($check['public_mx'] ?? '') ?: '—'))?></code></td></tr>
</table></div><small class="muted">Registrar changes can take up to 48 h to show. "Other IP" on the domain itself is fine if the site is hosted elsewhere on purpose.</small><?php endif;?></div><?php endif;?>
<?php else:?><p class="muted">Nameservers couldn't be worked out for this zone.</p><?php endif;?>
<?php if($isAdmin):?><div class="dnsep"></div><h4>🔁 Secondary nameservers <small class="muted">external BIND secondaries allowed zone transfers + notified on change</small></h4>
<form class="inline" method="post" action="/dns/secondary"><?=$hid?>
<input name="secondaries" value="<?=Helpers::e($viewing['secondary_ns'] ?? '')?>" placeholder="203.0.113.7, 198.51.100.9 (blank = primary only)" style="min-width:260px">
<button class="btn small">Set secondaries</button></form>
<small class="muted">Current: <b><?=Helpers::e(($viewing['secondary_ns'] ?? '') !== '' ? $viewing['secondary_ns'] : 'primary only')?></b> · Secondary box setup (BIND secondary stanza + registrar glue) is documented, not managed here.</small><?php endif;?>
</div>

<div class="dnpanel" id="dnp-import" role="tabpanel">
<h4 style="margin-top:0">📋 Paste records <small class="muted">zone file / export from Cloudflare, cPanel, another BIND server</small></h4>
<form method="post" action="/dns/import"><?=$hid?>
<textarea name="zone" rows="6" style="width:100%;font-family:monospace;font-size:12px" placeholder="www.<?=Helpers::e($vd)?>. 3600 IN A 192.0.2.10&#10;@ IN MX 10 mail.<?=Helpers::e($vd)?>.&#10;_dmarc IN TXT &quot;v=DMARC1; p=none&quot;"></textarea>
<button class="btn small">Import & apply</button> <small class="muted">Adds new records only — duplicates, SOA, the apex NS (managed by the panel) and other domains' names are skipped. All-or-nothing: if BIND refuses the result, nothing is kept.</small></form>
<div class="dnsep"></div>
<div class="dnrow">
<div><b>⇪ Import from disk</b><small class="muted">pull records from the zone file BIND is serving into the panel</small></div>
<form method="post" action="/dns/import"><?=$hid?><button class="btn small">Import from disk</button></form></div>
<div class="dnrow">
<div><b>⇩ Export zone file</b><small class="muted">download it for a backup or another DNS host</small></div>
<a class="btn small" href="/dns/export?domain=<?=urlencode($vd)?>">Export</a></div>
<div class="dnrow dndanger">
<div><b>↺ Reset to default</b><small class="muted">rebuild the zone from KarnakPanel defaults — all custom records go</small></div>
<form method="post" action="/dns/reset" onsubmit="return confirm('Reset <?=Helpers::e($vd)?> to KarnakPanel defaults? All custom records go.')"><?=$hid?><button class="btn small danger">Reset zone</button></form></div>
</div>

<div class="dnpanel" id="dnp-security" role="tabpanel">
<h4 style="margin-top:0">🔏 DNSSEC <small class="muted">signed responses — give the DS to your registrar to complete the chain</small></h4>
<?php if(!empty($dnssecSigned)):?><p>✅ <b>Signed.</b> Keys live in <code>/etc/bind/keys</code> and are reused on re-enable, so this DS stays valid:</p>
<pre style="white-space:pre-wrap;overflow-wrap:anywhere;font-size:12px"><?=Helpers::e($dnssecDs ?? '')?></pre>
<form method="post" action="/dns/dnssec-disable" onsubmit="return confirm('Stop signing <?=Helpers::e($vd)?>? Validators will treat it as unsigned again.')"><?=$hid?><button class="btn small danger">Disable signing</button></form>
<?php else:?><p class="muted">Unsigned — resolvers get plain answers.</p>
<form method="post" action="/dns/dnssec-enable"><?=$hid?><button class="btn small">Sign zone</button></form><?php endif;?>
</div>

<div class="dnpanel" id="dnp-zone" role="tabpanel">
<p class="muted" style="margin-top:0">The file BIND is serving right now (read-only).</p>
<pre class="dnzonefile"><?=Helpers::e($zone ?? '')?></pre>
</div>
</div>
<?php endif;?>
</section>
</div>
<?php endif;?>
<script>
(function(){
  // zone list: instant filter over this page; Enter searches every zone
  var f=document.getElementById('dnFilter'),z=document.getElementById('dnZones'),nh=document.getElementById('dnNoHit');
  if(f&&z){f.addEventListener('input',function(){var q=f.value.trim().toLowerCase(),hit=0;
    z.querySelectorAll('.dnzone').forEach(function(a){var ok=!q||a.dataset.name.indexOf(q)>=0;a.hidden=!ok;if(ok)hit++;});
    if(nh)nh.hidden=hit>0||!z.querySelector('.dnzone');});
    var on=z.querySelector('.dnzone.on');if(on)on.scrollIntoView({block:'nearest'});}
  // zone tabs, remembered in the URL hash so reloads/redirects land back
  var tabs=[].slice.call(document.querySelectorAll('.dntab'));if(!tabs.length)return;
  document.querySelector('.dnzone-card').classList.add('js');
  function show(k,push){var b=tabs.filter(function(t){return t.dataset.tab===k;})[0]||tabs[0];
    tabs.forEach(function(t){var o=t===b;t.classList.toggle('on',o);t.setAttribute('aria-selected',o?'true':'false');document.getElementById('dnp-'+t.dataset.tab).classList.toggle('on',o);});
    if(push)history.replaceState(null,'',location.pathname+location.search+(b.dataset.tab==='records'?'':'#'+b.dataset.tab));}
  tabs.forEach(function(t){t.addEventListener('click',function(){show(t.dataset.tab,true);});});
  // a POST from a tab redirects back without the hash: reopen that tab once
  var zk=<?=json_encode($vd, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>+'|',back='';try{back=sessionStorage.getItem('dnTab')||'';sessionStorage.removeItem('dnTab');}catch(e){}
  back=back.indexOf(zk)===0?back.slice(zk.length):'';
  show(<?= is_array($check ?? null) ? "'ns'" : "location.hash.slice(1)||back||'records'" ?>,!!back);
  document.querySelectorAll('.dnpanel form[method=post]').forEach(function(f){f.addEventListener('submit',function(ev){if(ev.defaultPrevented)return;try{sessionStorage.setItem('dnTab',zk+f.closest('.dnpanel').id.slice(4));}catch(e){}});});
  // record edit lives on the Records tab; make sure it's showing
  document.addEventListener('click',function(e){if(e.target.closest('.dns-edit'))show('records',true);},true);
  // long values (DKIM keys) fold to 3 lines; click to unfold
  document.querySelectorAll('#dns-rows .dnv').forEach(function(v){if(v.scrollHeight>v.clientHeight+2){v.classList.add('fold');v.title='Click to show the full value';v.addEventListener('click',function(){v.classList.toggle('open');});}});
  var ab=document.getElementById('dnAddBtn');if(ab)ab.addEventListener('click',function(e){e.preventDefault();var h=document.getElementById('dns-f-host');h.scrollIntoView({behavior:'smooth',block:'center'});h.focus({preventScroll:true});});
})();
</script>

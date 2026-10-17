<h2>✉️ Email <small style="color:var(--muted)">Exim4 + Dovecot + spamd</small></h2>
<div class="card"><form class="inline" method="post" action="/email/create">
<input type="hidden" name="csrf" value="<?=Helpers::csrf()?>">
<input name="local" placeholder="info" required>
<select name="domain_id"><?php foreach($domains as $d):?><option value="<?=$d['id']?>">@<?=Helpers::e($d['domain'])?></option><?php endforeach;?></select>
<input name="password" type="password" autocomplete="new-password" placeholder="password 8+" required>
<button class="btn">Create mailbox</button></form></div>
<?php $mailhost = explode(':', $_SERVER['HTTP_HOST'] ?? 'this server')[0]; ?>
<div class="card"><h3>📡 Connection settings <small style="color:var(--muted)">IMAP is the default — mail stays on the server</small></h3>
<table><tr><th>Service</th><th>Server</th><th>Ports (STARTTLS / SSL)</th><th>Login</th></tr>
<tr><td>✉️ IMAP <span class="badge">default</span></td><td><code><?=Helpers::e($mailhost)?></code></td><td><code>143</code> / <code>993</code></td><td>full address + mailbox password</td></tr>
<tr><td>📤 SMTP</td><td><code><?=Helpers::e($mailhost)?></code></td><td><code>25</code> / <code>587</code> (auth required)</td><td>same as IMAP</td></tr>
<tr><td>📬 Webmail</td><td colspan="2"><a class="btn small" href="<?=Helpers::e(Helpers::serviceUrl(8091, 'app.webmail_url'))?>" target="_blank" rel="noopener">Open Roundcube</a></td><td>—</td></tr>
</table>
<small style="color:var(--muted)">No POP3 — it deletes mail off the server in most clients. Spam scoring via spamd (tagged <code>***SPAM***</code>).</small></div>
<div class="card"><form method="get" action="/email" class="inline" style="margin-bottom:6px">
<input name="q" value="<?=Helpers::e($q ?? '')?>" placeholder="search mailbox" style="min-width:200px"> <button class="btn small">Search</button><?php if(($q ?? '')!==''):?> <a class="btn small" href="/email">Clear (<?=Helpers::e((string)($total ?? 0))?> match)</a><?php endif;?></form><table><tr><th>Mailbox</th><th>Used / Quota</th><th>Spam flag ≥</th><th>Limit/hr</th><th></th></tr>
<?php foreach($emails as $e):?><tr><td><?=Helpers::e($e['localpart'].'@'.$e['domain'])?><?php if(($e['send_hold_at'] ?? null) !== null):?><br><span class="badge" style="background:var(--danger,#c0392b);color:#fff" title="<?=Helpers::e('Held '.$e['send_hold_at'].' — '.($e['send_hold_reason'] ?? ''))?>">⛔ sending held</span> <small style="color:var(--muted)">suspected compromise · <b>Reset PW</b> restores sending</small><?php if(($user['role'] ?? '') === 'admin'):?> <form method="post" action="/email/guard-release" style="display:inline" onsubmit="return confirm('Release without a password change? Only for a false alarm — a real thief keeps sending.')"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><input type="hidden" name="id" value="<?=$e['id']?>"><button class="btn small">Release</button></form><?php endif;?><?php endif;?></td><td><?= $e['used_mb'] === null ? '—' : Helpers::e($e['used_mb'].' MB') ?> / <?=$e['quota_mb']?> MB</td>
<td><form method="post" action="/email/threshold" style="display:inline"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><input type="hidden" name="id" value="<?=$e['id']?>"><input name="threshold" value="<?=Helpers::e((string)($e['spam_threshold'] ?? 5))?>" style="max-width:56px" title="X-Spam-Flag added above this spamd score"><button class="btn small">Set</button></form></td>
<td><form method="post" action="/email/limit" style="display:inline"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><input type="hidden" name="id" value="<?=$e['id']?>"><input name="limit" value="<?=Helpers::e((string)($e['smtp_limit'] ?? ''))?>" placeholder="def" style="max-width:56px" title="Sends per hour (blank = global default)"><button class="btn small">Set</button></form></td>
<td style="white-space:nowrap"><a class="btn small" href="<?=Helpers::e(Helpers::serviceUrl(8091, 'app.webmail_url'))?>" target="_blank" rel="noopener" title="Open <?=Helpers::e($e['localpart'].'@'.$e['domain'])?> in Roundcube">📬 Webmail</a>
<form method="post" action="/email/reset" style="display:inline"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><input type="hidden" name="id" value="<?=$e['id']?>"><button class="btn small" title="Generate a new password (shown once)">Reset PW</button></form>
<form method="post" action="/email/delete" style="display:inline" onsubmit="return confirm('Delete <?=Helpers::e($e['localpart'].'@'.$e['domain'])?>? Its stored mail goes too.')"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><input type="hidden" name="id" value="<?=$e['id']?>"><button class="btn small danger">Delete</button></form></td></tr><?php endforeach;?>
<?php if(empty($emails)):?><tr><td colspan="5" style="color:var(--muted)">No mailboxes yet.<?php if(empty($domains)):?> Add a domain first, then create mailboxes here.<?php endif;?></td></tr><?php endif;?></table>
<?php $pagerBase = '/email?'.(($q ?? '') !== '' ? 'q='.urlencode($q).'&' : ''); $pagerLabel = 'mailboxes'; require __DIR__.'/_pager.php'; ?></div>
<div class="card"><h3>📤 Sending limits <small style="color:var(--muted)">compromised-script brake — outbound authenticated sends per hour</small></h3>
<?php if(($user['role'] ?? '') === 'admin'):?><form class="inline" method="post" action="/email/limit-default">
<input type="hidden" name="csrf" value="<?=Helpers::csrf()?>">
<input name="default" value="<?=Helpers::e($smtpDefault ?? '200')?>" pattern="[0-9]*" style="max-width:90px" title="Global default sends/hour">
<button class="btn">Set default</button></form><?php endif;?>
<small style="color:var(--muted)">Global default: <b><?=Helpers::e($smtpDefault ?? '200')?>/hr</b> · per-mailbox override in the table (blank = default) · inbound mail and webmail never limited.</small>
<?php if(($user['role'] ?? '') === 'admin'):?><form class="inline" method="post" action="/email/guard" style="margin-top:8px"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><input type="hidden" name="on" value="<?=($mailGuard ?? '1') === '1' ? '0' : '1'?>">
<b>🛡️ Mail guard:</b> <?=($mailGuard ?? '1') === '1' ? '<span class="badge">on</span>' : '<span class="badge">off</span>'?> <button class="btn small"><?=($mailGuard ?? '1') === '1' ? 'Turn off' : 'Turn on'?></button></form>
<small style="color:var(--muted)">Holds a mailbox's outgoing mail when it looks hijacked (sending from many networks, hammering the cap, mass bounces — two signs, or one extreme). Its queued mail is frozen for review; the owner and you are alerted; Reset PW restores sending.</small><?php endif;?></div>
<?php if(($user['role'] ?? '') === 'admin'):?><div class="card"><h3>🛡️ Spam scanning <small style="color:var(--muted)">spamd costs CPU per message — optionally skip it Sat/Sun when nobody triages</small></h3>
<form class="inline" method="post" action="/email/weekend-skip">
<input type="hidden" name="csrf" value="<?=Helpers::csrf()?>">
<label style="display:inline-flex;align-items:center;gap:6px;margin-right:8px"><input type="checkbox" name="on" value="1" <?=($weekendSkip ?? '0') === '1' ? 'checked' : ''?>> Skip spam scanning on weekends</label>
<button class="btn">Save</button></form>
<small style="color:var(--muted)">Status: <b><?=($weekendSkip ?? '0') === '1' ? 'skipping Sat/Sun' : 'scanning every day'?></b> · malware rejects, rate caps and relay policy always apply · skipped mail is marked <code>X-Spam-Skipped</code> and stays in the inbox (no <code>X-Spam-Flag</code>).</small></div><?php endif;?>
<?php if(($user['role'] ?? '') === 'admin'):?><div class="card"><h3>📡 SMTP relay <small style="color:var(--muted)">smarthost for port-25-blocked networks — all outbound via the relay when on</small></h3>
<?php $rl = ($relay ?? null);?>
<?php if(!empty($rl) && !empty($rl['on'])):?><p>Status: <b class="pill">relaying via <?=Helpers::e($rl['host'] ?? '')?>:<?=Helpers::e($rl['port'] ?? '')?> as <?=Helpers::e($rl['user'] ?? '')?></b></p>
<form class="inline" method="post" action="/email/relay-clear"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><button class="btn small danger">Disable relay</button></form>
<?php else:?><p style="color:var(--muted)">Status: direct MX delivery. Saving turns the relay on immediately.</p><?php endif;?>
<form class="inline" method="post" action="/email/relay-save"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>">
<input name="host" placeholder="smtp.example.com" required value="<?=Helpers::e($rl['host'] ?? '')?>" style="min-width:170px">
<input name="port" placeholder="587" required value="<?=Helpers::e($rl['port'] ?? '587')?>" pattern="[0-9]*" style="max-width:70px" title="587 STARTTLS, 465 implicit TLS">
<input name="user" placeholder="login" required value="<?=Helpers::e($rl['user'] ?? '')?>" style="max-width:150px" autocomplete="off">
<input name="pass" type="password" placeholder="password (no : allowed)" required autocomplete="new-password" style="max-width:150px">
<button class="btn">Save &amp; enable</button></form>
<br><form class="inline" method="post" action="/email/relay-test" style="margin-top:8px"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>">
<input name="to" placeholder="you@example.com" required style="min-width:200px">
<button class="btn small">Send test mail</button></form>
<small style="color:var(--muted)">Password travels file-only (never shown, never stored in the panel DB) · 465 = implicit TLS, anything else STARTTLS (required, never plaintext).</small></div><?php endif;?>
<div class="card"><h3>↪️ Forwarders <small style="color:var(--muted)">redirect before delivery — a forwarder wins over a same-named mailbox, no local copy kept. Use <code>*</code> as the name for a catch-all (only fires when no mailbox matches — mailboxes always win)</small></h3>
<form class="inline" method="post" action="/email/forward-create">
<input type="hidden" name="csrf" value="<?=Helpers::csrf()?>">
<input name="flocal" placeholder="sales" required>
<select name="domain_id"><?php foreach($domains as $d):?><option value="<?=$d['id']?>">@<?=Helpers::e($d['domain'])?></option><?php endforeach;?></select>
<input name="targets" placeholder="you@gmail.com, boss@firm.com" required style="min-width:260px">
<button class="btn">Add forwarder</button></form>
<table><tr><th>From</th><th>To</th><th></th></tr>
<?php foreach($forwards ?? [] as $f):?><tr><td><?=Helpers::e($f['localpart'].'@'.$f['domain'])?></td><td><?=Helpers::e($f['targets'])?></td>
<td><form method="post" action="/email/forward-delete" style="display:inline" onsubmit="return confirm('Delete forwarder <?=Helpers::e($f['localpart'].'@'.$f['domain'])?>?')"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><input type="hidden" name="id" value="<?=$f['id']?>"><button class="btn small danger">Delete</button></form></td></tr><?php endforeach;?>
<?php if(empty($forwards ?? [])):?><tr><td colspan="3" style="color:var(--muted)">No forwarders yet.</td></tr><?php endif;?></table></div>
<div class="card"><h3>🧹 Filters <small style="color:var(--muted)">server-side rules per mailbox — matched mail files into a folder, forwards on, or is discarded</small></h3>
<form class="inline" method="post" action="/email/filter-create">
<input type="hidden" name="csrf" value="<?=Helpers::csrf()?>">
<select name="mailbox_id"><?php foreach($emails as $e):?><option value="<?=$e['id']?>"><?=Helpers::e($e['localpart'].'@'.$e['domain'])?></option><?php endforeach;?></select>
<select name="field"><option value="subject">subject</option><option value="from">from</option><option value="to">to</option></select>
<input name="match" placeholder="contains…" required maxlength="160" style="min-width:140px">
<select name="action"><option value="folder">→ folder</option><option value="redirect">→ forward</option><option value="discard">discard</option></select>
<input name="target" placeholder="folder or address" required maxlength="190" style="min-width:150px">
<button class="btn">Add filter</button></form>
<table><tr><th>Mailbox</th><th>If</th><th>Then</th><th></th></tr>
<?php foreach($filters ?? [] as $f):?><tr><td><?=Helpers::e($f['localpart'].'@'.$f['domain'])?></td><td><code><?=Helpers::e($f['field'])?></code> contains <code><?=Helpers::e($f['match_text'])?></code></td><td><?=Helpers::e($f['action'])?><?php if($f['action']!=='discard'):?> → <code><?=Helpers::e($f['target'])?></code><?php endif;?></td>
<td><form method="post" action="/email/filter-delete" style="display:inline" onsubmit="return confirm('Delete this filter?')"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><input type="hidden" name="id" value="<?=$f['id']?>"><button class="btn small danger">Delete</button></form></td></tr><?php endforeach;?>
<?php if(empty($filters ?? [])):?><tr><td colspan="4" style="color:var(--muted)">No filters yet.</td></tr><?php endif;?></table></div>
<div class="card"><h3>📣 Mailing lists <small style="color:var(--muted)">distribution groups — only subscribers can post (others get a bounce)</small></h3>
<?php if(($user['role'] ?? '') === 'admin'):?><form class="inline" method="post" action="/email/list-default">
<input type="hidden" name="csrf" value="<?=Helpers::csrf()?>">
<input name="default" value="<?=Helpers::e($listDefault ?? '10')?>" pattern="[0-9]*" style="max-width:90px" title="Posts per hour per list (fanout is per member)">
<button class="btn">Set list rate</button></form>
<small style="color:var(--muted)">List rate: <b><?=Helpers::e($listDefault ?? '10')?> posts/hr per list</b> (one post emits per member).</small><?php endif;?>
<form class="inline" method="post" action="/email/list-create">
<input type="hidden" name="csrf" value="<?=Helpers::csrf()?>">
<input name="llocal" placeholder="team" required>
<select name="domain_id"><?php foreach($domains as $d):?><option value="<?=$d['id']?>">@<?=Helpers::e($d['domain'])?></option><?php endforeach;?></select>
<button class="btn">Create list</button></form>
<?php foreach($lists ?? [] as $l):?><div style="margin-top:8px"><b><?=Helpers::e($l['localpart'].'@'.$l['domain'])?></b> (<?=count($l['members'] ?? [])?>/200)
<form method="post" action="/email/list-delete" style="display:inline" onsubmit="return confirm('Delete list <?=Helpers::e($l['localpart'].'@'.$l['domain'])?>?')"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><input type="hidden" name="id" value="<?=$l['id']?>"><button class="btn small danger">Delete list</button></form>
<form class="inline" method="post" action="/email/member-add" style="margin-top:4px">
<input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><input type="hidden" name="id" value="<?=$l['id']?>">
<input name="address" type="email" placeholder="member@example.com" required style="min-width:200px"><button class="btn small">Subscribe</button></form>
<?php foreach($l['members'] ?? [] as $m):?><div><small><?=Helpers::e($m['address'])?></small>
<form method="post" action="/email/member-delete" style="display:inline"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><input type="hidden" name="mid" value="<?=$m['id']?>"><button class="btn small danger">×</button></form></div><?php endforeach;?></div>
<?php endforeach;?>
<?php if(empty($lists ?? [])):?><p style="color:var(--muted)">No lists yet.</p><?php endif;?></div>
<div class="card"><h3>🏖️ Autoresponders <small style="color:var(--muted)">vacation reply + original both delivered — one reply per sender per 7 days. A forwarder on the same address wins and suppresses the reply.</small></h3>
<form method="post" action="/email/autoresponder">
<input type="hidden" name="csrf" value="<?=Helpers::csrf()?>">
<div class="inline"><select name="id"><?php foreach($emails as $e):?><option value="<?=$e['id']?>"><?=Helpers::e($e['localpart'].'@'.$e['domain'])?></option><?php endforeach;?></select>
<input name="subject" placeholder="Subject (max 190)" required maxlength="190" style="min-width:200px">
<button class="btn">Save auto-reply</button></div>
<textarea name="text" placeholder="Message (max 2000 chars)" required maxlength="2000" rows="3" style="width:100%;margin-top:6px"></textarea>
</form>
<table><tr><th>Mailbox</th><th>Subject</th><th></th></tr>
<?php foreach($responders ?? [] as $r):?><tr><td><?=Helpers::e($r['localpart'].'@'.$r['domain'])?></td><td><?=Helpers::e($r['autoresp_subject'])?></td>
<td><form method="post" action="/email/autoresponder-delete" style="display:inline" onsubmit="return confirm('Turn OFF auto-reply for <?=Helpers::e($r['localpart'].'@'.$r['domain'])?>?')"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><input type="hidden" name="id" value="<?=$r['id']?>"><button class="btn small danger">Turn OFF</button></form></td></tr><?php endforeach;?>
<?php if(empty($responders ?? [])):?><tr><td colspan="3" style="color:var(--muted)">No auto-replies active.</td></tr><?php endif;?></table></div>
<div class="card"><h3>🔏 Mail authentication <small style="color:var(--muted)">SPF + DKIM + DMARC — receivers trust signed mail</small></h3>
<table><tr><th>Domain</th><th>SPF</th><th>DKIM</th><th>DMARC</th><th>Score</th><th></th></tr>
<?php foreach($auth ?? [] as $a):?><tr><td><?=Helpers::e($a['domain'])?></td>
<td><?= $a['spf'] ? '✅' : '⚠️ add <code>v=spf1 a mx ~all</code> TXT at <code>'.Helpers::e($a['hosts']['spf'] ?? '@').'</code>'.(!empty($a['zone']) ? ' in the '.Helpers::e($a['zone']).' zone' : '') ?></td>
<td><?php if($a['dkim']):?>✅ signed<br><small style="word-break:break-all"><?=Helpers::e($a['txt'])?></small><?php else:?>—<?php endif;?></td>
<td><?php if(!empty($a['dmarcinh'])):?>✅ <small style="color:var(--muted)">inherited (p=<?=Helpers::e($a['dmarcinh'])?>)</small><?php else:?><?= !empty($a['dmarc']) ? '✅' : '⚠️ add <code>v=DMARC1; p=none</code> TXT at <code>'.Helpers::e($a['hosts']['dmarc'] ?? '_dmarc').'</code>' ?><?php endif;?></td>
<td><?php $sc = $scores[$a['domain']] ?? null; if($sc):?><b><?= (int)$sc['score'] ?>/100</b><br><small style="color:var(--muted)"><?=Helpers::e(substr($sc['checked_at'] ?? '', 0, 10))?><br><?= nl2br(Helpers::e($sc['details'] ?? '')) ?></small><?php else:?>—<?php endif;?></td>
<td style="white-space:nowrap"><form method="post" action="/email/score" style="display:inline"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><input type="hidden" name="domain_id" value="<?=$a['id']?>"><button class="btn small">Check</button></form>
<?php if(!$a['dkim']):?><form method="post" action="/email/dkim" style="display:inline"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><input type="hidden" name="domain_id" value="<?=$a['id']?>"><button class="btn small">Sign</button></form><?php endif;?></td></tr><?php endforeach;?></table>
<h4 style="margin:14px 0 6px">🛡️ Transport security &amp; reports <small style="color:var(--muted)">MTA-STS makes senders require TLS to your MX · DMARC/TLS reports show who sends as you</small></h4>
<table><tr><th>Domain</th><th>DMARC reports</th><th>MTA-STS</th><th></th></tr>
<?php foreach($auth ?? [] as $a): $m = $a['mta_sts'] ?? 'off'; $csrf = Helpers::csrf(); ?><tr><td><?=Helpers::e($a['domain'])?></td>
<td><?php if(!empty($a['remote'])):?><small style="color:var(--muted)">mail handled remotely</small><?php else:?>
<form method="post" action="/email/reports-toggle" style="display:inline"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="domain_id" value="<?=$a['id']?>"><button class="btn small<?= !empty($a['reports']) ? ' danger' : '' ?>"<?= !empty($a['reports']) ? ' onclick="return confirm(\'Stop collecting reports? Stored reports are kept.\')"' : '' ?>><?= !empty($a['reports']) ? 'Stop' : 'Collect' ?></button></form>
<?php if(!empty($a['reports'])):?> <a class="btn small" href="/email/reports?domain_id=<?=(int)$a['id']?>">View</a><?php if(($a['dmarcp'] ?? '') !== ''):?> <small style="color:var(--muted)">p=<?=Helpers::e($a['dmarcp'])?></small><?php endif;?>
<?php if(!empty($a['ap'])):?> <a class="badge" style="text-decoration:none" href="/email/reports?domain_id=<?=(int)$a['id']?>" title="The reports say this step is safe. Open to review and apply.">🧭 ready: <?=Helpers::e(implode(' · ', $a['ap']))?></a><?php endif;?><?php endif;?><?php endif;?></td>
<td><?php $badge = ['off' => '—', 'testing' => '🧪 testing', 'enforce' => '🔒 enforce', 'none' => '⏸ none (winding down)'][$m] ?? '—'; ?><?=$badge?></td>
<td style="white-space:nowrap"><?php if(empty($a['remote'])):?>
<?php $btn = function(string $mode, string $label, string $cls = '', string $confirm = '') use ($a, $csrf) { ?><form method="post" action="/email/mta-sts" style="display:inline"<?= $confirm !== '' ? ' onsubmit="return confirm(\''.Helpers::e($confirm).'\')"' : '' ?>><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="domain_id" value="<?=$a['id']?>"><input type="hidden" name="mode" value="<?=$mode?>"><button class="btn small<?=$cls?>"><?=$label?></button></form> <?php }; ?>
<?php if($m === 'off'): $btn('testing', 'Publish (testing)'); endif;?>
<?php if($m === 'testing'): $btn('check', 'Check'); $btn('enforce', 'Enforce', '', 'Enforce MTA-STS? Senders will REFUSE delivery if TLS to your MX ever breaks (cached up to 7 days). Checks run first.'); $btn('none', 'Step down'); $btn('remove', 'Remove', ' danger'); endif;?>
<?php if($m === 'enforce'): $btn('check', 'Check'); $btn('none', 'Step down', ' danger', 'Step down to none? Keep it published 7 days before removing.'); endif;?>
<?php if($m === 'none'): $btn('testing', 'Re-test'); $btn('remove', 'Remove', ' danger', 'Remove the MTA-STS policy?'); endif;?>
<?php endif;?></td></tr><?php endforeach;?></table>
<small style="color:var(--muted)">Start with <b>testing</b> (never blocks mail), re-issue SSL so <code>mta-sts.&lt;domain&gt;</code> is covered, then <b>Check</b>. Enforce only after TLS reports show senders succeed.</small><br>
<small style="color:var(--muted)">DKIM signs outbound after key creation (no registrar wait for signing itself — the TXT only matters once the registrar publishes it).</small>
<details style="margin-top:8px"><summary style="cursor:pointer;color:var(--muted)">Using external nameservers? Add these records at your registrar — nothing here counts publicly until then</summary>
<div style="margin-top:6px;display:grid;gap:10px">
<?php $authList = array_values($auth ?? []); $authHead = array_slice($authList, 0, 4); $authRest = array_slice($authList, 4); ?>
<?php foreach($authHead as $a):?><?php require __DIR__.'/_registrar.php'; ?><?php endforeach;?>
<?php if(!empty($authRest)):?><details style="margin-top:6px"><summary style="cursor:pointer;color:var(--muted)">Show all <?=count($authList)?> domains (<?=count($authRest)?> more)</summary>
<?php foreach($authRest as $a):?><?php require __DIR__.'/_registrar.php'; ?><?php endforeach;?></details><?php endif;?></div></details></div>


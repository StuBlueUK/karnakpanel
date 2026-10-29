<h2>✉️ Email <small style="color:var(--muted)">Exim4 + Dovecot + spamd</small></h2>
<?php $isAdmin = ($user['role'] ?? '') === 'admin'; ?>
<nav class="tabs etabs" hidden>
<a class="tab" href="#mailboxes" data-etab="mailboxes">Mailboxes<?php if(($total ?? 0) > 0):?> <span class="count"><?=(int)$total?></span><?php endif;?></a>
<a class="tab" href="#rules" data-etab="rules">Forwarding &amp; rules</a>
<a class="tab" href="#auth" data-etab="auth">Authentication</a>
<?php if($isAdmin):?><a class="tab" href="#server" data-etab="server">Server</a><?php endif;?>
</nav>
<?php ob_start(); ?>
<div class="card"><h3>📤 Sending limits <small style="color:var(--muted)">compromised-script brake — outbound authenticated sends per hour</small></h3>
<?php if(($user['role'] ?? '') === 'admin'):?><form class="inline" method="post" action="/email/limit-default">
<input type="hidden" name="csrf" value="<?=Helpers::csrf()?>">
<input name="default" value="<?=Helpers::e($smtpDefault ?? '200')?>" pattern="[0-9]*" style="max-width:90px" title="Global default sends/hour">
<button class="btn">Set default</button></form><?php endif;?>
<small style="color:var(--muted)">Global default: <b><?=Helpers::e($smtpDefault ?? '200')?>/hr</b> · per-mailbox override in the table (blank = default) · inbound mail and webmail never limited.</small>
<?php if(($user['role'] ?? '') === 'admin'):?><form class="inline" method="post" action="/email/guard" style="margin-top:8px"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><input type="hidden" name="on" value="<?=($mailGuard ?? '1') === '1' ? '0' : '1'?>">
<b>🛡️ Mail guard:</b> <?=($mailGuard ?? '1') === '1' ? '<span class="badge">on</span>' : '<span class="badge">off</span>'?> <button class="btn small"><?=($mailGuard ?? '1') === '1' ? 'Turn off' : 'Turn on'?></button></form>
<small style="color:var(--muted)">Holds a mailbox's outgoing mail when it looks hijacked (sending from many networks, hammering the cap, mass bounces — two signs, or one extreme). Its queued mail is frozen for review; the owner and you are alerted; Reset PW restores sending.</small><?php endif;?></div>
<?php $limitsCard = ob_get_clean(); ?>
<section class="etab" id="etab-mailboxes">
<?php $qmax = (int)($quotaMax ?? 102400); $csrf = Helpers::csrf(); $webmail = Helpers::serviceUrl(8091, 'app.webmail_url'); ?>
<div class="card"><h3>➕ New mailbox</h3><form class="inline" method="post" action="/email/create">
<input type="hidden" name="csrf" value="<?=$csrf?>">
<input name="local" placeholder="info" required>
<select name="domain_id"><?php foreach($domains as $d):?><option value="<?=$d['id']?>">@<?=Helpers::e($d['domain'])?></option><?php endforeach;?></select>
<input name="password" type="password" autocomplete="new-password" placeholder="password 8+" required minlength="8">
<label style="display:inline-flex;align-items:center;gap:4px">quota <input name="quota_mb" type="number" min="10" max="<?=$qmax?>" step="1" value="<?=min(1024, $qmax)?>" style="max-width:90px" title="Mailbox size limit in MB (10..<?=$qmax?>)"> MB</label>
<button class="btn">Create mailbox</button></form></div>
<?php $mailhost = explode(':', $_SERVER['HTTP_HOST'] ?? 'this server')[0]; ?>
<div class="card"><details><summary style="cursor:pointer;font-weight:600">📡 How to connect a mail app <small style="color:var(--muted)">IMAP / SMTP servers and ports · webmail</small></summary><div style="margin-top:10px">
<table><tr><th>Service</th><th>Server</th><th>Ports (STARTTLS / SSL)</th><th>Login</th></tr>
<tr><td>✉️ IMAP <span class="badge">default</span></td><td><code><?=Helpers::e($mailhost)?></code></td><td><code>143</code> / <code>993</code></td><td>full address + mailbox password</td></tr>
<tr><td>📤 SMTP</td><td><code><?=Helpers::e($mailhost)?></code></td><td><code>25</code> / <code>587</code> (auth required)</td><td>same as IMAP</td></tr>
<tr><td>📬 Webmail</td><td colspan="2"><a class="btn small" href="<?=Helpers::e($webmail)?>" target="_blank" rel="noopener">Open Roundcube</a></td><td>—</td></tr>
</table>
<small style="color:var(--muted)">No POP3 — it deletes mail off the server in most clients. Spam scoring via spamd (tagged <code>***SPAM***</code>).</small></div></details></div>
<div class="card mbcard"><form method="get" action="/email" class="inline" style="margin-bottom:6px">
<input name="q" value="<?=Helpers::e($q ?? '')?>" placeholder="search mailbox" style="min-width:180px">
<?php if(count($domains) > 1):?><select name="domain" onchange="this.form.submit()"><option value="0">all domains</option><?php foreach($domains as $d):?><option value="<?=$d['id']?>"<?=(int)($dom ?? 0) === (int)$d['id'] ? ' selected' : ''?>><?=Helpers::e($d['domain'])?></option><?php endforeach;?></select><?php endif;?>
<button class="btn small">Search</button><?php if(($q ?? '') !== '' || (int)($dom ?? 0) > 0):?> <a class="btn small" href="/email">Clear (<?=Helpers::e((string)($total ?? 0))?> match)</a><?php endif;?></form>
<table class="mboxes"><tr><th>Mailbox</th><th>Usage</th><th class="hide-sm">Spam flag · limit</th><th></th></tr>
<?php foreach($emails as $e): $addr = $e['localpart'].'@'.$e['domain']; $qmb = max(1, (int)$e['quota_mb']); $pct = $e['used_mb'] === null ? null : min(100, (int)round((float)$e['used_mb'] * 100 / $qmb)); ?><tr><td style="word-break:break-all"><b><?=Helpers::e($addr)?></b><?php if(($e['send_hold_at'] ?? null) !== null):?><br><span class="badge" style="background:var(--danger,#c0392b);color:#fff" title="<?=Helpers::e('Held '.$e['send_hold_at'].' — '.($e['send_hold_reason'] ?? ''))?>">⛔ sending held</span> <small style="color:var(--muted)">suspected compromise · a new password restores sending</small><?php if(($user['role'] ?? '') === 'admin'):?> <form method="post" action="/email/guard-release" style="display:inline" onsubmit="return confirm('Release without a password change? Only for a false alarm — a real thief keeps sending.')"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="id" value="<?=$e['id']?>"><button class="btn small">Release</button></form><?php endif;?><?php endif;?></td>
<td style="min-width:120px"><small><?= $e['used_mb'] === null ? '—' : Helpers::e($e['used_mb']) ?> / <?=(int)$e['quota_mb']?> MB<?= $pct === null ? '' : ' · '.$pct.'%' ?></small><?php if($pct !== null):?><div class="bar mbar"><i class="<?= $pct >= 90 ? 'hot' : ($pct >= 75 ? 'warm' : '') ?>" style="width:<?=max(1, $pct)?>%"></i></div><?php endif;?></td>
<td class="hide-sm"><small>≥ <?=Helpers::e((string)($e['spam_threshold'] ?? 5))?> · <?= ($e['smtp_limit'] ?? null) === null ? 'default' : (int)$e['smtp_limit'] ?>/hr</small></td>
<td style="text-align:right;white-space:nowrap"><a class="btn small" href="<?=Helpers::e($webmail)?>" target="_blank" rel="noopener" title="Open <?=Helpers::e($addr)?> in Roundcube">📬<span class="hide-sm"> Webmail</span></a>
<details class="emenu"><summary class="btn small" title="Manage <?=Helpers::e($addr)?>">⋯<span class="hide-sm"> Manage</span></summary><div class="emenu-pop">
<b style="word-break:break-all"><?=Helpers::e($addr)?></b>
<form method="post" action="/email/password"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="id" value="<?=$e['id']?>"><label>🔑 Set password</label>
<div class="row"><input name="password" type="password" autocomplete="new-password" placeholder="new (8+)" required minlength="8" maxlength="128"><input name="password2" type="password" autocomplete="new-password" placeholder="again" required minlength="8" maxlength="128"><button class="btn small">Save</button></div></form>
<form method="post" action="/email/quota"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="id" value="<?=$e['id']?>"><label>💾 Quota (MB, max <?=$qmax?>)</label>
<div class="row"><input name="quota_mb" type="number" min="10" max="<?=$qmax?>" value="<?=(int)$e['quota_mb']?>" required><button class="btn small">Set</button></div></form>
<form method="post" action="/email/threshold"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="id" value="<?=$e['id']?>"><label>🚩 Spam flag at score ≥ <small style="color:var(--muted)">(X-Spam-Flag; lower = stricter)</small></label>
<div class="row"><input name="threshold" value="<?=Helpers::e((string)($e['spam_threshold'] ?? 5))?>" inputmode="decimal"><button class="btn small">Set</button></div></form>
<form method="post" action="/email/limit"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="id" value="<?=$e['id']?>"><label>📤 Sends per hour <small style="color:var(--muted)">(blank = default <?=Helpers::e($smtpDefault ?? '200')?>)</small></label>
<div class="row"><input name="limit" value="<?=Helpers::e((string)($e['smtp_limit'] ?? ''))?>" placeholder="default" inputmode="numeric"><button class="btn small">Set</button></div></form>
<div class="row"><form method="post" action="/email/reset"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="id" value="<?=$e['id']?>"><button class="btn small" title="Generate a random password (shown once)">🎲 Random password</button></form>
<a class="btn small" href="/email/import?id=<?=$e['id']?>" title="Copy mail from an old IMAP account into <?=Helpers::e($addr)?>">📥 Import mail</a>
<form method="post" action="/email/delete" onsubmit="return confirm('Delete <?=Helpers::e($addr)?>? Its stored mail goes too.')"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="id" value="<?=$e['id']?>"><button class="btn small danger">Delete</button></form></div>
</div></details></td></tr><?php endforeach;?>
<?php if(empty($emails)):?><tr><td colspan="4" style="color:var(--muted)">No mailboxes<?= (($q ?? '') !== '' || (int)($dom ?? 0) > 0) ? ' match' : ' yet' ?>.<?php if(empty($domains)):?> Add a domain first, then create mailboxes here.<?php endif;?></td></tr><?php endif;?></table>
<?php $pagerBase = '/email?'.(($q ?? '') !== '' ? 'q='.urlencode($q).'&' : '').((int)($dom ?? 0) > 0 ? 'domain='.(int)$dom.'&' : ''); $pagerLabel = 'mailboxes'; require __DIR__.'/_pager.php'; ?></div>
<?php if(!$isAdmin) echo $limitsCard; ?>
</section>
<section class="etab" id="etab-rules">
<?php $csrf = Helpers::csrf(); $boxOpts = function(int $sel = 0) use ($boxes) { foreach($boxes ?? [] as $b):?><option value="<?=$b['id']?>"<?=$sel === (int)$b['id'] ? ' selected' : ''?>><?=Helpers::e($b['localpart'].'@'.$b['domain'])?></option><?php endforeach; }; ?>
<div class="card"><h3>↪️ Forwarders <small style="color:var(--muted)">send an address on to other addresses — tick <b>keep a copy</b> to also deliver it to the mailbox of the same name. <code>*</code> as the name = catch-all (only for addresses with no mailbox)</small></h3>
<form class="inline" method="post" action="/email/forward-create">
<input type="hidden" name="csrf" value="<?=$csrf?>">
<input name="flocal" placeholder="sales" required>
<select name="domain_id"><?php foreach($domains as $d):?><option value="<?=$d['id']?>">@<?=Helpers::e($d['domain'])?></option><?php endforeach;?></select>
<input name="targets" placeholder="you@gmail.com, boss@firm.com" required style="min-width:240px">
<label style="display:inline-flex;align-items:center;gap:4px" title="Needs a mailbox with the same address"><input type="checkbox" name="keep" value="1"> keep a copy</label>
<button class="btn">Add forwarder</button></form>
<table><tr><th>From</th><th>To</th><th></th></tr>
<?php foreach($forwards ?? [] as $f): $faddr = $f['localpart'].'@'.$f['domain']; ?><tr><td style="word-break:break-all"><?=Helpers::e($faddr)?><?php if($f['localpart'] === '*'):?> <span class="badge">catch-all</span><?php endif;?></td><td style="word-break:break-all"><?=Helpers::e($f['shown'])?><?php if(!empty($f['keep'])):?> <span class="badge" title="Also delivered to the <?=Helpers::e($faddr)?> mailbox">+ copy kept</span><?php endif;?></td>
<td style="text-align:right;white-space:nowrap"><details class="emenu"><summary class="btn small">✏️<span class="hide-sm"> Edit</span></summary><div class="emenu-pop">
<form method="post" action="/email/forward-create"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="flocal" value="<?=Helpers::e($f['localpart'])?>"><input type="hidden" name="domain_id" value="<?=(int)$f['domain_id']?>">
<label><b style="word-break:break-all"><?=Helpers::e($faddr)?></b> forwards to</label>
<textarea name="targets" rows="3" required style="width:100%"><?=Helpers::e(str_replace(', ', "\n", $f['shown']))?></textarea>
<?php if($f['localpart'] !== '*'):?><label style="display:flex;gap:6px;align-items:center;margin:6px 0"><input type="checkbox" name="keep" value="1"<?=!empty($f['keep']) ? ' checked' : ''?>> keep a copy in the mailbox</label><?php endif;?>
<button class="btn small">Save</button></form></div></details>
<form method="post" action="/email/forward-delete" style="display:inline" onsubmit="return confirm('Delete forwarder <?=Helpers::e($faddr)?>?')"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="id" value="<?=$f['id']?>"><button class="btn small danger">Delete</button></form></td></tr><?php endforeach;?>
<?php if(empty($forwards ?? [])):?><tr><td colspan="3" style="color:var(--muted)">No forwarders yet.</td></tr><?php endif;?></table></div>
<?php $fieldNames = ['subject' => 'subject', 'from' => 'from', 'to' => 'to', 'cc' => 'cc', 'spam' => 'spam score']; $typeNames = ['contains' => 'contains', 'is' => 'is', 'starts' => 'starts with'];
$filterForm = function(?array $f) use ($csrf, $boxOpts, $fieldNames, $typeNames) { $fld = $f['field'] ?? 'subject'; $act = $f['action'] ?? 'folder'; ?>
<form class="<?= $f ? '' : 'inline ' ?>ffilter<?= $fld === 'spam' ? ' isspam' : '' ?><?= $act === 'discard' ? ' isdiscard' : '' ?>" method="post" action="/email/filter-create">
<input type="hidden" name="csrf" value="<?=$csrf?>"><?php if($f):?><input type="hidden" name="id" value="<?=(int)$f['id']?>"><?php endif;?>
<select name="mailbox_id"><?php $boxOpts((int)($f['box_id'] ?? 0)); ?></select>
<select name="field" data-ffield><?php foreach($fieldNames as $k => $v):?><option value="<?=$k?>"<?=$fld === $k ? ' selected' : ''?>><?=$v?></option><?php endforeach;?></select>
<select name="match_type" class="nospam"><?php foreach($typeNames as $k => $v):?><option value="<?=$k?>"<?=($f['match_type'] ?? 'contains') === $k ? ' selected' : ''?>><?=$v?></option><?php endforeach;?></select>
<span class="isspam-only">≥</span><input name="match" value="<?=Helpers::e($f['match_text'] ?? '')?>" placeholder="<?= $fld === 'spam' ? 'score, e.g. 5' : 'text…' ?>" required maxlength="160" style="min-width:120px">
<select name="action" data-faction><option value="folder"<?=$act === 'folder' ? ' selected' : ''?>>→ folder</option><option value="redirect"<?=$act === 'redirect' ? ' selected' : ''?>>→ forward</option><option value="discard"<?=$act === 'discard' ? ' selected' : ''?>>discard</option></select>
<input name="target" class="ftarget" value="<?=Helpers::e($f['target'] ?? '')?>" placeholder="folder or address" maxlength="190" style="min-width:130px">
<button class="btn<?= $f ? ' small' : '' ?>"><?= $f ? 'Save' : 'Add filter' ?></button></form>
<?php }; ?>
<div class="card"><h3>🧹 Filters <small style="color:var(--muted)">server-side rules per mailbox, first match wins — file into a folder, forward on, or discard. For from/to/cc, <i>is</i> and <i>starts with</i> compare the address; <i>contains</i> also searches the name</small></h3>
<?php $filterForm(null); ?>
<table><tr><th>Mailbox</th><th>If</th><th>Then</th><th></th></tr>
<?php $boxIds = []; foreach($boxes ?? [] as $b) $boxIds[$b['localpart'].'@'.$b['domain']] = (int)$b['id'];
foreach($filters ?? [] as $f): $f['box_id'] = $boxIds[$f['localpart'].'@'.$f['domain']] ?? 0; $on = (int)($f['active'] ?? 1) === 1; ?><tr<?= $on ? '' : ' style="opacity:.55"' ?>><td style="word-break:break-all"><?=Helpers::e($f['localpart'].'@'.$f['domain'])?><?php if(!$on):?> <span class="badge">paused</span><?php endif;?></td>
<td><?php if($f['field'] === 'spam'):?>spam score ≥ <code><?=Helpers::e($f['match_text'])?></code><?php else:?><code><?=Helpers::e($f['field'])?></code> <?=Helpers::e($typeNames[$f['match_type'] ?? 'contains'] ?? 'contains')?> <code><?=Helpers::e($f['match_text'])?></code><?php endif;?></td>
<td><?=Helpers::e(['folder' => 'folder', 'redirect' => 'forward', 'discard' => 'discard'][$f['action']] ?? $f['action'])?><?php if($f['action'] !== 'discard'):?> → <code><?=Helpers::e($f['target'])?></code><?php endif;?></td>
<td style="text-align:right;white-space:nowrap"><form method="post" action="/email/filter-toggle" style="display:inline"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="id" value="<?=$f['id']?>"><button class="btn small" title="<?= $on ? 'Pause (keep the rule, stop applying it)' : 'Apply this rule again' ?>"><?= $on ? '⏸' : '▶' ?></button></form>
<details class="emenu"><summary class="btn small">✏️<span class="hide-sm"> Edit</span></summary><div class="emenu-pop" style="width:340px"><?php $filterForm($f); ?></div></details>
<form method="post" action="/email/filter-delete" style="display:inline" onsubmit="return confirm('Delete this filter?')"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="id" value="<?=$f['id']?>"><button class="btn small danger">Delete</button></form></td></tr><?php endforeach;?>
<?php if(empty($filters ?? [])):?><tr><td colspan="4" style="color:var(--muted)">No filters yet.</td></tr><?php endif;?></table></div>
<div class="card"><h3>🏖️ Autoresponders <small style="color:var(--muted)">vacation reply, original still delivered — one reply per sender per 7 days. Optional dates switch it on and off by themselves. Not sent while a forwarder takes the address (unless it keeps a copy).</small></h3>
<?php $arForm = function(?array $r) use ($csrf, $boxOpts) { ?><form method="post" action="/email/autoresponder">
<input type="hidden" name="csrf" value="<?=$csrf?>">
<div class="inline"><?php if($r):?><input type="hidden" name="id" value="<?=(int)$r['id']?>"><?php else:?><select name="id"><?php $boxOpts(); ?></select><?php endif;?>
<input name="subject" value="<?=Helpers::e($r['autoresp_subject'] ?? '')?>" placeholder="Subject (max 190)" required maxlength="190" style="min-width:200px">
<label style="display:inline-flex;align-items:center;gap:4px">from <input type="date" name="start" value="<?=Helpers::e((string)($r['autoresp_start'] ?? ''))?>"></label>
<label style="display:inline-flex;align-items:center;gap:4px">until <input type="date" name="end" value="<?=Helpers::e((string)($r['autoresp_end'] ?? ''))?>"></label></div>
<textarea name="text" placeholder="Message (max 2000 chars)" required maxlength="2000" rows="<?= $r ? 5 : 3 ?>" style="width:100%;margin-top:6px"><?=Helpers::e((string)($r['autoresp_text'] ?? ''))?></textarea>
<button class="btn<?= $r ? ' small' : '' ?>"><?= $r ? 'Save' : 'Save auto-reply' ?></button></form><?php }; ?>
<?php $arForm(null); ?>
<table><tr><th>Mailbox</th><th>Subject</th><th>When</th><th></th></tr>
<?php $today = date('Y-m-d'); foreach($responders ?? [] as $r): $st = (string)($r['autoresp_start'] ?? ''); $en = (string)($r['autoresp_end'] ?? '');
$live = ($st === '' || $st <= $today) && ($en === '' || $en >= $today); ?><tr><td style="word-break:break-all"><?=Helpers::e($r['localpart'].'@'.$r['domain'])?></td><td><?=Helpers::e($r['autoresp_subject'])?></td>
<td><small><?php if($en !== '' && $en < $today):?><span class="pill off">ended <?=Helpers::e($en)?></span><?php elseif(!$live):?><span class="pill warn">from <?=Helpers::e($st)?></span><?php else:?><span class="pill ok">on</span><?php endif;?><?= $en !== '' && $en >= $today ? ' until '.Helpers::e($en) : '' ?></small></td>
<td style="text-align:right;white-space:nowrap"><details class="emenu"><summary class="btn small">✏️<span class="hide-sm"> Edit</span></summary><div class="emenu-pop" style="width:420px"><b style="word-break:break-all"><?=Helpers::e($r['localpart'].'@'.$r['domain'])?></b><?php $arForm($r); ?></div></details>
<form method="post" action="/email/autoresponder-delete" style="display:inline" onsubmit="return confirm('Turn OFF auto-reply for <?=Helpers::e($r['localpart'].'@'.$r['domain'])?>?')"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="id" value="<?=$r['id']?>"><button class="btn small danger">Turn off</button></form></td></tr><?php endforeach;?>
<?php if(empty($responders ?? [])):?><tr><td colspan="4" style="color:var(--muted)">No auto-replies set.</td></tr><?php endif;?></table></div>
<div class="card"><h3>📣 Mailing lists <small style="color:var(--muted)">distribution groups — only subscribers can post (others get a bounce), up to 200 members</small></h3>
<form class="inline" method="post" action="/email/list-create">
<input type="hidden" name="csrf" value="<?=$csrf?>">
<input name="llocal" placeholder="team" required>
<select name="domain_id"><?php foreach($domains as $d):?><option value="<?=$d['id']?>">@<?=Helpers::e($d['domain'])?></option><?php endforeach;?></select>
<button class="btn">Create list</button></form>
<?php foreach($lists ?? [] as $l): $laddr = $l['localpart'].'@'.$l['domain']; $n = count($l['members'] ?? []); ?><details class="lst" style="margin-top:10px;border:1px solid var(--line);border-radius:10px;padding:8px 10px"><summary style="cursor:pointer"><b style="word-break:break-all"><?=Helpers::e($laddr)?></b> <small style="color:var(--muted)"><?=$n?>/200 members</small></summary>
<div style="margin-top:8px;display:grid;gap:8px">
<form method="post" action="/email/member-add"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="id" value="<?=$l['id']?>">
<textarea name="address" rows="3" required placeholder="member@example.com — paste many: one per line, or separated by commas" style="width:100%"></textarea>
<button class="btn small">Subscribe</button></form>
<?php if($n):?><div style="display:flex;flex-wrap:wrap;gap:6px"><?php foreach($l['members'] as $m):?><span class="badge" style="display:inline-flex;align-items:center;gap:4px"><?=Helpers::e($m['address'])?><form method="post" action="/email/member-delete" style="display:inline"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="mid" value="<?=$m['id']?>"><button class="btn small danger" style="padding:0 6px" title="Unsubscribe <?=Helpers::e($m['address'])?>">×</button></form></span><?php endforeach;?></div><?php else:?><small style="color:var(--muted)">No members yet.</small><?php endif;?>
<form method="post" action="/email/list-delete" onsubmit="return confirm('Delete list <?=Helpers::e($laddr)?>?')"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="id" value="<?=$l['id']?>"><button class="btn small danger">Delete list</button></form>
</div></details>
<?php endforeach;?>
<?php if(empty($lists ?? [])):?><p style="color:var(--muted)">No lists yet.</p><?php endif;?>
<?php if(($user['role'] ?? '') === 'admin'):?><form class="inline" method="post" action="/email/list-default" style="margin-top:10px">
<input type="hidden" name="csrf" value="<?=$csrf?>">
<small style="color:var(--muted)">List rate (all lists): </small><input name="default" value="<?=Helpers::e($listDefault ?? '10')?>" pattern="[0-9]*" style="max-width:80px" title="Posts per hour per list (fanout is per member)"><small style="color:var(--muted)"> posts/hr per list</small>
<button class="btn small">Set</button></form><?php endif;?></div>
</section>
<section class="etab" id="etab-auth">
<div class="card"><h3>🔏 Mail authentication <small style="color:var(--muted)">SPF + DKIM + DMARC prove mail is really yours · MTA-STS makes senders use TLS · reports show who sends as you</small></h3>
<?php $csrf = Helpers::csrf(); $maxPts = ['spf' => 25, 'dkim' => 25, 'dmarc' => 20, 'mx' => 15, 'tls' => 15];
$chkNames = ['spf' => 'SPF', 'dkim' => 'DKIM', 'dmarc' => 'DMARC', 'mx' => 'MX', 'tls' => 'TLS', 'mtasts' => 'MTA-STS', 'tlsrpt' => 'TLS reports'];
$copyJs = "navigator.clipboard&&navigator.clipboard.writeText(this.dataset.v);var b=this,t=b.textContent;b.textContent='✓ copied';setTimeout(function(){b.textContent=t},1200)"; ?>
<?php if(empty($auth)):?><p style="color:var(--muted)">No domains yet.</p><?php endif;?>
<div class="authgrid">
<?php foreach($auth ?? [] as $a): $m = $a['mta_sts'] ?? 'off'; $remote = !empty($a['remote']); $sc = $scores[$a['domain']] ?? null;
$dp = ''; if(!empty($a['dmarcval']) && preg_match('/(?:^|;)\s*p=([a-z]+)/i', $a['dmarcval'], $mm)) $dp = strtolower($mm[1]);
$scoreCls = $sc ? ((int)$sc['score'] >= 85 ? 'ok' : 'err') : 'off';
$chip = function(string $label, string $cls, string $title = '') { ?><span class="pill <?=$cls?>"<?= $title !== '' ? ' title="'.Helpers::e($title).'"' : '' ?>><?=$label?></span><?php }; ?>
<div class="authcard">
<h4><span><?=Helpers::e($a['domain'])?></span><span class="pill <?=$scoreCls?>" title="Deliverability score (last Check)"><?= $sc ? (int)$sc['score'].'/100' : 'not checked' ?></span></h4>
<div class="chips">
<?php if($remote) $chip('mail handled remotely', 'off', 'MX points at an external provider — set these records there');
$chip(($a['spf'] ? '✓' : '✗').' SPF', $a['spf'] ? 'ok' : 'err', $a['spf'] ? $a['spfval'] : 'No SPF record');
$chip(($a['dkim'] ? '✓' : '✗').' DKIM', $a['dkim'] ? 'ok' : 'err', $a['dkim'] ? 'Outgoing mail is signed' : 'Not signing yet');
if(!empty($a['dmarcinh'])) $chip('✓ DMARC inherited', 'ok', 'p='.$a['dmarcinh']);
elseif(!empty($a['dmarc'])) $chip('✓ DMARC'.($dp !== '' ? ' p='.Helpers::e($dp) : ''), $dp === 'none' ? 'err' : 'ok', ($dp === 'none' ? 'p=none only monitors — nothing is protected. ' : '').$a['dmarcval']);
else $chip('✗ DMARC', 'err', 'No DMARC record');
$chip(['off' => 'MTA-STS off', 'testing' => '🧪 MTA-STS testing', 'enforce' => '🔒 MTA-STS enforce', 'none' => 'MTA-STS winding down'][$m] ?? 'MTA-STS off', $m === 'enforce' ? 'ok' : ($m === 'off' ? 'off' : 'warn'));
$chip(!empty($a['reports']) ? '📊 reports on' : 'reports off', !empty($a['reports']) ? 'ok' : 'off'); ?>
</div>
<?php if(!empty($a['extns'])): $dmv = !empty($a['dmarcval']) ? $a['dmarcval'] : MailDns::DMARC_DEFAULT; ?><div class="alert warn" style="margin:8px 0;font-size:13px">🌐 <b>Your nameservers are with your domain registrar</b> (<?=Helpers::e($a['extns'])?>), not this server. The ticks above are this server's copy; the internet only sees what is set at your registrar, so add these records there<?= !empty($a['zone']) ? ' (in '.Helpers::e($a['origin']).')' : '' ?>. <b>🔎 Check</b> shows what is really public.
<div style="display:flex;flex-wrap:wrap;gap:6px;margin-top:6px"><button type="button" class="btn small" data-v="<?=Helpers::e($a['spfval'])?>" onclick="<?=$copyJs?>" title="TXT at <?=Helpers::e($a['hosts']['spf'] ?? '@')?>">📋 SPF (TXT at <?=Helpers::e($a['hosts']['spf'] ?? '@')?>)</button>
<?php if(empty($a['dmarcinh'])):?><button type="button" class="btn small" data-v="<?=Helpers::e($dmv)?>" onclick="<?=$copyJs?>" title="<?=Helpers::e($dmv)?>">📋 DMARC (TXT at <?=Helpers::e($a['hosts']['dmarc'] ?? '_dmarc')?>)</button><?php endif;?>
<?php if($a['dkim'] && $a['txt'] !== ''):?><small style="color:var(--muted);align-self:center">+ the DKIM key below</small><?php endif;?></div></div><?php endif;?>
<?php if(!$a['spf'] || empty($a['dmarc'])):?><small style="color:var(--muted)">Missing: <?php if(!$a['spf']):?><code>v=spf1 a mx ~all</code> at <code><?=Helpers::e($a['hosts']['spf'] ?? '@')?></code><?php endif;?><?php if(!$a['spf'] && empty($a['dmarc'])):?> · <?php endif;?><?php if(empty($a['dmarc'])):?><code><?=Helpers::e(MailDns::DMARC_DEFAULT)?></code> at <code><?=Helpers::e($a['hosts']['dmarc'] ?? '_dmarc')?></code><?php endif;?><?= !empty($a['zone']) ? ' in the '.Helpers::e($a['zone']).' zone' : '' ?></small><?php endif;?>
<div class="row" style="display:flex;flex-wrap:wrap;gap:6px">
<form method="post" action="/email/score" style="display:inline"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="domain_id" value="<?=$a['id']?>"><button class="btn small" title="Look the records up in public DNS and score them">🔎 Check</button></form>
<?php if(!$a['dkim'] || (!$remote && (!$a['spf'] || empty($a['dmarc'])))):?><form method="post" action="/email/dkim" style="display:inline"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="domain_id" value="<?=$a['id']?>"><button class="btn small" title="DKIM key + any missing SPF / DMARC (<?=Helpers::e(MailDns::DMARC_DEFAULT)?>)">✍️ <?= $a['dkim'] ? 'Add missing' : 'Sign' ?></button></form><?php endif;?>
<?php if($a['dkim'] && $a['txt'] !== ''):?><button type="button" class="btn small" data-v="<?=Helpers::e($a['txt'])?>" onclick="<?=$copyJs?>" title="TXT at <?=Helpers::e($a['hosts']['dkim'] ?? 'default._domainkey')?>">📋 Copy DKIM key</button><?php endif;?>
</div>
<?php if($sc && trim((string)($sc['details'] ?? '')) !== ''):?><details><summary style="cursor:pointer;color:var(--muted);font-size:13px">Score details · checked <?=Helpers::e(substr($sc['checked_at'] ?? '', 0, 16))?></summary><ul class="checklist" style="margin-top:6px">
<?php foreach(explode("\n", (string)$sc['details']) as $ln): if(!preg_match('/^([a-z]+)=(\d+) (.*)$/', trim($ln), $dm)) continue; $mx = $maxPts[$dm[1]] ?? 0; $pt = (int)$dm[2];
$ic = $mx === 0 ? 'ℹ️' : ($pt >= $mx ? '✅' : ($pt > 0 ? '⚠️' : '❌')); ?><li><span><?=$ic?></span><span><b><?=Helpers::e($chkNames[$dm[1]] ?? strtoupper($dm[1]))?></b><?= $mx ? ' <small style="color:var(--muted)">'.$pt.'/'.$mx.'</small>' : '' ?> — <?=Helpers::e($dm[3])?></span></li><?php endforeach;?>
</ul></details><?php endif;?>
<?php if(!$remote):?><div style="border-top:1px solid var(--line);padding-top:8px;display:grid;gap:6px">
<div class="row" style="display:flex;flex-wrap:wrap;gap:6px;align-items:center"><small style="color:var(--muted);min-width:90px">DMARC reports</small>
<form method="post" action="/email/reports-toggle" style="display:inline"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="domain_id" value="<?=$a['id']?>"><button class="btn small<?= !empty($a['reports']) ? ' danger' : '' ?>"<?= !empty($a['reports']) ? ' onclick="return confirm(\'Stop collecting reports? Stored reports are kept.\')"' : '' ?>><?= !empty($a['reports']) ? 'Stop' : 'Collect' ?></button></form>
<?php if(!empty($a['reports'])):?><a class="btn small" href="/email/reports?domain_id=<?=(int)$a['id']?>">View</a><?php endif;?>
<?php if(!empty($a['ap'])):?><a class="badge" style="text-decoration:none" href="/email/reports?domain_id=<?=(int)$a['id']?>" title="The reports say this step is safe. Open to review and apply.">🧭 ready: <?=Helpers::e(implode(' · ', $a['ap']))?></a><?php endif;?></div>
<div class="row" style="display:flex;flex-wrap:wrap;gap:6px;align-items:center"><small style="color:var(--muted);min-width:90px">MTA-STS</small>
<?php $btn = function(string $mode, string $label, string $cls = '', string $confirm = '') use ($a, $csrf) { ?><form method="post" action="/email/mta-sts" style="display:inline"<?= $confirm !== '' ? ' onsubmit="return confirm(\''.Helpers::e($confirm).'\')"' : '' ?>><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="domain_id" value="<?=$a['id']?>"><input type="hidden" name="mode" value="<?=$mode?>"><button class="btn small<?=$cls?>"><?=$label?></button></form><?php }; ?>
<?php if($m === 'off'): $btn('testing', 'Publish (testing)'); endif;?>
<?php if($m === 'testing'): $btn('check', 'Check'); $btn('enforce', 'Enforce', '', 'Enforce MTA-STS? Senders will REFUSE delivery if TLS to your MX ever breaks (cached up to 7 days). Checks run first.'); $btn('none', 'Step down'); $btn('remove', 'Remove', ' danger'); endif;?>
<?php if($m === 'enforce'): $btn('check', 'Check'); $btn('none', 'Step down', ' danger', 'Step down to none? Keep it published 7 days before removing.'); endif;?>
<?php if($m === 'none'): $btn('testing', 'Re-test'); $btn('remove', 'Remove', ' danger', 'Remove the MTA-STS policy?'); endif;?></div>
</div><?php endif;?>
</div>
<?php endforeach;?>
</div>
<p style="margin:10px 0 0"><small style="color:var(--muted)">MTA-STS: start with <b>testing</b> (never blocks mail), re-issue SSL so <code>mta-sts.&lt;domain&gt;</code> is covered, then <b>Check</b>; enforce only after TLS reports show senders succeed. DKIM signs outgoing mail as soon as the key exists — the TXT only counts once it is published in public DNS.</small></p>
<details style="margin-top:8px"><summary style="cursor:pointer;color:var(--muted)">Using external nameservers? Add these records at your registrar — nothing here counts publicly until then</summary>
<div style="margin-top:6px;display:grid;gap:10px">
<?php $authList = array_values($auth ?? []); $authHead = array_slice($authList, 0, 4); $authRest = array_slice($authList, 4); ?>
<?php foreach($authHead as $a):?><?php require __DIR__.'/_registrar.php'; ?><?php endforeach;?>
<?php if(!empty($authRest)):?><details style="margin-top:6px"><summary style="cursor:pointer;color:var(--muted)">Show all <?=count($authList)?> domains (<?=count($authRest)?> more)</summary>
<?php foreach($authRest as $a):?><?php require __DIR__.'/_registrar.php'; ?><?php endforeach;?></details><?php endif;?></div></details></div>
</section>
<?php if($isAdmin):?><section class="etab" id="etab-server">
<?php $csrf = Helpers::csrf(); $rl = ($relay ?? null); $qd = $queue ?? ['queued' => '?', 'frozen' => '?']; ?>
<div class="tiles" style="margin-bottom:14px">
<a class="stat-card" href="/admin/mail" title="Open the mail queue"><small style="color:var(--muted)">📮 Queue</small><br><b><?=Helpers::e((string)$qd['queued'])?></b> <small style="color:var(--muted)">messages</small></a>
<a class="stat-card" href="/admin/mail" title="Frozen = undeliverable bounces or held mail"><small style="color:var(--muted)">🧊 Frozen</small><br><b<?= ctype_digit((string)$qd['frozen']) && (int)$qd['frozen'] > 0 ? ' style="color:#fbbf24"' : '' ?>><?=Helpers::e((string)$qd['frozen'])?></b></a>
<a class="stat-card" href="#held"><small style="color:var(--muted)">⛔ Held mailboxes</small><br><b<?= !empty($held) ? ' style="color:#f87171"' : '' ?>><?=count($held ?? [])?></b></a>
<div class="stat-card"><small style="color:var(--muted)">📡 Outbound</small><br><b style="font-size:15px"><?= !empty($rl['on']) ? 'via relay' : 'direct MX' ?></b></div>
<div class="stat-card"><small style="color:var(--muted)">🛡️ Mail guard</small><br><b style="font-size:15px"><?= ($mailGuard ?? '1') === '1' ? 'on' : 'off' ?></b></div>
</div>
<?php if(!empty($held)):?><div class="card" id="held"><h3>⛔ Held mailboxes <small style="color:var(--muted)">outgoing mail stopped as a suspected compromise — a new password (owner) is the cure; Release only for a false alarm</small></h3>
<table><tr><th>Mailbox</th><th>Owner</th><th>Held</th><th></th></tr>
<?php foreach($held as $h):?><tr><td style="word-break:break-all"><?=Helpers::e($h['localpart'].'@'.$h['domain'])?></td><td><?=Helpers::e($h['username'])?></td><td><small><?=Helpers::e(substr((string)$h['send_hold_at'], 0, 16))?><br><span style="color:var(--muted)"><?=Helpers::e((string)$h['send_hold_reason'])?></span></small></td>
<td><form method="post" action="/email/guard-release" onsubmit="return confirm('Release without a password change? Only for a false alarm — a real thief keeps sending.')"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="id" value="<?=(int)$h['id']?>"><button class="btn small">Release</button></form></td></tr><?php endforeach;?></table></div>
<?php endif;?>
<?php echo $limitsCard; ?>
<div class="card"><h3>🛡️ Spam scanning <small style="color:var(--muted)">spamd costs CPU per message — optionally skip it Sat/Sun when nobody triages</small></h3>
<form class="inline" method="post" action="/email/weekend-skip">
<input type="hidden" name="csrf" value="<?=$csrf?>">
<label style="display:inline-flex;align-items:center;gap:6px;margin-right:8px"><input type="checkbox" name="on" value="1" <?=($weekendSkip ?? '0') === '1' ? 'checked' : ''?>> Skip spam scanning on weekends</label>
<button class="btn">Save</button> <span class="pill <?=($weekendSkip ?? '0') === '1' ? 'warn' : 'ok'?>"><?=($weekendSkip ?? '0') === '1' ? 'skipping Sat/Sun' : 'scanning every day'?></span></form>
<small style="color:var(--muted)">Malware rejects, rate caps and relay policy always apply · skipped mail is marked <code>X-Spam-Skipped</code> and stays in the inbox (no <code>X-Spam-Flag</code>).</small></div>
<div class="card"><h3>📡 SMTP relay <small style="color:var(--muted)">smarthost for port-25-blocked networks — all outbound goes via the relay when on</small></h3>
<?php if(!empty($rl) && !empty($rl['on'])):?><p style="display:flex;flex-wrap:wrap;gap:8px;align-items:center">Status: <span class="pill ok">relaying via <?=Helpers::e($rl['host'] ?? '')?>:<?=Helpers::e($rl['port'] ?? '')?> as <?=Helpers::e($rl['user'] ?? '')?></span>
<form class="inline" method="post" action="/email/relay-clear" onsubmit="return confirm('Disable the relay? Mail goes straight to each MX again.')"><input type="hidden" name="csrf" value="<?=$csrf?>"><button class="btn small danger">Disable relay</button></form></p>
<?php else:?><p style="color:var(--muted)">Status: direct MX delivery. Saving turns the relay on immediately.</p><?php endif;?>
<details<?= empty($rl['on']) ? ' open' : '' ?>><summary style="cursor:pointer;color:var(--muted)"><?= !empty($rl['on']) ? 'Change relay settings' : 'Relay settings' ?></summary>
<form class="inline" method="post" action="/email/relay-save" style="margin-top:8px"><input type="hidden" name="csrf" value="<?=$csrf?>">
<input name="host" placeholder="smtp.example.com" required value="<?=Helpers::e($rl['host'] ?? '')?>" style="min-width:170px">
<input name="port" placeholder="587" required value="<?=Helpers::e($rl['port'] ?? '587')?>" pattern="[0-9]*" style="max-width:70px" title="587 STARTTLS, 465 implicit TLS">
<input name="user" placeholder="login" required value="<?=Helpers::e($rl['user'] ?? '')?>" style="max-width:150px" autocomplete="off">
<input name="pass" type="password" placeholder="password (no : allowed)" required autocomplete="new-password" style="max-width:150px">
<button class="btn">Save &amp; enable</button></form></details>
<form class="inline" method="post" action="/email/relay-test" style="margin-top:10px"><input type="hidden" name="csrf" value="<?=$csrf?>">
<input name="to" type="email" placeholder="you@example.com" required style="min-width:200px" value="<?=Helpers::e((string)($relayResult['to'] ?? ''))?>">
<button class="btn small">Send test mail</button></form>
<?php if(!empty($relayResult)):?><div style="margin-top:8px;border:1px solid <?= $relayResult['ok'] ? 'var(--ok)' : 'var(--err)' ?>;border-radius:10px;padding:8px 10px">
<b><?= $relayResult['ok'] ? '✅ Test mail handed over' : '❌ Test failed' ?></b> <small style="color:var(--muted)">to <?=Helpers::e($relayResult['to'])?> at <?=Helpers::e($relayResult['at'])?><?= $relayResult['ok'] ? ' — check it arrived (and its spam folder)' : '' ?></small>
<?php if($relayResult['out'] !== ''):?><pre style="margin:6px 0 0;white-space:pre-wrap;word-break:break-all;font-size:12px;max-height:200px;overflow:auto"><?=Helpers::e($relayResult['out'])?></pre><?php endif;?></div><?php endif;?>
<small style="color:var(--muted);display:block;margin-top:8px">Password travels file-only (never shown, never stored in the panel DB) · 465 = implicit TLS, anything else STARTTLS (required, never plaintext).</small></div>
</section><?php endif;?>

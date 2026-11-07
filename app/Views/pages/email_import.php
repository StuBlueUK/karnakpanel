<?php /* Mailbox import from another IMAP server (EmailController::importPage) */
$addr = $box['localpart'].'@'.$box['domain']; $csrf = Helpers::csrf(); $bid = (int)$box['id'];
$live = null;
foreach ($jobs as $j) if (in_array(MailImport::stage($j), ['checking', 'running'], true)) { $live = $j; break; }
$label = ['checking' => 'checking login', 'running' => 'copying', 'done' => 'done', 'full' => 'mailbox full', 'failed' => 'failed', 'cancelled' => 'cancelled', 'stalled' => 'interrupted — run it again']; ?>
<h2>📥 Import mail <small style="color:var(--muted)"><?=Helpers::e($addr)?></small></h2>
<div class="card"><a class="btn small" href="/email">← Email</a>
<p>Copies every folder from your old mailbox into <b><?=Helpers::e($addr)?></b>. The old mailbox is only read, never changed. Mail already here is kept, and running the import again fetches only what is new (mail you deleted here since an earlier import comes back; a folder you deleted here is not recreated).</p></div>

<?php if(!$supported):?>
<div class="card"><p>Mailbox import isn't available on this server yet. <small style="color:var(--muted)"><?=Helpers::e($why)?></small></p></div>
<?php elseif($live):?>
<div class="card" id="miprog"><h3>⏳ Importing from <?=Helpers::e($live['remote_user'])?> at <?=Helpers::e($live['host'])?></h3>
<div class="bar" style="max-width:420px"><i id="miBar" style="width:<?=MailImport::pct($live)?>%"></i></div>
<p><b id="miStage"><?=Helpers::e($label[MailImport::stage($live)] ?? $live['stage'])?></b> <span id="miPct"></span><br><small id="miDetail" style="color:var(--muted)"><?=Helpers::e($live['detail'])?></small></p>
<p><small style="color:var(--muted)">You can leave this page; the import keeps running.</small></p>
<form method="post" action="/email/import-cancel" onsubmit="return confirm('Stop this import? Mail copied so far is kept.')"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="id" value="<?=$bid?>"><input type="hidden" name="job" value="<?=Helpers::e($live['job'])?>"><button class="btn small danger">Cancel import</button></form></div>
<script>
(function(){
var labels = <?=json_encode($label, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)?>;
async function poll(){
  try {
    var r = await fetch('/email/import-status?id=<?=$bid?>&job=<?=Helpers::e($live['job'])?>', {credentials:'same-origin'});
    if (!r.ok) return;
    var d = await r.json();
    if (d.stage !== 'checking' && d.stage !== 'running') { location.reload(); return; }
    document.getElementById('miStage').textContent = labels[d.stage] || d.stage;
    document.getElementById('miPct').textContent = d.total ? d.pct + '%' : '';
    document.getElementById('miDetail').textContent = d.detail || '';
    document.getElementById('miBar').style.width = Math.min(100, d.pct || 0) + '%';
    setTimeout(poll, 5000);
  } catch(e){ setTimeout(poll, 10000); }
}
setTimeout(poll, 3000);
})();
</script>
<?php else:?>
<div class="card"><h3>Your old mailbox</h3>
<form method="post" action="/email/import"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="id" value="<?=$bid?>">
<label>IMAP server</label>
<input name="host" list="mi-presets" required maxlength="253" placeholder="imap.gmail.com" autocomplete="off" autocapitalize="off" spellcheck="false">
<datalist id="mi-presets"><?php foreach(MailImport::PRESETS as $h => $n):?><option value="<?=Helpers::e($h)?>"><?=Helpers::e($n)?></option><?php endforeach;?></datalist>
<label>Port</label>
<select name="port"><option value="993">993 (TLS)</option><option value="143">143 (STARTTLS)</option></select>
<label>Username <small style="color:var(--muted)">(usually the full old address)</small></label>
<input name="remote_user" required maxlength="254" autocomplete="off" autocapitalize="off" spellcheck="false" placeholder="you@gmail.com">
<label>Password</label>
<input name="password" type="password" required maxlength="256" autocomplete="off">
<p><button class="btn">Start import</button></p></form>
<small style="color:var(--muted)">
<b>Gmail, Yahoo, iCloud, AOL, Zoho:</b> use an <i>app password</i> from that provider's security settings (your normal password is refused when 2-step sign-in is on).
<b>Gmail:</b> archived mail with no label is only in "All Mail", which is skipped (it would copy everything twice) — give that mail a label first. Starred and Important are views of mail already copied.
<b>Outlook.com, Hotmail and Microsoft 365</b> no longer allow password sign-in for IMAP, so they can't be imported here.<br>
The password is used for this import only and never stored. This mailbox's quota must be big enough for the old mail.
At most <?=MailImport::PER_HOUR?> attempts per hour.</small></div>
<?php endif;?>

<?php if($jobs):?>
<div class="card"><h3>Recent imports</h3>
<table><tr><th>Started</th><th>From</th><th>Result</th></tr>
<?php foreach($jobs as $j): $st = MailImport::stage($j); ?><tr><td><small><?=Helpers::e(substr((string)$j['created_at'], 0, 16))?></small></td>
<td style="word-break:break-all"><small><?=Helpers::e($j['remote_user'])?><br><span style="color:var(--muted)"><?=Helpers::e($j['host'])?>:<?=(int)$j['port']?></span></small></td>
<td><span class="badge"><?=Helpers::e($label[$st] ?? $st)?></span><br><small style="color:var(--muted)"><?=Helpers::e($j['detail'])?></small></td></tr><?php endforeach;?>
</table></div>
<?php endif;?>

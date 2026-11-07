<h2>🔔 Notifications</h2>
<?php $wa = $watchdogAge ?? null; if($wa === null || $wa > 600):?><div class="alert err">⚠️ Watchdog <?= $wa === null ? 'has never run' : 'stale — last tick '.(int)floor($wa/60).' min ago' ?> — service alerts may not be firing. See Admin → Services.</div><?php endif;?>
<?php if(($user['role'] ?? '') === 'admin'):?><?php $ps = $push ?? []; $prov = $ps['push_provider'] ?? ''; ?>
<?php $provNames = ['telegram' => 'Telegram bot', 'discord' => 'Discord webhook', 'slack' => 'Slack webhook', 'webhook' => 'Webhook (JSON to any https URL)']; ?>
<div class="card"><h3>📲 Phone &amp; chat alerts <small style="color:var(--muted)">off unless configured — Telegram, Discord, Slack or your own webhook; box-level urgents only (service, integrity, egress, offsite, expiry, malware, quota)</small></h3>
<p>Status: <b><?= isset($provNames[$prov]) ? Helpers::e($provNames[$prov]).' on' : 'off' ?></b></p>
<form method="post" action="/notifications/push" id="pushForm">
<input type="hidden" name="csrf" value="<?=Helpers::csrf()?>">
<div style="display:grid;gap:6px;max-width:520px">
<select name="provider">
<option value="off"<?= $prov === '' || $prov === 'off' ? ' selected' : ''?>>Off</option>
<?php foreach($provNames as $pk => $pn):?><option value="<?=$pk?>"<?= $prov === $pk ? ' selected' : ''?>><?=Helpers::e($pn)?></option><?php endforeach;?>
</select>
<input name="token" class="push-tg" value="<?=Helpers::e($ps['push_token'] ?? '')?>" placeholder="bot token from @BotFather (digits:string)" style="min-width:280px" maxlength="120">
<input name="chat" class="push-tg" value="<?=Helpers::e($ps['push_chat'] ?? '')?>" placeholder="chat ID (message the bot, then Find chat below)" style="max-width:220px" maxlength="20">
<input name="url" class="push-hook" value="<?=Helpers::e($ps['push_url'] ?? '')?>" placeholder="webhook URL (https://…)" style="min-width:280px" maxlength="500" autocomplete="off">
<small class="push-hook" style="color:var(--muted)">Discord: channel ⚙️ → Integrations → Webhooks → New Webhook → Copy Webhook URL. Slack: create an app with Incoming Webhooks and copy its URL. Webhook: we POST JSON <code>{"source":"karnakpanel","server","type","title","text","urgent"}</code> — works with ntfy, n8n, Zapier and the like. The URL works like a password: anyone who has it can post to that channel.</small>
<div style="display:flex;gap:6px;align-items:center"><small style="color:var(--muted)">Quiet hours (non-critical held for daytime):</small>
<input name="quiet_from" value="<?=Helpers::e($ps['push_quiet_from'] ?? '22')?>" pattern="[0-9]*" style="max-width:50px" title="from hour">–<input name="quiet_to" value="<?=Helpers::e($ps['push_quiet_to'] ?? '8')?>" pattern="[0-9]*" style="max-width:50px" title="to hour"></div>
<button class="btn">Save</button>
<button class="btn small push-tg" type="submit" name="do" value="find" title="Uses the token above">🔍 Find my chat</button>
</div></form>
<form class="inline" method="post" action="/notifications/push-test" style="margin-top:6px"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><button class="btn small">Send test alert</button></form>
<div class="card push-tg" style="margin-top:8px"><h3>📱 Connect Telegram <small style="color:var(--muted)">once, 2 minutes</small></h3>
<ol style="margin:0;padding-left:20px;font-size:14px">
<li>Open <b>Telegram</b>, search <b>@BotFather</b>, send <code>/newbot</code> — pick a name and a username ending in <code>bot</code>.</li>
<li>BotFather replies with a <b>token</b> (<code>123456:ABC…</code>) — paste it above.</li>
<li>Open your new bot (search its username) and send it anything, e.g. <code>hi</code>.</li>
<li>Back here press <b>🔍 Find my chat</b>, copy the ID number into <b>Chat ID</b>, press <b>Save</b>.</li>
<li>Press <b>Send test alert</b> — the chat buzzes within seconds. Done.</li>
</ol>
<small style="color:var(--muted)">Phone holds no access: short status text only (no IPs, secrets or links). Service/integrity buzz day and night; the rest wait for daytime. New phone or leak? @BotFather → /revoke, then update the token here.</small></div>
<script>
(function(){
  // show only the fields the chosen service needs (all stay in the form)
  var f=document.getElementById('pushForm'); if(!f) return;
  var sel=f.elements.provider;
  function sync(){var v=sel.value;
    document.querySelectorAll('.push-tg').forEach(function(e){e.style.display=v==='telegram'?'':'none';});
    document.querySelectorAll('.push-hook').forEach(function(e){e.style.display=(v==='discord'||v==='slack'||v==='webhook')?'':'none';});}
  sel.addEventListener('change',sync); sync();
})();
</script></div><?php endif;?>
<div class="card"><form method="post" action="/notifications/read" style="display:inline"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><button class="btn small">Mark all read</button></form>
<form method="post" action="/notifications/delete-all" style="display:inline" onsubmit="return confirm('Delete ALL notifications? This cannot be undone.')"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><button class="btn small danger">Delete all</button></form></div>
<div class="card"><table><tr><th>When</th><th>Event</th><th>Detail</th><th></th></tr>
<?php foreach($notes as $n):?><tr<?php if(empty($n['read_at'])):?> style="font-weight:bold"<?php endif;?>><td style="white-space:nowrap"><?=Helpers::e($n['created_at'])?></td>
<td><span class="badge"><?=Helpers::e($n['type'])?></span><?= $n['who'] ?? null ? ' '.Helpers::e($n['who']) : '' ?> <?=Helpers::e($n['title'])?></td>
<td><?=Helpers::e(mb_substr($n['body'] ?? '', 0, 160))?></td>
<td><?php if(empty($n['read_at'])):?><form method="post" action="/notifications/read" style="display:inline"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><input type="hidden" name="id" value="<?=$n['id']?>"><button class="btn small">✓</button></form><?php endif;?>
<form method="post" action="/notifications/delete" style="display:inline" onsubmit="return confirm('Delete this notification?')"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><input type="hidden" name="id" value="<?=$n['id']?>"><button class="btn small danger" title="Delete">×</button></form></td></tr><?php endforeach;?>
<?php if(empty($notes)):?><tr><td colspan="4" style="color:var(--muted)">All quiet.</td></tr><?php endif;?></table>
<?php $pagerBase = '/notifications?'; $pagerLabel = 'notifications'; require __DIR__.'/_pager.php'; ?></div>

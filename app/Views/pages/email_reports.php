<?php /* DMARC + TLS-RPT aggregate report viewer (MailPolicyController::reports) */
$total = (int)($tot['total'] ?? 0); $aligned = (int)($tot['aligned'] ?? 0);
$pct = $total > 0 ? round(100 * $aligned / $total, 1) : null; ?>
<h2>📊 Mail reports <small style="color:var(--muted)"><?=Helpers::e($dom['domain'])?></small></h2>
<div class="card"><a class="btn small" href="/email">← Email</a>
<?php foreach([7, 30, 90, 180] as $dd):?> <a class="btn small" style="<?= $dd === $days ? 'font-weight:700;outline:2px solid currentColor' : 'opacity:.7' ?>" href="/email/reports?domain_id=<?=(int)$dom['id']?>&amp;days=<?=$dd?>"><?=$dd?> days</a><?php endforeach;?>
<?php if((int)$dom['mail_reports'] !== 1):?><p style="color:var(--muted)">Report intake is off for this domain — showing stored reports only.</p><?php endif;?></div>

<?php $csrf = Helpers::csrf(); $did = (int)$dom['id'];
$step = function(string $to, string $label, string $cls = '', string $confirm = '') use ($csrf, $did) { ?><form method="post" action="/email/dmarc-step" style="display:inline"<?= $confirm !== '' ? ' onsubmit="return confirm(\''.Helpers::e($confirm).'\')"' : '' ?>><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="domain_id" value="<?=$did?>"><input type="hidden" name="to" value="<?=$to?>"><button class="btn small<?=$cls?>"><?=$label?></button></form> <?php };
$ignForm = function(string $ip, bool $ignore) use ($csrf, $did) { ?><form method="post" action="/email/dmarc-source" style="display:inline"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="domain_id" value="<?=$did?>"><input type="hidden" name="ip" value="<?=Helpers::e($ip)?>"><input type="hidden" name="ignore" value="<?= $ignore ? '1' : '0' ?>"><button class="btn small" title="<?= $ignore ? 'Not my server (spoofer): stop it blocking policy steps and alerts' : 'Count this source again' ?>"><?= $ignore ? 'Ignore' : 'Count again' ?></button></form><?php };
$plabel = ['' => 'no record', 'none' => 'p=none (monitor only)', 'quarantine' => 'p=quarantine (failing mail goes to spam)', 'reject' => 'p=reject (failing mail refused)']; ?>
<div class="card"><h3>🧭 Policy autopilot <small style="color:var(--muted)">step DMARC up to reject, and MTA-STS up to enforce, once the reports say it is safe</small></h3>
<p>DMARC: <b><?=Helpers::e($plabel[$ap['p']] ?? 'p='.$ap['p'])?></b><?php if($ap['next'] !== ''):?> → next: <b>p=<?=Helpers::e($ap['next'])?></b><?php endif;?></p>
<ul style="margin:4px 0 8px;padding-left:18px;list-style:none">
<?php foreach($ap['checks'] as [$ok, $txt]):?><li><?= $ok ? '✅' : '⏳' ?> <?=Helpers::e($txt)?></li><?php endforeach;?></ul>
<?php if($ap['blockers']):?><table><tr><th>Failing sender</th><th>Messages</th><th>Failed</th><th></th></tr>
<?php foreach(array_slice($ap['blockers'], 0, 10, true) as $ip => [$m, $f]): $ip = (string)$ip; ?><tr><td><code><?=Helpers::e($ip)?></code><?php if($ip === $serverIp):?> <span class="badge">this server: check DKIM</span><?php endif;?></td><td><?=number_format($m)?></td><td><?=number_format($f)?></td>
<td><?php if($ip !== $serverIp) $ignForm($ip, true); ?></td></tr><?php endforeach;?></table>
<small style="color:var(--muted)">A failing sender is either a service you use that isn't set up yet (add it to SPF, give it a DKIM key) or someone spoofing you. Ignore only the ones you don't recognise; once you reach reject, their mail is refused, which is the point.</small><br><?php endif;?>
<div style="margin-top:8px"><?php if($ap['next'] !== '' && $ap['ok']): $step($ap['next'], 'Move to p='.$ap['next'], '', $ap['next'] === 'reject'
      ? 'Move to p=reject? Receivers will REFUSE any mail that fails DMARC, including from services you forgot to set up.'
      : 'Move to p=quarantine? Receivers will send mail that fails DMARC to spam.');
  elseif($ap['next'] !== ''):?><button class="btn small" disabled title="Waiting on the checks above">Move to p=<?=Helpers::e($ap['next'])?></button> <?php endif;?>
<?php if($ap['p'] === 'reject') $step('quarantine', 'Step down to quarantine', ' danger', 'Step down to p=quarantine?');
      if(in_array($ap['p'], ['quarantine', 'reject'], true)) $step('none', 'Step down to none', ' danger', 'Step down to p=none? Nothing is filtered any more, only reported.'); ?></div>
<?php if(($dom['mta_sts_mode'] ?? 'off') === 'testing'):?><hr style="border:0;border-top:1px solid var(--border,#334);margin:12px 0">
<p>MTA-STS: <b>🧪 testing</b> → next: <b>enforce</b></p>
<ul style="margin:4px 0 8px;padding-left:18px;list-style:none">
<?php foreach($sts['checks'] as [$ok, $txt]):?><li><?= $ok ? '✅' : '⏳' ?> <?=Helpers::e($txt)?></li><?php endforeach;?></ul>
<?php if($sts['ok']):?><form method="post" action="/email/mta-sts" style="display:inline" onsubmit="return confirm('Enforce MTA-STS? Senders will REFUSE delivery if TLS to your MX ever breaks (cached up to 7 days). Live checks run first.')"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="domain_id" value="<?=$did?>"><input type="hidden" name="mode" value="enforce"><button class="btn small">Enforce MTA-STS</button></form>
<?php else:?><button class="btn small" disabled title="Waiting on the checks above">Enforce MTA-STS</button><?php endif;?>
<?php elseif(($dom['mta_sts_mode'] ?? 'off') === 'enforce'):?><p>MTA-STS: <b>🔒 enforce</b>. Alerts fire if TLS reports show failures.</p>
<?php elseif(($dom['mta_sts_mode'] ?? 'off') === 'off'):?><p style="color:var(--muted)">MTA-STS is not published. Start it in testing mode on the Email page.</p><?php endif;?>
<small style="color:var(--muted)">You get a notification and an email when a step becomes safe, when a new sender starts failing DMARC, and when TLS reports show failed deliveries. Nothing changes until you click.</small></div>

<div class="card"><h3>DMARC <small style="color:var(--muted)">who sent mail claiming to be @<?=Helpers::e($dom['domain'])?>, as reported by receivers</small></h3>
<?php if($total === 0):?><p style="color:var(--muted)">No DMARC reports in the last <?=$days?> days. Large receivers (Google, Microsoft, Yahoo) send one per day once the <code>rua=</code> tag is public and they have seen mail from you.</p>
<?php else:?>
<p><b><?=number_format($total)?></b> messages in <?=(int)$tot['reports']?> report(s) · <b><?=$pct?>%</b> passed DMARC (aligned SPF or DKIM)<?php if((int)$tot['actioned'] > 0):?> · <b><?=number_format((int)$tot['actioned'])?></b> quarantined/rejected by policy<?php endif;?></p>
<table><tr><th>Source IP</th><th>Messages</th><th>DMARC pass</th><th>DKIM</th><th>SPF</th><th>From</th><th>Reported by</th><th></th></tr>
<?php foreach($sources as $s): $m = max(1, (int)$s['msgs']); $srcPct = round(100 * (int)$s['aligned'] / $m); ?>
<tr><td><code><?=Helpers::e($s['source_ip'])?></code><?php if($s['source_ip'] === $serverIp):?> <span class="badge">this server</span><?php endif;?></td>
<td><?=number_format((int)$s['msgs'])?></td>
<td><?= $srcPct >= 98 ? '✅' : ($srcPct > 0 ? '⚠️' : '❌') ?> <?=$srcPct?>%</td>
<td><?=round(100 * (int)$s['dkim_pass'] / $m)?>%</td><td><?=round(100 * (int)$s['spf_pass'] / $m)?>%</td>
<td><small><?=Helpers::e(mb_strimwidth((string)$s['froms'], 0, 80, '…'))?></small></td>
<td><small><?=Helpers::e(mb_strimwidth((string)$s['orgs'], 0, 60, '…'))?></small></td>
<td><?php $isIgn = in_array($s['source_ip'], $ignored, true); if($isIgn): ?><span class="badge">ignored</span> <?php $ignForm((string)$s['source_ip'], false); elseif($srcPct < 98 && $s['source_ip'] !== $serverIp): $ignForm((string)$s['source_ip'], true); endif;?></td></tr>
<?php endforeach;?></table>
<small style="color:var(--muted)">A source failing both DKIM and SPF is either a service you use that isn't set up yet (add it to SPF / give it a DKIM key) or someone spoofing you. Move DMARC from <code>p=none</code> to <code>quarantine</code> only once every legitimate source passes.</small>
<?php endif;?></div>

<div class="card"><h3>TLS reports <small style="color:var(--muted)">did senders manage encrypted delivery to your MX (MTA-STS / TLS-RPT)</small></h3>
<?php if(!$tls):?><p style="color:var(--muted)">No TLS reports in the last <?=$days?> days<?= ($dom['mta_sts_mode'] ?? 'off') === 'off' ? ' — publish MTA-STS (testing) on the Email page to start receiving them' : '' ?>.</p>
<?php else:?>
<table><tr><th>Reporter</th><th>Period</th><th>Policy</th><th>OK sessions</th><th>Failed</th><th>Failures</th></tr>
<?php foreach($tls as $t):?><tr><td><?=Helpers::e($t['org_name'])?></td><td><small><?=Helpers::e(substr((string)$t['date_begin'], 0, 10))?> → <?=Helpers::e(substr((string)$t['date_end'], 0, 10))?></small></td>
<td><?=Helpers::e($t['policy_type'])?></td><td><?=number_format((int)$t['success_count'])?></td>
<td><?= (int)$t['failure_count'] > 0 ? '❌ '.number_format((int)$t['failure_count']) : '✅ 0' ?></td>
<td><small><?= nl2br(Helpers::e((string)$t['failures'])) ?></small></td></tr><?php endforeach;?></table>
<?php endif;?></div>

<div class="card"><h3>Recent DMARC reports</h3>
<table><tr><th>Reporter</th><th>Period</th><th>Policy</th><th>Messages</th><th>Received</th></tr>
<?php foreach($recent as $r):?><tr><td><?=Helpers::e($r['org_name'])?></td><td><small><?=Helpers::e(substr((string)$r['date_begin'], 0, 10))?> → <?=Helpers::e(substr((string)$r['date_end'], 0, 10))?></small></td>
<td><code>p=<?=Helpers::e($r['policy_p'])?></code></td><td><?=number_format((int)$r['msgs'])?></td><td><small><?=Helpers::e((string)$r['received_at'])?></small></td></tr><?php endforeach;?>
<?php if(!$recent):?><tr><td colspan="5" style="color:var(--muted)">None yet.</td></tr><?php endif;?></table>
<small style="color:var(--muted)">Reports are kept 180 days. Only reports whose policy domain is <?=Helpers::e($dom['domain'])?> (or its own subdomains) are accepted.</small></div>

<h2>📨 Mail queue <small style="color:var(--muted)">Exim4</small> <span class="pill"><?=(int)($nq??0)?> queued</span> <?php if(($nf??0)>0):?><span class="pill err"><?=(int)$nf?> frozen</span><?php endif;?></h2>
<?php $bl = $bl ?? []; $blListed = array_values(array_filter($bl, fn($r) => $r['state'] === 'listed' || ($r['state'] === 'unchecked' && $r['listed_since'])));
$blNo = []; foreach ($bl as $r) if ($r['state'] === 'unchecked') $blNo[$r['name']] = $r['detail'];
$blClean = array_values(array_filter($bl, fn($r) => $r['state'] === 'clean'));
$blKind = ['ipv4' => 'server IPv4', 'ipv6' => 'server IPv6', 'domain' => 'domain']; ?>
<div class="card" id="blacklists"<?=!empty($blRun) ? ' data-reloadin="10"' : ''?>><h3>🚫 Blacklists <small style="color:var(--muted)">is this server's mail on a spam blacklist? checked nightly<?=($blAt ?? '') !== '' ? ', last '.Helpers::e(date('j M H:i', strtotime($blAt))) : ''?></small>
<?php if(!empty($blRun)):?> <span class="spin" aria-hidden="true"></span> <small>checking…</small><?php elseif(!$bl):?> <span class="pill">not checked yet</span><?php elseif($blListed):?> <span class="pill err">listed on <?=count($blListed)?></span><?php else:?> <span class="pill">✓ not listed<?=$blNo ? ' (where checked)' : ''?></span><?php endif;?></h3>
<?php if(!empty($blRelay)):?><p><small style="color:var(--muted)">Outgoing mail goes through your SMTP relay, so receivers judge the relay's IP — a listing of this server's own IP matters less while the relay is on.</small></p><?php endif;?>
<?php if($blListed):?><table><tr><th>Listed</th><th>Blacklist</th><th>Why</th><th>Since</th><th></th></tr>
<?php foreach($blListed as $r):?><tr><td><b><?=Helpers::e($r['target'])?></b> <small style="color:var(--muted)"><?=Helpers::e($blKind[$r['kind']] ?? $r['kind'])?></small></td><td><?=Helpers::e($r['name'])?></td>
<td><small><?php if($r['state'] === 'unchecked'):?>Can't check right now — still counted as listed until a check shows it clean.<?php elseif(str_contains($r['detail'], 'PBL')):?>PBL — the IP's owner (your hosting provider) marks its range as not meant to send mail directly. Ask the provider, or use Spamhaus's removal form.<?php elseif(str_starts_with($r['name'], 'UCEPROTECT')):?>Low weight (few big providers use it). Clears by itself 7 days after the spam stops — never pay for express removal.<?php elseif($r['detail'] !== 'listed'):?><?=Helpers::e($r['detail'])?><?php endif;?></small></td>
<td><small><?=$r['listed_since'] ? Helpers::e(date('j M', strtotime($r['listed_since']))) : ''?></small></td>
<td><?php if($r['delist']):?><a class="btn small" href="<?=Helpers::e($r['delist'])?>" target="_blank" rel="noopener noreferrer">Removal ↗</a><?php endif;?></td></tr><?php endforeach;?></table>
<p><small style="color:var(--muted)">Fix the cause before asking for removal — usually a hacked mailbox or website script sending spam (check Recent outcomes below and the abuse board). Most lists drop a listing by themselves within days once the spam stops.</small></p><?php endif;?>
<?php if($blNo):?><p><small style="color:var(--muted)">Can't check from this server: <?php $i = 0; foreach($blNo as $n => $why):?><?=$i++ ? '; ' : ''?><b><?=Helpers::e($n)?></b> (<?=Helpers::e($why)?>)<?php endforeach;?>. A list that refuses a lookup is shown here, never as "not listed".</small></p><?php endif;?>
<?php if($blClean):?><details><summary style="cursor:pointer"><small><?=count($blClean)?> checks clean</small></summary><table><tr><th>Checked</th><th>Blacklist</th></tr>
<?php $byT = []; foreach($blClean as $r) $byT[$r['target'].' ('.($blKind[$r['kind']] ?? $r['kind']).')'][] = $r['name']; foreach($byT as $t => $names):?><tr><td><?=Helpers::e($t)?></td><td><small><?=Helpers::e(implode(', ', $names))?></small></td></tr><?php endforeach;?></table>
<small style="color:var(--muted)">IPv6 is checked on Spamhaus only — the other lists don't list IPv6 addresses.</small></details><?php endif;?>
<form method="post" action="/admin/blacklist-check" style="margin-top:8px"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><button class="btn small"<?=!empty($blRun) ? ' disabled' : ''?>>🔍 Check now</button></form></div>
<div class="card"><h3>Queued messages <small style="color:var(--muted)"><?=(int)($totalMsgs??0)?> shown<?php
$tb = (int)(($totals ?? [])['bytes'] ?? 0);
$ts = $tb >= 1048576 ? round($tb/1048576,1).' MB' : ($tb >= 1024 ? round($tb/1024,1).' KB' : $tb.' B');
$to = (int)(($totals ?? [])['oldest'] ?? 0);
$ot = $to >= 1440 ? intdiv($to,1440).'d '.intdiv($to%1440,60).'h' : ($to >= 60 ? intdiv($to,60).'h '.($to%60).'m' : $to.'m');
?> · <?=$ts?> spool<?php if(($totalMsgs??0)>0):?> · oldest <?=$ot?><?php endif;?></small></h3>
<form method="get" action="/admin/mail" style="margin-bottom:10px"><input name="q" placeholder="filter sender / recipient / id" value="<?=Helpers::e($fq??'')?>" style="max-width:280px"> <button class="btn small">Filter</button><?php if(($fq??'')!==''):?> <a href="/admin/mail">clear</a><?php endif;?></form>
<?php if(empty($msgs)):?><p class="empty">Queue empty<?=($fq??'')!==''?' — nothing matches that filter':''?>.</p>
<?php else:?><table class="list"><tr><th>Age</th><th>From</th><th>To</th><th>Size</th><th>Status</th><th>Sender auth</th><th>Last error</th><th></th></tr>
<?php foreach($msgs as $m):?><tr><td style="white-space:nowrap"><?=Helpers::e($m['age'])?></td>
<td><?=Helpers::e($m['from']===''?'mailer-daemon':$m['from'])?></td>
<td><?=Helpers::e($m['to']==='—'?'—':$m['to'])?></td>
<td><?=Helpers::e($m['size'])?></td>
<td><?php if($m['frozen']):?><span class="pill err">frozen</span><?php else:?><span class="pill">queued</span><?php endif;?></td>
<td><?php $dom = str_contains($m['from'],'@') ? strtolower(substr(strrchr($m['from'],'@'),1)) : ''; $sc = ($dom!=='' && isset($auth[$dom])) ? $auth[$dom] : null;
if($sc):?><span class="pill" title="<?=Helpers::e($sc['details'])?>"><?=(int)$sc['score']?>/100</span><?php else:?>—<?php endif;?></td>
<td><small style="color:var(--muted)"><?=Helpers::e(($errs ?? [])[$m['id']] ?? '')?></small></td>
<td style="white-space:nowrap"><a class="btn small" href="/admin/mail?<?=http_build_query(array_merge($_GET,['show'=>$m['id']])) /* nosemgrep: php.lang.security.injection.echoed-request.echoed-request -- http_build_query percent-encodes (no literal quote), href only */?>">View</a>
<form method="post" action="/admin/mail-retry-one" style="display:inline"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><input type="hidden" name="id" value="<?=Helpers::e($m['id'])?>"><button class="btn small">Retry</button></form>
<form method="post" action="/admin/mail-drop-one" style="display:inline" onsubmit="return confirm('Delete queued message <?=Helpers::e($m['id'])?>? This cannot be undone.')"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><input type="hidden" name="id" value="<?=Helpers::e($m['id'])?>"><button class="btn small danger">Delete</button></form></td></tr><?php endforeach;?></table>
<?php if(($pages??1)>1):?><p style="margin:8px 0 0">Page <?=(int)$pg?> of <?=(int)$pages?>: <?php for($i=1;$i<=$pages;$i++):?><?php if($i===$pg):?><b><?=$i?></b><?php else:?><a href="/admin/mail?<?=http_build_query(array_merge($_GET,['pg'=>$i])) /* nosemgrep: php.lang.security.injection.echoed-request.echoed-request -- http_build_query percent-encodes (no literal quote), href only */?>"><?=$i?></a><?php endif;?> <?php endfor;?></p><?php endif;?>
<?php if(!empty($truncated)):?><p><small style="color:var(--muted)">Queue exceeds 500 — oldest first 500 shown; narrow with the filter.</small></p><?php endif;?>
<?php endif;?></div>
<?php if(!empty($show)):?><div class="card"><h3>Message <code><?=Helpers::e($show['id'])?></code> <a class="btn small" href="/admin/mail">Close</a></h3><?php if(($showRetry ?? '')!==''):?><p><small style="color:var(--muted)">Next attempt: <?=Helpers::e($showRetry)?></small></p><?php endif;?><pre style="white-space:pre-wrap;max-height:400px;overflow:auto"><?=Helpers::e($show['out'])?></pre></div><?php endif;?>
<div class="card"><h3>Recent outcomes <small style="color:var(--muted)">deliveries, bounces + deferrals from the Exim log (<?=(int)($totalOuts??0)?>)</small></h3>
<p><?php foreach([''=>'all','delivered'=>'delivered','bounced'=>'bounced','deferred'=>'deferred'] as $k=>$lbl):?><?php if(($res??'')===$k):?><b><?=$lbl?></b><?php else:?><a href="/admin/mail?<?=http_build_query(array_merge($_GET,['res'=>$k,'opg'=>1])) /* nosemgrep: php.lang.security.injection.echoed-request.echoed-request -- http_build_query percent-encodes (no literal quote), href only */?>"><?=$lbl?></a><?php endif;?> <?php endforeach;?></p>
<?php if(empty($outs)):?><p class="empty">No outcomes in the current log<?=($res??'')!==''?' for that result':''?>.</p>
<?php else:?><table><tr><th>Time</th><th>Outcome</th><th>Recipient</th><th>Message</th><th>Detail</th></tr>
<?php foreach($outs as $o):?><tr><td style="white-space:nowrap"><small><?=Helpers::e($o['time'])?></small></td>
<td><?php if($o['result']==='delivered'):?><span class="pill">delivered</span><?php elseif($o['result']==='bounced'):?><span class="pill err">bounced</span><?php else:?><span class="pill warn">deferred</span><?php endif;?></td>
<td><?=Helpers::e($o['addr'])?></td><td><code><?=Helpers::e($o['id'])?></code></td><td><small><?=Helpers::e($o['detail'])?></small></td></tr><?php endforeach;?></table>
<?php if(($opages??1)>1):?><p style="margin:8px 0 0">Page <?=(int)$opg?> of <?=(int)$opages?>: <?php for($i=1;$i<=$opages;$i++):?><?php if($i===$opg):?><b><?=$i?></b><?php else:?><a href="/admin/mail?<?=http_build_query(array_merge($_GET,['opg'=>$i])) /* nosemgrep: php.lang.security.injection.echoed-request.echoed-request -- http_build_query percent-encodes (no literal quote), href only */?>"><?=$i?></a><?php endif;?> <?php endfor;?></p><?php endif;?>
<?php endif;?></div>
<?php if(!empty($topDoms)):?><div class="card"><h3>Failing destinations <small style="color:var(--muted)">bounced + deferred by recipient domain — one dead destination vs our reputation</small></h3>
<table class="list"><tr><th>Domain</th><th>Bounced</th><th>Deferred</th></tr>
<?php foreach($topDoms as $dom=>$t):?><tr><td><?=Helpers::e($dom)?></td><td><?php if($t['bounced']>0):?><span class="pill err"><?=(int)$t['bounced']?></span><?php else:?>—<?php endif;?></td><td><?php if($t['deferred']>0):?><span class="pill warn"><?=(int)$t['deferred']?></span><?php else:?>—<?php endif;?></td></tr><?php endforeach;?></table></div><?php endif;?>
<div class="card"><pre style="white-space:pre-wrap"><?=Helpers::e($queue)?></pre>
<form method="post" action="/admin/mail-flush"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><button class="btn">▶ Run queue now</button></form></div>
<div class="card"><h3>Frozen messages</h3>
<?php if(trim($frozen ?? '')===''):?><p style="color:var(--muted)">None frozen. Frozen mail is usually a dead recipient/router — investigate before flushing.</p>
<?php else:?><pre style="white-space:pre-wrap"><?=Helpers::e($frozen)?></pre>
<form class="inline" method="post" action="/admin/mail-thaw"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><button class="btn small">Thaw (retry all)</button></form>
<form class="inline" method="post" action="/admin/mail-drop-frozen" onsubmit="return confirm('Delete all frozen messages? They are undeliverable dead ends — this cannot be undone.')"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><button class="btn small danger">Delete frozen</button></form><?php endif;?></div>
<div class="card"><h3>🧹 Empty entire queue <small style="color:var(--muted)">spam-flood escape hatch — 500 messages per press, repeat until empty</small></h3>
<form class="inline" method="post" action="/admin/mail-drop-all" onsubmit="return confirm('DELETE up to 500 queued messages? Legit mail in the queue goes too. This cannot be undone.')"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><input name="confirm" placeholder="type DELETE" required style="max-width:120px"><button class="btn danger">Delete all queued</button></form></div>

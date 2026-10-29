<?php
$ug = $usage ?? []; $st = $stats ?? null; $isAdmin = !empty($isAdmin);
$num = fn($n) => number_format((int)$n);
$bytes = fn($b) => Helpers::fmtMb((float)$b / 1048576);
$pct = fn($a, $b) => $b > 0 ? round($a / $b * 100, ($a / $b) < 0.1 ? 1 : 0) : 0;
// "+12% vs previous 7 days" pill; nothing when the log doesn't reach back that far
$delta = function(int $now, int $prev) use ($st) {
    if (empty($st['prevOk'])) return '';
    $d = AccessStats::delta($now, $prev);
    if ($d === null) return '';
    $cls = $d > 0 ? 'up' : ($d < 0 ? 'down' : '');
    return ' <span class="stdelta '.$cls.'" title="vs the previous '.(int)$st['days'].' day(s): '.number_format($prev).'">'.($d > 0 ? '▲ ' : ($d < 0 ? '▼ ' : '')).abs($d).'%</span>';
};
// ranked list with share bars; $code = monospace keys (paths, IPs)
$blist = function(array $rows, int $total, bool $code = false, string $empty = 'Nothing in this range.') use ($num, $pct) {
    if (!$rows) return '<div class="muted stempty">'.Helpers::e($empty).'</div>';
    $max = max(1, max($rows)); $h = '<div class="stlist">';
    foreach ($rows as $k => $c) {
        $k = (string)$k;
        $h .= '<div title="'.Helpers::e($k).'"><span class="stk">'.($code ? '<code>'.Helpers::e($k).'</code>' : Helpers::e($k)).'</span>'
            .'<span class="stv">'.$num($c).($total > 0 ? ' <small>'.$pct($c, $total).'%</small>' : '').'</span>'
            .'<span class="stbar"><i style="width:'.max(1, (int)round($c / $max * 100)).'%"></i></span></div>';
    }
    return $h.'</div>';
};
$rangeName = fn(int $d) => $d === 1 ? 'Today' : "$d days";
?>
<div class="fwhead sthead"><h2>📊 Stats</h2><span class="muted">visitors from the access logs + account usage</span>
<form method="get" action="/stats" class="stpick" id="stPick">
<input name="domain" list="stats-domains" value="<?=Helpers::e($domain)?>" placeholder="type a domain…" autocomplete="off" aria-label="Domain">
<datalist id="stats-domains"><?php foreach($domains as $d):?><option value="<?=Helpers::e($d['domain'])?>"<?php if($isAdmin && isset($d['username'])):?> label="<?=Helpers::e($d['username'])?>"<?php endif;?>><?php endforeach;?></datalist>
<input type="hidden" name="days" value="<?=(int)$days?>"><button class="btn small">Show</button></form>
<div class="stranges" role="group" aria-label="Date range"><?php foreach(StatsController::RANGES as $n):?><a href="/stats?domain=<?=urlencode($domain)?>&amp;days=<?=$n?>" class="<?= $days === $n ? 'on' : '' ?>"><?=$rangeName($n)?></a><?php endforeach;?></div></div>

<?php if(!$domains):?>
<div class="card"><h3>No domains yet</h3><p class="muted">Traffic stats appear here once you add a domain and it gets its first visitors. <a href="/domains">Add a domain →</a></p></div>
<?php elseif(!$st || !empty($st['empty'])):?>
<div class="card"><h3>🌐 <?=Helpers::e($domain)?></h3><p class="muted">No visits logged yet. The log starts with the first request to the site — check back after someone visits it.</p></div>
<?php else:
  $h = (int)$st['hits']; $hum = (int)$st['humans']; $e4 = (int)$st['status']['4xx']; $e5 = (int)$st['status']['5xx'];
  $peakDay = ''; $peakDayN = 0; foreach($st['daily'] as $dd => $r) if($r['hits'] > $peakDayN){ $peakDayN = $r['hits']; $peakDay = $dd; }
  $peakHour = array_search(max($st['hours']), $st['hours'], true);
  $bad5 = $e5 >= 10 && $e5 / max(1, $h) >= 0.01;
?>
<div class="stsec"><h3>🌐 <?=Helpers::e($domain)?> <small class="muted">· <?= $days === 1 ? 'today' : 'last '.(int)$days.' days' ?></small></h3>
<small class="muted stcover">Log data <?= $st['first'] ? Helpers::e(date('j M H:i', (int)$st['first'])).' → '.Helpers::e(date('j M H:i', (int)$st['last'])) : '—' ?>
<?php if($st['firstAny'] && $st['firstAny'] > $st['start'] + 3600):?> · <span class="stwarnt" title="Older lines were rotated away or are past the 8 MB read window">the log only reaches back to <?=Helpers::e(date('j M', (int)$st['firstAny']))?></span><?php endif;?></small></div>

<div class="fwtiles sttiles">
<div class="fwtile"><small>Visitors</small><b><?=$num($st['uniq'])?><?=$delta((int)$st['uniq'], (int)$st['prev']['uniq'])?></b><span class="muted dbsub">unique IP addresses</span></div>
<div class="fwtile"><small>Requests</small><b><?=$num($h)?><?=$delta($h, (int)$st['prev']['hits'])?></b><span class="muted dbsub"><?=$num($st['assets'])?> were images / CSS / JS</span></div>
<div class="fwtile"><small>Data served</small><b><?=Helpers::e($bytes($st['bytes']))?><?=$delta((int)$st['bytes'], (int)$st['prev']['bytes'])?></b><span class="muted dbsub">≈ <?=Helpers::e($bytes($st['bytes'] / max(1, $days)))?> a day</span></div>
<div class="fwtile"><small>Real people</small><b><?=$pct($hum, $h)?>%</b><span class="abbar"><i style="width:<?=$pct($hum, $h)?>%"></i></span><span class="muted dbsub"><?=$num($st['bots'])?> requests from bots &amp; scripts</span></div>
<div class="fwtile<?= $bad5 ? ' alert' : '' ?>"><small>Errors</small><b><?=$num($e5)?></b><span class="muted dbsub">server errors (5xx) · <?=$pct($e4, $h)?>% not found / denied (4xx)</span></div>
<div class="fwtile"><small>Scanner probes</small><b><?=$num($st['probes'])?></b><span class="muted dbsub">requests for .env, wp-login, phpMyAdmin…</span></div>
<div class="fwtile"><small>Busiest</small><b class="stsmallb"><?= $peakDay ? Helpers::e(date('D j M', (int)strtotime($peakDay))) : '—' ?></b><span class="muted dbsub"><?=$num($peakDayN)?> requests · peak hour <?=sprintf('%02d:00', (int)$peakHour)?></span></div>
</div>

<?php
  // chart only the days the log actually covers — blank bars would read as "no traffic"
  $daily = $st['daily']; $from = $st['firstAny'] ? date('Y-m-d', (int)$st['firstAny']) : '';
  if ($from !== '') $daily = array_filter($daily, fn($k) => $k >= $from, ARRAY_FILTER_USE_KEY);
  if($days > 1 && count($daily) > 1): $dmax = max(1, max(array_column($daily, 'hits'))); $nd = count($daily); $every = max(1, (int)ceil($nd / 10)); ?>
<div class="card"><div class="fwcardhead"><h3>📈 Daily traffic</h3><small class="muted"><?= $nd < count($st['daily']) ? 'from '.Helpers::e(date('j M', (int)strtotime((string)array_key_first($daily)))).' (start of the log) · ' : '' ?><span class="stkey"></span>people <span class="stkey bot"></span>bots &amp; scripts · hover a day for details</small></div>
<div class="stchart"><?php $i = 0; foreach($daily as $dd => $r): $hp = $r['hits'] - $r['bots']; ?>
<div title="<?=Helpers::e(date('D j M', (int)strtotime($dd)))?>&#10;<?=$num($r['hits'])?> requests (<?=$num($r['bots'])?> bots)&#10;<?=$num($r['uniq'])?> visitors · <?=Helpers::e($bytes($r['bytes']))?>"><span class="stcol"><i class="bot" style="height:<?=round($r['bots'] / $dmax * 100, 2)?>%"></i><i style="height:<?=round($hp / $dmax * 100, 2)?>%"></i></span><small><?= $i++ % $every === 0 ? Helpers::e(date('j M', (int)strtotime($dd))) : '' ?></small></div>
<?php endforeach;?></div></div>
<?php endif;?>

<div class="dbcols">
<div class="card"><div class="fwcardhead"><h3>🕐 By hour</h3><small class="muted">server time</small></div><?php $hmax = max(1, max($st['hours'])); ?>
<div class="stchart sm"><?php foreach($st['hours'] as $hh => $c):?><div title="<?=sprintf('%02d:00', $hh)?> — <?=$num($c)?> requests"><span class="stcol"><i style="height:<?=round($c / $hmax * 100, 2)?>%"></i></span><small><?= $hh % 3 === 0 ? sprintf('%02d', $hh) : '' ?></small></div><?php endforeach;?></div></div>
<?php if($days >= 7): $wmax = max(1, max($st['wdays'])); ?>
<div class="card"><div class="fwcardhead"><h3>📅 By weekday</h3></div>
<div class="stchart sm"><?php foreach(['Mon','Tue','Wed','Thu','Fri','Sat','Sun'] as $wi => $wn): $c = $st['wdays'][$wi]; ?><div title="<?=$wn?> — <?=$num($c)?> requests"><span class="stcol"><i style="height:<?=round($c / $wmax * 100, 2)?>%"></i></span><small><?=$wn?></small></div><?php endforeach;?></div></div>
<?php endif;?>
</div>

<div class="dbcols">
<div class="card"><div class="fwcardhead"><h3>🚦 Responses</h3><small class="muted"><?=$pct($st['status']['2xx'], $h)?>% OK</small></div>
<div class="ststack"><?php foreach($st['status'] as $k => $c): if(!$c) continue; ?><i class="sts<?=$k[0]?>" style="width:<?=$pct($c, $h)?>%" title="<?=$k?>: <?=$num($c)?>"></i><?php endforeach;?></div>
<div class="stlegend"><?php foreach(['2xx' => 'OK', '3xx' => 'Redirects', '4xx' => 'Not found / denied', '5xx' => 'Server errors'] as $k => $lbl):?><div><span class="stdot sts<?=$k[0]?>"></span><?=$lbl?> <code><?=$k?></code><b><?=$num($st['status'][$k])?></b><small class="muted"><?=$pct($st['status'][$k], $h)?>%</small></div><?php endforeach;?></div></div>
<div class="card"><div class="fwcardhead"><h3>📱 People's devices</h3><small class="muted">bots excluded</small></div>
<?php if($hum > 0):?><div class="ststack dev"><?php $di = 0; foreach($st['devices'] as $k => $c):?><i class="std<?=$di++?>" style="width:<?=$pct($c, $hum)?>%" title="<?=Helpers::e($k)?>: <?=$num($c)?>"></i><?php endforeach;?></div>
<div class="stlegend inl"><?php $di = 0; foreach($st['devices'] as $k => $c):?><div><span class="stdot std<?=$di++?>"></span><?=Helpers::e($k)?> <b><?=$pct($c, $hum)?>%</b></div><?php endforeach;?></div>
<div class="stsplit"><div><div class="dbgroup">Browser</div><?=$blist($st['browsers'], $hum)?></div><div><div class="dbgroup">System</div><?=$blist($st['os'], $hum)?></div></div>
<?php else:?><div class="muted stempty">No human visitors in this range — only bots.</div><?php endif;?></div>
</div>

<div class="dbcols">
<div class="card"><div class="fwcardhead"><h3>📄 Top pages</h3><small class="muted">successful page loads, no assets</small></div><?=$blist($st['pages'], 0, true)?></div>
<div class="card"><div class="fwcardhead"><h3>↪️ Where visitors came from</h3><small class="muted">other sites linking in</small></div><?=$blist($st['refs'], 0, false, 'No outside referrers — visitors typed the address, used a bookmark or an app.')?></div>
</div>

<div class="dbcols">
<div class="card"><div class="fwcardhead"><h3>🤖 Bots &amp; crawlers</h3><small class="muted"><?=$num($st['bots'])?> requests</small></div><?=$blist($st['botnames'], (int)$st['bots'], false, 'No bots seen.')?></div>
<div class="card"><div class="fwcardhead"><h3>🕵️ Scanner probes</h3><small class="muted">normal internet noise — worrying only in large bursts</small></div><?=$blist($st['probepaths'], 0, true, 'No probes for common attack targets.')?></div>
</div>

<div class="dbcols">
<div class="card"><div class="fwcardhead"><h3>🔗 Broken links (404)</h3><small class="muted">worth fixing or redirecting</small></div><?=$blist($st['notfound'], 0, true, 'No 404s — every requested path resolved.')?></div>
<div class="card"><div class="fwcardhead"><h3>💥 Server errors (5xx)</h3><small class="muted">check the site's PHP error log</small></div><?=$blist($st['errors'], 0, true, 'No server errors. 🎉')?></div>
</div>

<div class="card"><div class="fwcardhead"><h3>🌐 Heaviest visitors</h3><small class="muted">by requests — check before blocking, search engines are often near the top<?php if($isAdmin):?> · <a href="/admin/firewall">Firewall →</a><?php endif;?></small></div>
<?php if($st['topips']):?><div class="tablewrap"><table class="stips"><thead><tr><th>IP address</th><th>Requests</th><th>Errors</th><th>Probes</th><th></th></tr></thead><tbody>
<?php foreach($st['topips'] as $ip => $r): $scan = $r['probes'] > 0 || ($r['hits'] >= 20 && $r['errors'] / $r['hits'] > 0.5); ?>
<tr><td><code><?=Helpers::e((string)$ip)?></code></td><td><?=$num($r['hits'])?> <small class="muted"><?=$pct($r['hits'], $h)?>%</small></td><td><?=$num($r['errors'])?></td><td><?=$num($r['probes'])?></td><td><?php if($scan):?><span class="pill mini warn">looks like a scanner</span><?php endif;?></td></tr>
<?php endforeach;?></tbody></table></div><?php else:?><div class="muted stempty">No traffic in range.</div><?php endif;?></div>
<?php endif;?>

<?php
$bwq = (int)($ug['bwQuotaGb'] ?? 0); $bwg = (float)($ug['bwMonthGb'] ?? 0); $bwp = $bwq > 0 ? min(100, (int)round($bwg / $bwq * 100)) : 0;
$dq = (int)($ug['diskQuotaMb'] ?? 0); $dn = (int)($ug['diskNowMb'] ?? 0); $dp = $dq > 0 ? min(100, (int)round($dn / $dq * 100)) : 0;
$pace = $ug['bwPace'] ?? null;
?>
<div class="stsec"><h3>📦 Account usage — <?=Helpers::e($ug['user'] ?? '?')?></h3>
<?php if($isAdmin):?><form method="get" action="/stats" class="stpick sm"><input type="hidden" name="domain" value="<?=Helpers::e($domain)?>"><input type="hidden" name="days" value="<?=(int)$days?>"><input name="user" placeholder="another account…" aria-label="Account" autocomplete="off"><button class="btn small ghost">View</button></form><?php endif;?></div>
<div class="fwtiles sttiles">
<div class="fwtile<?= $pace !== null && $pace >= 100 ? ' svwarn' : '' ?>"><small>Bandwidth this month</small><b><?=Helpers::e(rtrim(rtrim(number_format($bwg, 2), '0'), '.'))?> GB</b>
<?php if($bwq > 0):?><span class="abbar<?= $bwp >= 90 ? ' err' : ($bwp >= 70 ? ' warn' : '') ?>"><i style="width:<?=$bwp?>%"></i></span><span class="muted dbsub">of <?=$bwq?> GB<?php if($pace !== null):?> · on pace for <span class="stpace<?= $pace >= 100 ? ' dbred' : '' ?>"><?=Helpers::e((string)$pace)?>%</span> by month end<?php endif;?></span>
<?php else:?><span class="muted dbsub">no monthly limit</span><?php endif;?></div>
<div class="fwtile"><small>Disk used</small><b><?=Helpers::e(Helpers::fmtMb($dn))?></b>
<?php if($dq > 0):?><span class="abbar<?= $dp >= 90 ? ' err' : ($dp >= 70 ? ' warn' : '') ?>"><i style="width:<?=$dp?>%"></i></span><span class="muted dbsub">of <?=Helpers::e(Helpers::fmtMb($dq))?><?= isset($ug['diskBurnDays']) && $ug['diskBurnDays'] < 730 ? ' · <span class="stpace'.($ug['diskBurnDays'] < 30 ? ' dbred' : '').'">full in ~'.(int)$ug['diskBurnDays'].' days</span> at this growth' : ' · plenty of room at this growth' ?></span>
<?php else:?><span class="muted dbsub">unlimited disk</span><?php endif;?></div>
</div>
<div class="dbcols">
<div class="card"><div class="fwcardhead"><h3>📶 Bandwidth — last 30 days</h3><small class="muted">all sites, measured nightly</small></div>
<?php $bwMax = !empty($ug['bw']) ? max(1, max($ug['bw'])) : 1; ?>
<div class="stchart sm"><?php for($i = 29; $i >= 0; $i--): $dd = date('Y-m-d', strtotime("-$i days")); $v = (int)($ug['bw'][$dd] ?? 0); ?><div title="<?=Helpers::e(date('D j M', (int)strtotime($dd)))?>: <?=Helpers::e($bytes($v))?>"><span class="stcol"><i style="height:<?=round($v / $bwMax * 100, 2)?>%"></i></span><small><?= $i % 7 === 0 ? Helpers::e(date('j M', (int)strtotime($dd))) : '' ?></small></div><?php endfor;?></div></div>
<?php $pts = $ug['disk'] ?? []; $top = $pts ? max(1, (int)ceil(max($pts) * 1.25), $dq > 0 && $dq < max($pts) * 3 ? $dq : 0) : 1; $showQ = $dq > 0 && $dq <= $top; ?>
<div class="card"><div class="fwcardhead"><h3>💽 Disk — last 30 days</h3><small class="muted"><?= $showQ ? 'red dashed line = quota' : 'measured nightly' ?></small></div>
<?php if(count($pts) >= 2): $poly = '';
    for($i = 29; $i >= 0; $i--){ $dd = date('Y-m-d', strtotime("-$i days")); if(isset($pts[$dd])) $poly .= (29 - $i) * (300 / 29).','.round(78 - $pts[$dd] / $top * 74, 1).' '; } ?>
<svg class="dbhist" viewBox="0 0 300 80" preserveAspectRatio="none" role="img" aria-label="disk use over 30 days"><?php if($showQ):?><line x1="0" x2="300" y1="<?=round(78 - $dq / $top * 74, 1)?>" y2="<?=round(78 - $dq / $top * 74, 1)?>" class="stquota"/><?php endif;?><polyline points="<?=trim($poly)?>"/></svg>
<div class="stlegend inl"><div>lowest <b><?=Helpers::e(Helpers::fmtMb(min($pts)))?></b></div><div>highest <b><?=Helpers::e(Helpers::fmtMb(max($pts)))?></b></div><div>now <b><?=Helpers::e(Helpers::fmtMb($dn))?></b></div></div>
<?php else:?><div class="muted stempty">Disk history builds up from the nightly measurement — check back in a couple of days.</div><?php endif;?></div>
</div>
<?php if(!empty($ug['bwDomains'])):?>
<div class="card"><div class="fwcardhead"><h3>🧮 Bandwidth by site — this month</h3></div>
<div class="stlist"><?php $mx = max(1, max($ug['bwDomains'])); $tot = max(1, array_sum($ug['bwDomains'])); foreach($ug['bwDomains'] as $dn2 => $b):?><div><span class="stk"><a href="/stats?domain=<?=urlencode((string)$dn2)?>&amp;days=<?=(int)$days?>"><?=Helpers::e((string)$dn2)?></a></span><span class="stv"><?=Helpers::e($bytes($b))?> <small><?=$pct($b, $tot)?>%</small></span><span class="stbar"><i style="width:<?=max(1, (int)round($b / $mx * 100))?>%"></i></span></div><?php endforeach;?></div></div>
<?php endif;?>
<script>
(function(){
  // remember the last domain looked at; reopen it when /stats is visited bare
  var f=document.getElementById('stPick'),inp=f?f.querySelector('input[name="domain"]'):null;
  if(!f||!inp)return;
  var q=new URLSearchParams(location.search),last='';
  try{last=localStorage.getItem('stats-domain')||'';}catch(e){}
  if(!q.get('domain')&&!q.get('user')&&last&&last.toLowerCase()!==inp.value.toLowerCase()){
    var opts=[].map.call(document.querySelectorAll('#stats-domains option'),function(o){return o.value.toLowerCase();});
    if(opts.indexOf(last.toLowerCase())>=0){location.replace('/stats?domain='+encodeURIComponent(last));return;}
  }
  try{if(inp.value)localStorage.setItem('stats-domain',inp.value);}catch(e){}
})();
</script>

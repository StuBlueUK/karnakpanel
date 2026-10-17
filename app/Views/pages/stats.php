<h2>📊 Traffic stats <small style="color:var(--muted)">from access logs</small></h2>
<?php $ug = $usage ?? []; $bwMax = !empty($ug['bw']) ? max(1, max($ug['bw'])) : 1; $dkMax = max(1, (int)($ug['diskQuotaMb'] ?? 0), !empty($ug['disk']) ? max($ug['disk']) : 0); ?>
<div class="card"><h3>📦 Account usage — <?=Helpers::e($ug['user'] ?? '?')?> <small style="color:var(--muted)">30-day bandwidth + disk vs quotas</small></h3>
<div style="display:flex;gap:6px;align-items:flex-end;height:64px;margin:6px 0" title="daily bandwidth, last 30 days">
<?php for($i = 29; $i >= 0; $i--): $d = date('Y-m-d', strtotime("-$i days")); $v = $ug['bw'][$d] ?? 0; $h = max(2, (int)round($v / $bwMax * 60)); ?>
<div style="flex:1;background:var(--accent);min-height:2px;height:<?=$h?>px" title="<?=Helpers::e($d)?>: <?=Helpers::e(Helpers::fmtMb($v/1048576))?>"></div>
<?php endfor;?></div>
<div style="display:flex;gap:6px;align-items:flex-end;height:40px;margin:6px 0" title="daily disk used (points from nightly measurement)">
<?php for($i = 29; $i >= 0; $i--): $d = date('Y-m-d', strtotime("-$i days")); $v = $ug['disk'][$d] ?? null; $h = $v === null ? 2 : max(2, (int)round($v / $dkMax * 36)); ?>
<div style="flex:1;background:<?= $v === null ? 'var(--muted)' : 'var(--ok)'?>;min-height:2px;height:<?=$h?>px" title="<?=Helpers::e($d)?>: <?= $v === null ? 'no data' : Helpers::e(Helpers::fmtMb($v))?>"></div>
<?php endfor;?></div>
<div style="display:flex;gap:18px;flex-wrap:wrap"><div>Bandwidth this month: <b><?=Helpers::e((string)($ug['bwMonthGb'] ?? 0))?> GB</b><?= ((int)($ug['bwQuotaGb'] ?? 0) > 0) ? ' of '.Helpers::e((string)$ug['bwQuotaGb']).' GB' : '' ?><?= isset($ug['bwPace']) ? ' <small style="color:var(--muted)">(pace '.$ug['bwPace'].'% of quota)</small>' : '' ?></div>
<div>Disk now: <b><?=Helpers::e(Helpers::fmtMb((int)($ug['diskNowMb'] ?? 0)))?></b><?= ((int)($ug['diskQuotaMb'] ?? 0) > 0) ? ' of '.Helpers::e(Helpers::fmtMb((int)$ug['diskQuotaMb'])).'' : '' ?><?= isset($ug['diskBurnDays']) ? ' <small style="color:var(--muted)">(full in ~'.$ug['diskBurnDays'].'d at current growth)</small>' : '' ?></div></div></div>
<div class="card"><form method="get" action="/stats" class="inline">
<input name="domain" list="stats-domains" value="<?=Helpers::e($domain)?>" placeholder="type a domain…" style="min-width:240px" autocomplete="off">
<datalist id="stats-domains"><?php foreach($domains as $d):?><option value="<?=Helpers::e($d['domain'])?>"><?php endforeach;?></datalist>
<select name="days" onchange="this.form.submit()"><?php foreach([7,14,30,90] as $n):?><option value="<?=$n?>"<?php if($days===$n):?> selected<?php endif;?>>last <?=$n?> days</option><?php endforeach;?></select>
<button class="btn small">Show</button></form></div>
<script>
(function(){
  var f = document.querySelector('form[action="/stats"]');
  var inp = f ? f.querySelector('input[name="domain"]') : null;
  if (!f || !inp) return;
  var q = new URLSearchParams(location.search);
  if (!q.get('domain')) {
    var last = localStorage.getItem('stats-domain') || '';
    if (last) {
      var opts = Array.from(document.querySelectorAll('#stats-domains option')).map(function(o){ return o.value.toLowerCase(); });
      if (opts.includes(last.toLowerCase())) { location.replace('/stats?domain=' + encodeURIComponent(last)); return; }
    }
  }
  f.addEventListener('submit', function(){ localStorage.setItem('stats-domain', inp.value); });
})();
</script>
<?php if(!empty($stats)):?>
<?php $hmax = max(1, max($stats['hours'])); ?>
<div class="card" style="display:flex;gap:18px;flex-wrap:wrap">
<div><b><?=number_format($stats['hits'])?></b> hits</div>
<div><b><?=number_format($stats['uniq'])?></b> unique IPs</div>
<div><b><?=Helpers::e(Helpers::fmtMb($stats['bytes']/1048576))?></b> served</div>
<div><b><?=number_format($stats['bots'])?></b> bot hits</div>
<div><b><?= $stats['hits']>0 ? round($stats['status']['2xx']/$stats['hits']*100,1).'% ok' : '—' ?></b></div>
</div>
<?php if(!empty($stats['daily'])):?><?php $dmax = max(1, max($stats['daily'])); ?>
<div class="card"><h3>📈 Daily hits <small style="color:var(--muted)">last <?=count($stats['daily'])?> day(s) with traffic</small></h3>
<div style="display:flex;gap:2px;align-items:flex-end;height:80px">
<?php foreach($stats['daily'] as $day=>$c):?><div title="<?=Helpers::e($day.': '.$c.' hits')?>" style="flex:1;background:linear-gradient(180deg,var(--sky2),var(--sky));border-radius:3px;min-height:2px;height:<?=max(2, round($c/$dmax*76))?>px"></div><?php endforeach;?>
</div>
<div style="display:flex;gap:2px;color:var(--muted);font-size:10px"><?php $i=0; foreach($stats['daily'] as $day=>$c):?><div style="flex:1;text-align:center"><?= $i++ % max(1, (int)(count($stats['daily'])/7)) === 0 ? substr($day, 5) : '' ?></div><?php endforeach;?></div></div><?php endif;?>
<div class="card"><h3>By hour <small style="color:var(--muted)">all days in range, server time</small></h3>
<div style="display:flex;gap:3px;align-items:flex-end;height:80px">
<?php foreach($stats['hours'] as $h=>$c):?><div title="<?=sprintf('%02d',$h)?>:00 — <?=$c?> hits" style="flex:1;background:linear-gradient(180deg,var(--sky2),var(--sky));border-radius:3px;min-height:2px;height:<?=max(2, round($c/$hmax*76))?>px"></div><?php endforeach;?>
</div>
<div style="display:flex;gap:3px;color:var(--muted);font-size:10px"><?php for($h=0;$h<24;$h++):?><div style="flex:1;text-align:center"><?= $h%3===0 ? sprintf('%02d',$h) : '' ?></div><?php endfor;?></div></div>
<div class="card"><h3>Status codes</h3>
<table><tr><th>Class</th><th>Hits</th><th></th></tr>
<?php foreach($stats['status'] as $k=>$c): $pc = $stats['hits']>0 ? round($c/$stats['hits']*100,1) : 0; ?>
<tr><td><code><?=$k?></code></td><td><?=$c?> (<?=$pc?>%)</td><td style="width:40%"><div class="bar"><i style="width:<?=$pc?>%"></i></div></td></tr><?php endforeach;?></table></div>
<div class="card"><h3>Top pages</h3><table><tr><th>Path</th><th>Hits</th></tr>
<?php foreach($stats['paths'] as $p=>$c):?><tr><td><code style="word-break:break-all"><?=Helpers::e($p)?></code></td><td><?=$c?></td></tr><?php endforeach;?>
<?php if(empty($stats['paths'])):?><tr><td colspan="2" style="color:var(--muted)">No traffic in range.</td></tr><?php endif;?></table></div>
<div class="card"><h3>Top referrers</h3><table><tr><th>Host</th><th>Hits</th></tr>
<?php foreach($stats['refs'] as $r=>$c):?><tr><td><?=Helpers::e($r)?></td><td><?=$c?></td></tr><?php endforeach;?>
<?php if(empty($stats['refs'])):?><tr><td colspan="2" style="color:var(--muted)">No referrers (direct/bookmark traffic).</td></tr><?php endif;?></table></div>
<div class="card"><h3>Top user agents</h3><table><tr><th>Agent</th><th>Hits</th></tr>
<?php foreach($stats['uas'] as $a=>$c):?><tr><td style="word-break:break-all"><small><?=Helpers::e($a)?></small></td><td><?=$c?></td></tr><?php endforeach;?>
<?php if(empty($stats['uas'])):?><tr><td colspan="2" style="color:var(--muted)">No agent data (old log lines predate agent logging).</td></tr><?php endif;?></table></div>
<div class="card"><h3>🌐 Top IPs <small style="color:var(--muted)">heaviest visitors — check against abuse before blocking</small></h3><table><tr><th>IP</th><th>Hits</th></tr>
<?php foreach(($stats['topips'] ?? []) as $ip=>$c):?><tr><td><code><?=Helpers::e($ip)?></code></td><td><?=$c?></td></tr><?php endforeach;?>
<?php if(empty($stats['topips'] ?? [])):?><tr><td colspan="2" style="color:var(--muted)">No traffic in range.</td></tr><?php endif;?></table></div>
<div class="card"><h3>🔗 Top 404s <small style="color:var(--muted)">broken links worth fixing or redirecting</small></h3><table><tr><th>Path</th><th>Hits</th></tr>
<?php foreach(($stats['notfound'] ?? []) as $p=>$c):?><tr><td><code style="word-break:break-all"><?=Helpers::e($p)?></code></td><td><?=$c?></td></tr><?php endforeach;?>
<?php if(empty($stats['notfound'] ?? [])):?><tr><td colspan="2" class="empty">No 404s — every linked path resolves.</td></tr><?php endif;?></table></div>
<?php endif;?>

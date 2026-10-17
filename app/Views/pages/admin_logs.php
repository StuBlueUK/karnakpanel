<h2>📋 Logs <small style="color:var(--muted)">✖ errors · ⚠ warnings · matches highlighted</small></h2>
<div class="card"><form method="get" action="/admin/logs" class="inline">
<select name="log" onchange="this.form.submit()"><?php $logNames = ['panel' => 'panel', 'exim' => 'exim (mail in/out)', 'exim_reject' => 'exim rejects', 'mail' => 'mail log', 'vsftpd' => 'ftp', 'fail2ban' => 'fail2ban', 'litespeed' => 'web server', 'named' => 'dns', 'upgrade' => 'system upgrade (apt)', 'update' => 'panel self-update', 'software' => 'software installs', 'apptoolkit' => 'staging clones', 'malware' => 'malware scans', 'offsite' => 'offsite backups', 'certbot' => 'ssl renewals']; foreach($svcs as $s):?><option value="<?=$s?>"<?php if($s===$svc):?> selected<?php endif;?>><?=Helpers::e($logNames[$s] ?? $s)?></option><?php endforeach;?></select>
<select name="n" onchange="this.form.submit()" title="Lines to show"><?php foreach([50,200,500] as $c):?><option value="<?=$c?>"<?php if($c===$n):?> selected<?php endif;?>><?=$c?> lines</option><?php endforeach;?></select>
<input name="q" value="<?=Helpers::e($q)?>" placeholder="filter text"> <button class="btn small">Filter</button><?php if($q!==''):?> <a class="btn small" href="/admin/logs?log=<?=urlencode($svc)?>&n=<?=$n?>&ord=<?=urlencode($ord)?>">Clear</a><?php endif;?>
<a class="btn small" href="/admin/logs?log=<?=urlencode($svc)?>&n=<?=$n?>&q=<?=urlencode($q)?>&ord=<?=$ord==='new'?'old':'new'?>" title="Flip reading order"><?=$ord==='new'?'↓ newest first':'↑ oldest first'?></a></form></div>
<div class="card"><h3 style="margin-top:0"><?=Helpers::e($svc)?> <small style="color:var(--muted)"><?=count($rows ?? [])?> lines</small></h3>
<div style="font-family:monospace;font-size:12px;line-height:1.65"><?php foreach($rows ?? [] as $r):?><?php
  $cls = ($r['lvl'] ?? 'info') === 'err' ? 'background:rgba(255,60,60,.10);border-left:3px solid #c0392b' : ((($r['lvl'] ?? '') === 'warn') ? 'background:rgba(255,170,0,.08);border-left:3px solid #d4a017' : 'border-left:3px solid transparent');
  // severity glyphs: hue alone is invisible to red/green color blindness —
  // the symbol (not the color) carries the meaning.
  $ico = ($r['lvl'] ?? 'info') === 'err' ? '<b>✖</b> ' : ((($r['lvl'] ?? '') === 'warn') ? '<b>⚠</b> ' : '');
  $msg = Helpers::e($r['msg']);
  if ($q !== '') {
    $eq = Helpers::e($q);
    $msg = str_ireplace($eq, '<mark>'.$eq.'</mark>', $msg);
  }
?><div style="<?=$cls?>;padding:1px 8px;white-space:pre-wrap;overflow-wrap:anywhere"><?=$ico?><?php if($r['ts'] !== ''):?><span style="color:var(--muted)"><?=Helpers::e($r['ts'])?></span> <?php endif;?><?=$msg?></div><?php endforeach;?>
<?php if(empty($rows ?? [])):?><p style="color:var(--muted)">No log lines (yet).</p><?php endif;?></div></div>

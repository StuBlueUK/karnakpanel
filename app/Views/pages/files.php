<?php
$jsF = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT;
$hsz = function (int $b): string {
    if ($b < 1024) return $b.' B';
    foreach (['KB','MB','GB','TB'] as $i => $unit) { $b /= 1024; if ($b < 1024 || $unit === 'TB') return round($b, $b < 10 ? 1 : 0).' '.$unit; }
    return '';
};
$fq = fn(string $r, array $extra = []) => '/files?'.http_build_query(array_filter(['p' => $r] + $extra, fn($v) => $v !== '' && $v !== null));
$icon = function (array $it): string {
    if ($it['link']) return '🔗';
    if ($it['dir']) return '📁';
    if ($it['img']) return '🖼️';
    if ($it['arch']) return '📦';
    $e = strtolower(pathinfo($it['name'], PATHINFO_EXTENSION));
    return match (true) {
        in_array($e, ['php','js','css','html','htm','json','xml','py','sh','rb','ts','sql'], true) => '📜',
        in_array($e, ['txt','md','log','ini','conf','csv','env','yml','yaml'], true) || str_starts_with($it['name'], '.') => '📝',
        in_array($e, ['pdf','doc','docx','xls','xlsx','odt'], true) => '📕',
        in_array($e, ['mp3','wav','ogg','mp4','webm','mov','avi'], true) => '🎞️',
        default => '📄',
    };
};
$sortLink = function (string $col, string $label) use ($rel, $sort, $order, $fq): string {
    $o = ($sort === $col && $order === 'asc') ? 'desc' : 'asc';
    $arrow = $sort === $col ? ($order === 'asc' ? ' ▲' : ' ▼') : '';
    return '<a href="'.Helpers::e($fq($rel, ['s' => $col, 'o' => $o])).'" style="color:inherit">'.$label.$arrow.'</a>';
};
$csrf = Helpers::csrf();
$hidden = fn(string $n, string $v) => '<input type="hidden" name="'.$n.'" value="'.Helpers::e($v).'">';
?>
<style>
#fmTable:not(.fmShowHidden) tr.fmHid{display:none}
#fmDrop{border:2px dashed var(--border,#8884);border-radius:10px;padding:14px;text-align:center;color:var(--muted);cursor:pointer}
#fmDrop.over{border-color:var(--accent,#4a8);background:rgba(80,160,120,.08)}
.fmCrumbs a{text-decoration:none}
.fmProg{display:flex;gap:8px;align-items:center;font-size:13px;margin-top:4px}
.fmProg progress{flex:1;max-width:260px}
.fmAct{white-space:nowrap}
.fmAct form{display:inline}
td.fmPerm{font-family:monospace;white-space:nowrap}
</style>
<h2>🗂️ File Manager</h2>
<?php if (is_array($trash)): ?>
<div class="card"><div style="display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap">
<h3 style="margin:0">🗑 Trash <small style="color:var(--muted)">items are deleted for good after 30 days · still count toward your disk quota</small></h3>
<span><a class="btn small" href="/files">← Back to files</a>
<?php if ($trash): ?><form method="post" action="/files/trash" style="display:inline" onsubmit="return confirm('Permanently delete everything in the trash?')"><?=$hidden('csrf', $csrf)?><?=$hidden('act', 'empty')?><button class="btn small danger">Empty trash</button></form><?php endif; ?></span></div>
<?php if (!$trash): ?><p style="color:var(--muted)">The trash is empty.</p><?php else: ?>
<table><tr><th>Name</th><th>Was in</th><th>Size</th><th>Deleted</th><th></th></tr>
<?php foreach ($trash as $t): ?>
<tr><td><?= $t['dir'] ? '📁' : '📄' ?> <?=Helpers::e($t['name'])?></td><td><small>/<?=Helpers::e($t['rel'])?></small></td><td><?=$hsz($t['size'])?></td><td style="white-space:nowrap"><?=date('Y-m-d H:i', $t['at'])?></td>
<td class="fmAct"><form method="post" action="/files/trash"><?=$hidden('csrf', $csrf)?><?=$hidden('act', 'restore')?><?=$hidden('id', $t['id'])?><button class="btn small">↩ Restore</button></form>
<form method="post" action="/files/trash" onsubmit="return confirm('Delete <?=Helpers::e($t['name'])?> for good?')"><?=$hidden('csrf', $csrf)?><?=$hidden('act', 'purge')?><?=$hidden('id', $t['id'])?><button class="btn small danger">Delete forever</button></form></td></tr>
<?php endforeach; ?></table><?php endif; ?></div>
<?php else: ?>
<div class="card">
<div class="fmCrumbs" style="font-size:15px;margin-bottom:10px">
<a href="/files">🏠 home</a><?php foreach ($crumbs as $c): ?> <span style="color:var(--muted)">/</span> <a href="<?=Helpers::e($fq($c['rel']))?>"><?=Helpers::e($c['name'])?></a><?php endforeach; ?>
<small style="color:var(--muted);margin-left:8px"><?=Helpers::e($path)?></small></div>
<div id="fmDrop" tabindex="0">⬆ <b>Drop files here</b> or click to choose <small>(several at once; big files are sent in parts)</small></div>
<input type="file" id="fmFiles" multiple style="display:none">
<div id="fmProgress"></div>
<noscript><form method="post" action="/files/upload" enctype="multipart/form-data" style="margin-top:8px"><?=$hidden('csrf', $csrf)?><?=$hidden('p', $rel)?><input type="file" name="f[]" multiple required><button class="btn">Upload</button> <small>max <?=Helpers::e(Helpers::fmtMb($upMax / 1048576))?> per upload without JavaScript</small></form></noscript>
<div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px;align-items:center">
<form method="post" action="/files/mkdir" class="inline"><?=$hidden('csrf', $csrf)?><?=$hidden('p', $rel)?><input name="name" placeholder="new folder" required style="max-width:160px"><button class="btn small">📁 New folder</button></form>
<form method="post" action="/files/new" class="inline"><?=$hidden('csrf', $csrf)?><?=$hidden('p', $rel)?><input name="name" placeholder="new file (.htaccess)" required style="max-width:160px"><button class="btn small">📄 New file</button></form>
<?php if ($sys): ?><a class="btn small" href="/files?trash=1" style="margin-left:auto">🗑 Trash</a><?php endif; ?>
</div></div>

<div class="card" id="fmClip" style="display:none"><span id="fmClipText"></span>
<button class="btn small" id="fmPaste">📋 Paste here</button> <button class="btn small" id="fmClipClear">Clear</button></div>

<?php if ($found !== null): ?>
<div class="card"><h3 style="margin-top:0">🔍 “<?=Helpers::e($q)?>” under /<?=Helpers::e($rel)?> <small style="color:var(--muted)"><?=count($found)?> match(es)<?= count($found) >= 200 ? ' — first 200 shown' : '' ?></small> <a class="btn small" href="<?=Helpers::e($fq($rel))?>">Clear search</a></h3>
<?php if (!$found): ?><p style="color:var(--muted)">Nothing found.</p><?php else: ?>
<table><tr><th>Name</th><th>Folder</th></tr>
<?php foreach ($found as $f): $target = $f['dir'] ? ltrim($f['rel'].'/'.$f['name'], '/') : $f['rel']; ?>
<tr><td><?= $f['dir'] ? '📁' : '📄' ?> <a href="<?=Helpers::e($fq($target))?>"><?=Helpers::e($f['name'])?></a></td><td><small>/<?=Helpers::e($f['rel'])?></small></td></tr>
<?php endforeach; ?></table><?php endif; ?></div>
<?php endif; ?>

<div class="card"><div style="margin-bottom:8px;display:flex;gap:8px;flex-wrap:wrap;align-items:center">
<input id="fmFilter" placeholder="filter this folder…" style="max-width:180px">
<form method="get" action="/files" class="inline"><?=$hidden('p', $rel)?><input name="q" value="<?=Helpers::e($q)?>" placeholder="🔍 search subfolders…" style="max-width:180px"><button class="btn small">Search</button></form>
<label style="font-size:13px;white-space:nowrap"><input type="checkbox" id="fmHidToggle"> show hidden (dotfiles)</label>
<span id="fmBulk" style="display:none;gap:6px;align-items:center;flex-wrap:wrap">
<b id="fmSelN"></b>
<?php if ($sys): ?><button class="btn small" id="fmCopy">⧉ Copy</button><button class="btn small" id="fmCut">✂ Cut</button><?php endif; ?>
<button class="btn small" id="fmBulkDel"><?= $sys ? '🗑 Trash' : 'Delete' ?></button>
<?php if ($sys): ?><button class="btn small danger" id="fmBulkPerm">Delete permanently</button>
<button class="btn small" id="fmBulkZip">📦 Pack</button><?php endif; ?>
<button class="btn small" id="fmBulkDl">⬇ Download zip</button>
</span></div>
<table id="fmTable"><tr><th><input type="checkbox" id="fmAll" title="select all"></th><th><?=$sortLink('name', 'Name')?></th><th><?=$sortLink('size', 'Size')?></th><th><?=$sortLink('date', 'Modified')?></th><th>Perms</th><th></th></tr>
<?php if ($rel !== ''): ?><tr><td></td><td><a href="<?=Helpers::e($fq(dirname($rel) === '.' ? '' : dirname($rel)))?>">⬆ ..</a></td><td>—</td><td>—</td><td></td><td></td></tr><?php endif; ?>
<?php foreach ($items as $it): $n = $it['name']; $isHid = $n[0] === '.'; $q2 = 'p='.urlencode($rel).'&amp;name='.urlencode($n); ?>
<tr class="<?= $isHid ? 'fmHid' : '' ?>"><td><?php if ($it['ok']): ?><input type="checkbox" class="fmSel" value="<?=Helpers::e($n)?>"><?php endif; ?></td>
<td><?=$icon($it)?> <?php if ($it['dir'] && $it['ok']): ?><a href="<?=Helpers::e($fq(ltrim($rel.'/'.$n, '/')))?>"><?=Helpers::e($n)?></a><?php else: ?><?=Helpers::e($n)?><?php endif; ?><?php if (!$it['ok']): ?> <span class="pill" title="managed by the panel">🔒</span><?php elseif ($it['link']): ?> <small style="color:var(--muted)">symlink</small><?php endif; ?></td>
<td style="white-space:nowrap"><?= $it['dir'] ? '—' : $hsz($it['size']) ?></td>
<td style="white-space:nowrap"><?= $it['mtime'] ? date('Y-m-d H:i', $it['mtime']) : '—' ?></td>
<td class="fmPerm"><?=Helpers::e($it['perms'])?> <small style="color:var(--muted)"><?=Helpers::e($it['owner'])?></small></td>
<td class="fmAct"><?php if ($it['ok']): ?>
<?php if (!$it['dir'] && !$it['link']): ?>
<?php if ($it['img']): ?><a class="btn small" href="/files/preview?<?=$q2?>" target="_blank" rel="noopener" title="View">👁</a><?php elseif (!$it['arch']): ?><a class="btn small" href="/files/edit?<?=$q2?>">Edit</a><?php endif; ?>
<a class="btn small" href="/files/download?<?=$q2?>" title="Download">⬇</a>
<?php endif; ?>
<?php if (!$it['link']): ?><button class="btn small fmRn" title="Rename" data-n="<?=Helpers::e($n)?>">✏️</button><?php endif; ?>
<?php if ($it['arch'] && $sys): ?><form method="post" action="/files/unzip" onsubmit="return confirm('Extract <?=Helpers::e($n)?> here? Existing files with the same names are overwritten.')"><?=$hidden('csrf', $csrf)?><?=$hidden('p', $rel)?><?=$hidden('name', $n)?><button class="btn small">📦 Extract</button></form><?php endif; ?>
<?php if (!$it['link']): ?><button class="btn small fmCh" title="Permissions" data-n="<?=Helpers::e($n)?>" data-m="<?=Helpers::e($it['perms'])?>">🔑</button><?php endif; ?>
<form method="post" action="/files/delete" onsubmit="return confirm(<?=Helpers::e(json_encode(($sys ? 'Move to trash: ' : 'Delete ').$n.($it['dir'] && !$it['link'] ? ' (whole folder)' : '').'?', $jsF))?>)"><?=$hidden('csrf', $csrf)?><?=$hidden('p', $rel)?><?=$hidden('name', $n)?><button class="btn small" title="<?= $sys ? 'Move to trash' : 'Delete' ?>">🗑</button></form>
<?php endif; ?></td></tr>
<?php endforeach; ?></table>
<?php if (!$items): ?><p style="color:var(--muted)">This folder is empty.</p><?php endif; ?></div>
<script>
(function(){
var CSRF = <?=json_encode($csrf)?>, REL = <?=json_encode($rel, $jsF)?>, UPMAX = <?=(int)$upMax?>;
function post(action, fields){
  var f = document.createElement('form');
  f.method = 'post'; f.action = action;
  [['csrf', CSRF]].concat(fields).forEach(function(p){
    var i = document.createElement('input'); i.type = 'hidden'; i.name = p[0]; i.value = p[1]; f.appendChild(i);
  });
  document.body.appendChild(f); f.submit();
}
// hidden files toggle (remembered per browser)
var tbl = document.getElementById('fmTable'), hid = document.getElementById('fmHidToggle');
try { hid.checked = localStorage.getItem('kpFmHidden') === '1'; } catch (e) {}
function applyHid(){ tbl.classList.toggle('fmShowHidden', hid.checked); }
hid.addEventListener('change', function(){ applyHid(); try { localStorage.setItem('kpFmHidden', hid.checked ? '1' : '0'); } catch (e) {} });
applyHid();
document.getElementById('fmFilter').addEventListener('input', function(){
  var q = this.value.toLowerCase();
  tbl.querySelectorAll('tr').forEach(function(r, i){ if (i) r.style.display = r.textContent.toLowerCase().includes(q) ? '' : 'none'; });
});
document.querySelectorAll('.fmRn').forEach(function(b){
  b.addEventListener('click', function(){
    var old = b.getAttribute('data-n'), n = prompt('Rename "' + old + '" to:', old);
    if (n && n !== old) post('/files/rename', [['p', REL], ['name', old], ['newname', n]]);
  });
});
document.querySelectorAll('.fmCh').forEach(function(b){
  b.addEventListener('click', function(){
    var m = prompt('Permissions for "' + b.getAttribute('data-n') + '" (3 octal digits, e.g. 644 files / 755 folders / 600 secrets):', b.getAttribute('data-m'));
    if (m && /^[0-7]{3}$/.test(m)) post('/files/chmod', [['p', REL], ['name', b.getAttribute('data-n')], ['mode', m]]);
    else if (m) alert('Use 3 digits 0-7, e.g. 644');
  });
});
// selection + bulk bar
var boxes = Array.from(document.querySelectorAll('.fmSel'));
function visible(c){ var r = c.closest('tr'); return r.style.display !== 'none' && getComputedStyle(r).display !== 'none'; }
document.getElementById('fmAll').addEventListener('change', function(){
  var on = this.checked; boxes.forEach(function(c){ c.checked = on && visible(c); }); count();
});
boxes.forEach(function(c){ c.addEventListener('change', count); });
function sel(){ return boxes.filter(function(c){ return c.checked; }).map(function(c){ return c.value; }); }
function count(){
  var n = sel().length;
  document.getElementById('fmBulk').style.display = n ? 'inline-flex' : 'none';
  document.getElementById('fmSelN').textContent = n + ' selected';
}
function bulk(action, extra){
  var s = sel(); if (!s.length) return;
  post(action, [['p', REL]].concat(s.map(function(n){ return ['sel[]', n]; }), extra || []));
}
function on(id, fn){ var e = document.getElementById(id); if (e) e.addEventListener('click', fn); }
on('fmBulkDel', function(){ if (confirm(<?= $sys ? "'Move ' + sel().length + ' item(s) to the trash?'" : "'Delete ' + sel().length + ' item(s)?'" ?>)) bulk('/files/bulk-delete'); });
on('fmBulkPerm', function(){ if (confirm('Permanently delete ' + sel().length + ' item(s)? This cannot be undone.')) bulk('/files/bulk-delete', [['perm', '1']]); });
on('fmBulkZip', function(){
  var a = prompt('Archive name (.zip or .tar.gz):', sel().length === 1 ? sel()[0] + '.zip' : 'archive.zip');
  if (a) bulk('/files/zip', [['archive', a]]);
});
on('fmBulkDl', function(){ bulk('/files/download-zip'); });
// clipboard: copy/cut here, paste in any folder (kept for this tab)
function clip(v){
  try { if (v === undefined) return JSON.parse(sessionStorage.getItem('kpFmClip') || 'null');
        if (v === null) sessionStorage.removeItem('kpFmClip'); else sessionStorage.setItem('kpFmClip', JSON.stringify(v)); } catch (e) {}
  return null;
}
function showClip(){
  var c = clip(), box = document.getElementById('fmClip');
  if (!c || !c.names || !c.names.length) { box.style.display = 'none'; return; }
  box.style.display = '';
  document.getElementById('fmClipText').textContent = (c.mode === 'move' ? '✂ ' : '⧉ ') + c.names.length + ' item(s) from /' + c.src + (c.mode === 'move' ? ' to move' : ' to copy') + ' — ';
}
on('fmCopy', function(){ clip({mode: 'copy', src: REL, names: sel()}); showClip(); });
on('fmCut', function(){ clip({mode: 'move', src: REL, names: sel()}); showClip(); });
on('fmClipClear', function(){ clip(null); showClip(); });
on('fmPaste', function(){
  var c = clip(); if (!c) return;
  if (c.mode === 'move') clip(null);
  post('/files/paste', [['p', REL], ['mode', c.mode], ['src', c.src]].concat(c.names.map(function(n){ return ['sel[]', n]; })));
});
showClip();
// uploads: drag & drop / picker, sent in parts of at most UPMAX bytes
var drop = document.getElementById('fmDrop'), pick = document.getElementById('fmFiles'), prog = document.getElementById('fmProgress');
drop.addEventListener('click', function(){ pick.click(); });
drop.addEventListener('keydown', function(e){ if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); pick.click(); } });
['dragenter', 'dragover'].forEach(function(t){ drop.addEventListener(t, function(e){ e.preventDefault(); drop.classList.add('over'); }); });
['dragleave', 'drop'].forEach(function(t){ drop.addEventListener(t, function(e){ e.preventDefault(); drop.classList.remove('over'); }); });
drop.addEventListener('drop', function(e){ if (e.dataTransfer && e.dataTransfer.files.length) upload(e.dataTransfer.files); });
pick.addEventListener('change', function(){ if (pick.files.length) upload(pick.files); });
function rid(){ var a = new Uint8Array(12); crypto.getRandomValues(a); return Array.from(a, function(b){ return ('0' + b.toString(16)).slice(-2); }).join(''); }
function upload(list){
  var files = Array.from(list), errors = 0, done = 0;
  (function next(){
    if (!files.length) { if (!errors) location.reload(); else { var b = document.createElement('button'); b.className = 'btn small'; b.textContent = 'Refresh listing'; b.onclick = function(){ location.reload(); }; prog.appendChild(b); } return; }
    var f = files.shift(), row = document.createElement('div'), bar = document.createElement('progress'), lab = document.createElement('span');
    row.className = 'fmProg'; bar.max = Math.max(1, f.size); bar.value = 0;
    lab.textContent = f.name; row.appendChild(lab); row.appendChild(bar); prog.appendChild(row);
    var chunk = Math.max(65536, UPMAX - 4096), id = rid(), off = 0;
    function fail(msg){ errors++; bar.remove(); lab.textContent = '✗ ' + f.name + ': ' + msg; lab.style.color = 'var(--err, #c33)'; next(); }
    (function send(){
      var part = f.slice(off, Math.min(f.size, off + chunk)), fd = new FormData(), x = new XMLHttpRequest();
      fd.append('csrf', CSRF); fd.append('p', REL); fd.append('id', id); fd.append('name', f.name);
      fd.append('size', f.size); fd.append('offset', off); fd.append('c', part, 'part');
      x.open('POST', '/files/upload-chunk');
      x.upload.onprogress = function(e){ if (e.lengthComputable) bar.value = off + e.loaded; };
      x.onload = function(){
        var r; try { r = JSON.parse(x.responseText); } catch (e) { return fail('server error (' + x.status + ')'); }
        if (!r.ok) return fail(r.msg);
        off += part.size; bar.value = off;
        if (r.done || off >= f.size) { done++; lab.textContent = '✓ ' + r.msg; bar.remove(); next(); } else send();
      };
      x.onerror = function(){ fail('network error'); };
      x.send(fd);
    })();
  })();
}
})();
</script>
<?php endif; ?>

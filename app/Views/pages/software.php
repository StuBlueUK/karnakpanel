<?php
$csrf = Helpers::csrf();
$avail = array_filter($apps, fn($a) => empty($a['unavailable']));
$pick = (string)($_GET['app'] ?? '');
if (!isset($avail[$pick])) $pick = (string)(array_key_first($avail) ?? '');
$running = array_filter($bg ?? [], fn($b) => $b[0] === 'running');
$failed = array_filter($bg ?? [], fn($b) => $b[0] === 'fail');
$ago = function (int $t): string { $m = intdiv(max(0, time() - $t), 60); return $m < 1 ? 'just now' : ($m < 60 ? "$m min ago" : intdiv($m, 60).' h ago'); };
$req = function (array $a): array {
    $r = [];
    $r[] = !empty($a['root_only']) ? ['📍', 'Domain root only'] : ['📁', 'Root or any folder'];
    if (!empty($a['needs_php'])) $r[] = ['🐘', 'PHP '.$a['needs_php'].' only'];
    elseif (!empty($a['php_min'])) $r[] = ['🐘', 'PHP '.$a['php_min'].'+'];
    $r[] = !empty($a['needs_admin']) ? ['👤', 'Admin set up here'] : ['🧭', 'Finish in browser'];
    return $r;
};
// what the page's script needs per app (no secrets, all static catalogue data)
$js = [];
foreach ($apps as $k => $a) $js[$k] = ['name' => $a['name'], 'icon' => $a['icon'] ?? '📦', 'root' => !empty($a['root_only']), 'admin' => !empty($a['needs_admin']),
    'min' => $a['php_min'] ?? '', 'exact' => $a['needs_php'] ?? '', 'setup' => $a['setup'] ?? '', 'pmin' => (int)($a['pass_min'] ?? 10)];
?>
<div class="fwhead swhead"><h2>🧰 Software <small style="color:var(--muted)">one-click installs</small></h2>
<span class="pill"><?= (int)$total ?> installed</span>
<?php if ($running):?><span class="pill warn"><span class="spin" aria-hidden="true"></span> <?= count($running) ?> installing</span><?php endif;?></div>

<?php if (empty($domains)):?>
<div class="card swempty"><b>Add a domain first.</b> Apps install into one of your domains, so you need one before you can install anything. <a class="btn small" href="/domains">Go to Domains</a></div>
<?php endif;?>

<div class="swcat">
<?php foreach ($apps as $key => $a): $off = !empty($a['unavailable']); ?>
<article class="swapp<?= $off ? ' off' : '' ?><?= $key === $pick ? ' on' : '' ?>" data-app="<?=Helpers::e($key)?>">
  <div class="swtop"><span class="swic" aria-hidden="true"><?=Helpers::e($a['icon'] ?? '📦')?></span><div><h3><?=Helpers::e($a['name'])?></h3><small><?=Helpers::e($a['kind'] ?? '')?></small></div></div>
  <p><?=Helpers::e($a['desc'])?></p>
  <ul class="swreq"><?php foreach ($req($a) as [$i, $t]):?><li><span aria-hidden="true"><?=$i?></span> <?=Helpers::e($t)?></li><?php endforeach;?></ul>
  <?php if ($off):?><p class="swwhy"><?=Helpers::e($a['why'] ?? 'Not available on this server')?></p>
  <?php else:?><button type="button" class="btn small" data-pick="<?=Helpers::e($key)?>"<?= empty($domains) ? ' disabled' : '' ?>>Install <?=Helpers::e($a['name'])?> →</button><?php endif;?>
</article>
<?php endforeach;?>
</div>

<?php if (!empty($domains) && $avail):?>
<div class="card swform" id="sw-form">
<div class="fwcardhead"><h3 id="sw-title">Install</h3><small class="muted">Needs an empty folder. A new database is created for it automatically.</small></div>
<?php if (!empty($dbErr)):?><div class="alert err" style="margin:0 0 12px"><?=Helpers::e($dbErr)?> Every app needs its own database, so installs are blocked until one is free.</div><?php endif;?>
<form method="post" action="/software/install" class="acform swfields" id="sw-f">
<input type="hidden" name="csrf" value="<?=$csrf?>">
<label>App<select name="app" id="sw-app"><?php foreach ($avail as $key => $a):?><option value="<?=Helpers::e($key)?>"<?= $key === $pick ? ' selected' : '' ?>><?=Helpers::e(($a['icon'] ?? '').' '.$a['name'])?></option><?php endforeach;?></select></label>
<label>Domain<select name="domain_id" id="sw-dom"><?php foreach ($domains as $d):?><option value="<?=(int)$d['id']?>" data-host="<?=Helpers::e($d['domain'])?>" data-php="<?=Helpers::e((string)($d['php_version'] ?? ''))?>" data-ssl="<?= !empty($d['ssl_enabled']) ? '1' : '0' ?>"><?=Helpers::e($d['domain'])?></option><?php endforeach;?></select></label>
<label id="sw-subl"><span>Folder <span class="swhint">(empty = domain root)</span></span><span class="swpre"><span id="sw-host">/</span><input name="subdir" id="sw-sub" placeholder="blog" pattern="[a-zA-Z0-9_-]{1,64}" title="letters, digits, _ and - (saved in lower case)" maxlength="64" autocomplete="off"></span></label>
<label>Site title<input name="title" id="sw-name" maxlength="80" placeholder="My site"></label>
<label class="sw-adm">Admin email<input name="admin_email" type="email" autocomplete="off" placeholder="you@example.com"></label>
<label class="sw-adm">Admin username<input name="admin_user" autocomplete="off" pattern="[a-zA-Z0-9_.-]{3,32}" maxlength="32" placeholder="3–32 letters, digits . _ -"></label>
<label class="sw-adm">Admin password<input name="admin_pass" id="sw-pass" type="password" autocomplete="new-password" minlength="10" maxlength="128" placeholder="10+ characters"></label>
<div class="swsum" id="sw-sum" aria-live="polite"></div>
<div class="alert err swphp" id="sw-php" hidden></div>
<p class="swnote" data-for="vvveb" hidden>Vvveb's newsletter plugin is no longer in its release download. You can get it free from plugins.vvveb.com and enable it in the Vvveb admin.</p>
<div class="swgo"><button class="btn" id="sw-go"<?= !empty($dbErr) ? ' disabled' : '' ?>>Install</button></div>
</form></div>
<?php endif;?>

<div class="card"><div class="fwcardhead"><h3>Installed apps</h3><small class="muted">WordPress auto-updates cover minor core releases and plugins only. Themes and major versions stay manual, and content, uploads and settings are never touched. Keep scheduled backups on as the safety net.</small></div>
<div class="swlist">
<?php foreach ($running as $where => [$st, $app, $started]): $a = $apps[$app] ?? []; ?>
<div class="swrow busy" data-swwait="1"><span class="swic" aria-hidden="true"><?=Helpers::e($a['icon'] ?? '📦')?></span>
<div class="swmain"><b><span class="spin" aria-hidden="true"></span> Installing <?=Helpers::e($a['name'] ?? $app)?>…</b><small><?=Helpers::e($where)?> · started <?=$ago($started)?> · this page refreshes when it's done</small></div></div>
<?php endforeach;?>
<?php foreach ($failed as $where => [$st, $app, $started, $msg]): $a = $apps[$app] ?? []; ?>
<div class="swrow bad"><span class="swic" aria-hidden="true"><?=Helpers::e($a['icon'] ?? '📦')?></span>
<div class="swmain"><b><?=Helpers::e($a['name'] ?? $app)?> install failed <span class="pill err mini">failed</span></b><small><?=Helpers::e($where)?> · <?=$ago($started)?></small>
<span class="swerr"><?=Helpers::e($msg !== '' ? $msg : 'No reason was recorded')?></span><small>Fix the cause and install again. If the folder isn't empty any more, clear it in the <a href="/files">File Manager</a> first.<?= $isAdmin ? ' Full output: Logs → software installs.' : '' ?></small></div></div>
<?php endforeach;?>
<?php foreach ($installs as $s): $a = $apps[$s['app']] ?? []; $url = (string)$s['url']; $lk = $links[$s['id']] ?? null; $ver = (string)($s['version'] ?? ''); ?>
<div class="swrow"><span class="swic" aria-hidden="true"><?=Helpers::e($a['icon'] ?? '📦')?></span>
<div class="swmain"><b><?=Helpers::e($a['name'] ?? $s['app'])?><?php if ($ver !== '' && $ver !== 'ready'):?> <span class="badge"><?=Helpers::e($ver)?></span><?php endif;?></b>
<a class="domlink" href="<?=Helpers::e($url)?>" target="_blank" rel="noopener" title="Open site in new window"><?=Helpers::e(preg_replace('#^https?://#', '', $url))?> <small>↗</small></a>
<small><?= ($s['path'] ?? '') !== '' ? 'folder /'.Helpers::e($s['path']) : 'domain root' ?> · installed <?=Helpers::e(substr((string)$s['created_at'], 0, 10))?></small></div>
<div class="swact">
<?php if ($lk):?><a class="btn small" href="<?=Helpers::e(rtrim($url, '/').$lk[1])?>" target="_blank" rel="noopener"><?=Helpers::e($lk[0])?> ↗</a><?php endif;?>
<?php if (($s['app'] ?? '') === 'wordpress'): $on = (($auto ?? [])[$s['id']] ?? 'off') === 'on'; ?>
<form method="post" action="/software/autoupdate" class="swtog"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="id" value="<?=(int)$s['id']?>"><input type="hidden" name="on" value="<?= $on ? '0' : '1' ?>">
<button class="swswitch<?= $on ? ' on' : '' ?>" role="switch" aria-checked="<?= $on ? 'true' : 'false' ?>" title="<?= $on ? 'Auto-updates on: click to turn off' : 'Auto-updates off: click to turn on' ?>"><i aria-hidden="true"></i> Auto-updates <?= $on ? 'on' : 'off' ?></button></form>
<a class="btn small ghost" href="/toolkit" title="Make a staging copy to test changes safely">🧪 Staging</a>
<?php endif;?>
<?php $where = preg_replace('#^https?://#', '', $url); $isRoot = ($s['path'] ?? '') === '';
$warn = 'Remove '.($a['name'] ?? $s['app']).' from '.$where.'?'."\n\n"
  .($isRoot ? 'All of its files in the domain\'s web folder are deleted (Karnak Builder sites, other apps\' folders and SSL files are kept) and the "coming soon" page comes back.'
            : 'The folder /'.$s['path'].' and everything in it is deleted.')
  ."\nIf \"and its database\" is ticked, the database with all posts, pages and users is deleted too.\n\nThis cannot be undone; only a backup brings it back."; ?>
<form method="post" action="/software/uninstall" class="swdel" onsubmit="return confirm(<?=Helpers::e(json_encode($warn))?>)"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="id" value="<?=(int)$s['id']?>">
<label title="Also delete this app's database"><input type="checkbox" name="drop_db" value="1" checked> and its database</label>
<button class="btn small danger" title="Delete this app's files<?= $isRoot ? '' : ' folder' ?>">🗑 Remove</button></form>
</div></div>
<?php endforeach;?>
<?php if (empty($installs) && !$running && !$failed):?><p class="muted swnone">Nothing installed yet. Pick an app above to get started.</p><?php endif;?>
</div>
<?php $pagerBase = '/software?'; $pagerLabel = 'installs'; require __DIR__.'/_pager.php'; ?></div>

<script>
(function(){
  const APPS = <?= json_encode($js, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
  const f = document.getElementById('sw-f');
  if (f) {
    const app = document.getElementById('sw-app'), dom = document.getElementById('sw-dom'), sub = document.getElementById('sw-sub'),
      subl = document.getElementById('sw-subl'), host = document.getElementById('sw-host'), sum = document.getElementById('sw-sum'),
      php = document.getElementById('sw-php'), go = document.getElementById('sw-go'), title = document.getElementById('sw-title'),
      name = document.getElementById('sw-name'), blocked = go.disabled;
    const ver = v => String(v).split('.').map(n => parseInt(n, 10) || 0);
    const atLeast = (have, min) => { const a = ver(have), b = ver(min); for (let i = 0; i < Math.max(a.length, b.length); i++) { if ((a[i] || 0) !== (b[i] || 0)) return (a[i] || 0) > (b[i] || 0); } return true; };
    const fits = (A, p) => A.exact ? p === A.exact : (A.min ? atLeast(p, A.min) : true);
    const need = A => A.exact ? 'PHP ' + A.exact : 'PHP ' + A.min + ' or newer';
    function render() {
      const k = app.value, A = APPS[k]; if (!A) return;
      title.textContent = 'Install ' + A.icon + ' ' + A.name;
      go.textContent = 'Install ' + A.name;
      name.placeholder = A.name === 'PrestaShop' ? 'My shop' : (A.name === 'phpBB' || A.name === 'Flarum' ? 'My forum' : 'My site');
      document.querySelectorAll('.swapp').forEach(c => c.classList.toggle('on', c.dataset.app === k));
      document.querySelectorAll('[data-for]').forEach(n => n.hidden = n.dataset.for !== k);
      document.querySelectorAll('.sw-adm').forEach(l => { l.hidden = !A.admin; const i = l.querySelector('input'); i.disabled = !A.admin; i.required = A.admin; });
      subl.hidden = A.root; sub.disabled = A.root; if (A.root) sub.value = '';
      const pw = document.getElementById('sw-pass'); pw.minLength = A.pmin; pw.placeholder = A.pmin + '+ characters';
      let firstOk = null;
      [...dom.options].forEach(o => { const ok = fits(A, o.dataset.php); o.disabled = !ok;
        o.textContent = o.dataset.host + (ok ? '' : '  (PHP ' + o.dataset.php + ', needs ' + (A.exact || A.min + '+') + ')');
        if (ok && firstOk === null) firstOk = o; });
      if (dom.selectedOptions[0] && dom.selectedOptions[0].disabled && firstOk) firstOk.selected = true;
      const o = dom.selectedOptions[0], ok = o && !o.disabled;
      php.hidden = ok; sum.hidden = !ok;
      if (!ok) { php.textContent = ''; php.append(A.name + ' needs ' + need(A) + ' and none of your domains is on it. Switch a domain on the ');
        const l = document.createElement('a'); l.href = '/php'; l.textContent = 'PHP page'; php.append(l, ' first, then come back.'); }
      go.disabled = blocked || !ok;
      const h = o ? o.dataset.host : '';
      host.textContent = h + '/';
      const path = (!A.root && sub.value.trim()) ? '/' + sub.value.trim().toLowerCase() : '';
      sum.textContent = '';
      if (o) { const b = document.createElement('b'); b.textContent = (o.dataset.ssl === '1' ? 'https://' : 'http://') + h + path;
        sum.append('Installs at ', b, ' · new database · next: ' + A.setup);
        if (o.dataset.ssl !== '1') sum.append(' · tip: issue SSL for this domain first so the app stores an https address'); }
    }
    app.addEventListener('change', render); dom.addEventListener('change', render); sub.addEventListener('input', render);
    document.querySelectorAll('[data-pick]').forEach(b => b.addEventListener('click', () => {
      app.value = b.dataset.pick; render();
      document.getElementById('sw-form').scrollIntoView({behavior: 'smooth', block: 'start'});
      setTimeout(() => dom.focus({preventScroll: true}), 350);
    }));
    f.addEventListener('submit', () => { setTimeout(() => { go.disabled = true; go.textContent = 'Starting…'; }, 0); });
    render();
  }
  // while an install runs: poll, reload once the running count drops
  if (document.querySelector('[data-swwait]')) {
    const start = document.querySelectorAll('[data-swwait]').length, t0 = Date.now();
    const tick = () => {
      if (Date.now() - t0 > 20 * 60 * 1000) return;
      if (document.hidden) { setTimeout(tick, 5000); return; }
      fetch('/software/pending', {credentials: 'same-origin', cache: 'no-store'}).then(r => r.json()).then(j => {
        const a = document.activeElement, typing = a && /^(INPUT|TEXTAREA|SELECT)$/.test(a.tagName) && a.value;
        if ((j.running | 0) < start && !typing) location.reload(); else setTimeout(tick, 5000);
      }).catch(() => setTimeout(tick, 10000));
    };
    setTimeout(tick, 5000);
  }
})();
</script>

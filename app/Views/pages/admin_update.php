<h2>⬆️ System update <small style="color:var(--muted)">apt for the whole box</small></h2>
<?php if(!empty($update) && ($update['sev'] ?? '') !== 'unreachable'):?><div class="alert <?=($update['sev'] ?? '') === 'urgent' ? 'err' : 'ok'?>"><?=($update['sev'] ?? '') === 'urgent' ? '🔴' : '🔵'?> Panel update available: <b><?=Helpers::e($update['remote'])?></b> (installed: <?=Helpers::e($update['local'])?>)<?=($update['sev'] ?? '') === 'urgent' ? ' — SECURITY release, prioritize section 2 below' : ''?></div><?php endif;?>
<?php
$sy = $sys ?? ['ok' => false, 'pkgs' => [], 'security' => 0, 'kept' => [], 'lists' => 0, 'auto' => false, 'running' => 'no', 'rebootPkgs' => ''];
$au = $auto ?? ['ok' => false, 'installed' => false, 'security' => true, 'reboot' => false, 'all' => false, 'hour' => 'os', 'next' => 0, 'last' => null];
?>
<?php if($reboot):?><div class="alert err">🔁 <b>Reboot required</b> — updates installed that only take effect after a restart<?= ($sy['rebootPkgs'] ?? '') !== '' ? ' ('.Helpers::e($sy['rebootPkgs']).')' : '' ?>. Use the red button in section 3 below when convenient.</div><?php endif;?>
<?php
$np = count($sy['pkgs']); $ns = (int)$sy['security'];
$age = $sy['lists'] > 0 ? max(0, time() - $sy['lists']) : null;
$ageTxt = $age === null ? 'never' : ($age < 3600 ? max(1, (int)($age / 60)).' min ago' : ($age < 172800 ? (int)($age / 3600).' h ago' : (int)($age / 86400).' days ago'));
$liveOpen = ($sy['running'] === 'panel') || !empty($_GET['live']);
// just started (or just finished) from here: the live log goes to the TOP of
// the page instead of a #live jump — the sticky topbar covered the card and
// the jump threw the admin away from where they clicked.
$liveTop = $liveOpen || !empty($_GET['ran']);
ob_start();
?>
<div class="card" id="live"><h3 style="margin-top:0">📟 <span id="lv-title"><?= $liveOpen ? 'Update running' : 'Last update run' ?></span> <span id="lv-state" class="pill mini"></span></h3>
<div id="lv-prog" style="display:none;margin-bottom:8px"><small id="lv-phase"></small><div class="bar mbar"><i id="lv-bar" style="width:0%"></i></div></div>
<pre id="lv-log" style="max-height:420px;overflow:auto;white-space:pre-wrap;font-size:12px;margin:0;background:rgba(0,0,0,.25);border:1px solid var(--line);border-radius:8px;padding:8px">Loading…</pre>
<small style="color:var(--muted)">Full history: <a href="/admin/logs?log=upgrade">Logs → upgrade</a>. Closing this page doesn't stop the update.</small></div>
<?php $liveCard = ob_get_clean();
// KarnakPanel's own update/rollback: same idea, fed by update.log. At the top
// right after Install/Rollback (or its finish reload); otherwise folded into
// section 2 as "last panel update", and it unfolds itself if a run is live.
$pTop = !empty($_GET['plive']) || !empty($_GET['pran']);
ob_start(); ?>
<div class="card" id="plive"><h3 style="margin-top:0">📦 <span id="pl-title"><?= !empty($_GET['plive']) ? 'Panel update running' : 'Last panel update' ?></span> <span id="pl-state" class="pill mini"></span></h3>
<div id="pl-prog" style="display:none;margin-bottom:8px"><small id="pl-phase"></small><div class="bar mbar"><i id="pl-bar" style="width:0%"></i></div></div>
<pre id="pl-log" style="max-height:360px;overflow:auto;white-space:pre-wrap;font-size:12px;margin:0;background:rgba(0,0,0,.25);border:1px solid var(--line);border-radius:8px;padding:8px">Loading…</pre>
<small style="color:var(--muted)">Full history: <a href="/admin/logs?log=update">Logs → update</a>. Closing this page doesn't stop the update; the panel drops for a few seconds while the web server restarts, and this view reconnects by itself.</small></div>
<?php $panelCard = ob_get_clean();
if($pTop) echo $panelCard;
if($liveTop) echo $liveCard; ?>
<?php if($sy['ok'] && $sy['running'] !== 'panel'):?>
<?php if($ns > 0):
  $secP = array_filter($sy['pkgs'], fn($p) => $p['kind'] === 'security');
  // reboot-needing ones are left out of the nightly run unless allowed
  $manual = ($au['ok'] && !$au['reboot']) ? array_filter($secP, fn($p) => !empty($p['reboot'])) : [];
  $nightly = $au['ok'] ? $au['security'] : $sy['auto'];?>
<div class="alert err">🔴 <b><?=$ns?> security update<?=$ns === 1 ? '' : 's'?> waiting</b> — <?=Helpers::e(implode(', ', array_slice(array_column($secP, 'name'), 0, 6)))?><?=$ns > 6 ? '…' : ''?>. Install now with <b>Upgrade now</b> in section 1<?php if($nightly && count($manual) < $ns):?>, or let the automatic security run install <?= $manual ? 'the others' : 'them' ?> overnight<?php endif;?>.
<?php if($manual):?><br>🔁 <b><?=count($manual)?> need<?= count($manual) === 1 ? 's' : '' ?> a reboot</b> (<?=Helpers::e(implode(', ', array_slice(array_column($manual, 'name'), 0, 6)))?><?=count($manual) > 6 ? '…' : ''?>) — not installed automatically: upgrade here, then reboot when convenient.<?php endif;?></div>
<?php elseif($np > 0):?><div class="alert ok">ℹ️ <?=$np?> system update<?=$np === 1 ? '' : 's'?> available (none security) — install when convenient in section 1.</div><?php endif;?>
<?php if($age !== null && $age > 172800):?><div class="alert warn">⚠️ Package lists were last refreshed <?=Helpers::e($ageTxt)?> — press <b>Check for updates</b> to see what's new.</div><?php endif;?>
<?php if(!$sy['auto']):?><div class="alert warn">⚠️ Automatic security updates (unattended-upgrades) are <b>off</b> — security fixes only install when you upgrade here.</div><?php endif;?>
<?php endif;?>
<div class="card"><h3>1 — System packages <small style="color:var(--muted)">Ubuntu/Debian updates for the whole server</small></h3>
<?php if(!$sy['ok']):?><p style="color:var(--muted)">Couldn't read the package status just now.</p>
<?php else:?>
<p><?php if($np === 0):?><span class="pill">✅ Up to date</span><?php else:?><span class="pill <?= $ns ? 'err' : 'warn' ?>"><?=$np?> waiting<?= $ns ? " · $ns security" : '' ?></span><?php endif;?>
 <small style="color:var(--muted)">lists refreshed <?=Helpers::e($ageTxt)?> · automatic security updates <?= $sy['auto'] ? 'on (nightly)' : 'off' ?> — <a href="#auto">settings</a></small></p>
<?php if($sy['running'] === 'auto'):?><div class="alert ok">⏳ The automatic security run is installing updates right now — an upgrade started here waits for it to finish.</div><?php endif;?>
<?php endif;?>
<div style="display:flex;gap:8px;flex-wrap:wrap">
<form method="post" action="/admin/update-start"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><button class="btn"<?= $sy['running'] === 'panel' ? ' disabled' : '' ?>>🔍 Check for updates</button></form>
<form method="post" action="/admin/update-start" onsubmit="return confirm('Upgrade <?= $np ? $np.' package(s)' : 'all packages' ?> now? Services (web, mail, database) restart as their packages update; the web server and panel restart last, for a few seconds.')"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><input type="hidden" name="mode" value="go"><button class="btn<?= $ns ? ' danger' : '' ?>"<?= $sy['running'] === 'panel' ? ' disabled' : '' ?>>⬆️ Upgrade now<?= $np ? " ($np)" : '' ?></button></form>
</div>
<p style="color:var(--muted)">Check refreshes the package lists (changes nothing). Upgrade installs what's listed below — no new packages, no removals — and you can watch it live.</p>
<?php if($np):?><details<?= $np <= 15 ? ' open' : '' ?>><summary style="cursor:pointer">Waiting packages (<?=$np?>)</summary>
<table class="list"><tr><th>Package</th><th>Installed</th><th>New</th><th></th></tr>
<?php foreach(array_slice($sy['pkgs'], 0, 200) as $p):?><tr><td><?=Helpers::e($p['name'])?></td><td><small class="clip"><?=Helpers::e($p['from'])?></small></td><td><small class="clip"><?=Helpers::e($p['to'])?></small></td><td><?= $p['kind'] === 'security' ? '<span class="pill err mini">security</span>' : '<span class="pill mini">update</span>' ?><?= !empty($p['reboot']) ? ' <span class="pill mini" title="takes effect after a reboot">needs reboot</span>' : '' ?></td></tr><?php endforeach;?></table></details><?php endif;?>
<?php if(!empty($sy['kept'])):?><p style="color:var(--muted)"><small>Held back by apt (staged rollout or needs new dependencies — installs on a later run): <?=Helpers::e(implode(', ', $sy['kept']))?></small></p><?php endif;?>
<div class="alert err" style="margin-top:8px">⛔ Never run <code>do-release-upgrade</code> on this box. Only Ubuntu 24.04 and Debian 13 are supported (existing 22.04 servers: migrate to a fresh supported server instead) — jumping to a newer release (e.g. Ubuntu 26.04) removes the PHP packages the whole stack runs on and will break the panel, sites and mail. Release upgrades are pinned off at the OS level; OS moves happen by migrating to a new server, never by upgrading in place.</div></div>
<?php if(!$liveTop) echo $liveCard; ?>
<div class="card" id="auto"><h3 style="margin-top:0">🕓 Automatic updates <small style="color:var(--muted)">the OS's own nightly unattended-upgrades — no extra cron</small></h3>
<?php if(!$au['ok']):?><p style="color:var(--muted)">Couldn't read the automatic update settings just now.</p>
<?php elseif(!$au['installed']):?><p>unattended-upgrades isn't installed on this server, so nothing updates automatically. Install it over SSH: <code>apt install unattended-upgrades</code>.</p>
<?php else:
  $hr = $au['hour'];
  $when = $hr === 'os' ? 'daily around 06:00 (OS default, random delay up to 1 h)' : sprintf('daily at %02d:00 (random delay up to 20 min)', $hr);
  $lr = $au['last'];?>
<form method="post" action="/admin/update-auto">
<input type="hidden" name="csrf" value="<?=Helpers::csrf()?>">
<p><label><input type="checkbox" name="security" value="1" id="au-sec"<?= $au['security'] ? ' checked' : '' ?>> <b>Install OS security updates automatically</b></label> <small style="color:var(--muted)">recommended · default on</small><br>
<small style="color:var(--muted)">Ubuntu/Debian security fixes, installed each night by unattended-upgrades. Services restart in place.</small></p>
<p><label><input type="checkbox" name="reboot" value="1"<?= $au['reboot'] ? ' checked' : '' ?>> Include updates that need a reboot</label> <small style="color:var(--muted)">default off</small><br>
<small style="color:var(--muted)">Kernel, firmware, microcode, libc, dbus, AppArmor, PAM. Off = they wait here for you to upgrade and reboot at a time you choose. The server never reboots by itself either way.</small></p>
<p><label><input type="checkbox" name="all" value="1" id="au-all"<?= $au['all'] ? ' checked' : '' ?><?= $au['security'] ? '' : ' disabled' ?>> Also install all other updates</label> <small style="color:var(--muted)">default off</small><br>
<small style="color:var(--muted)">Regular OS updates plus the PHP (ondrej/sury) and OpenLiteSpeed repos — those are <b>not</b> covered by security-only mode. PHP-FPM, MariaDB, mail and the web server restart when their packages update (a few seconds each). Packages that would ask about a changed config file are skipped and stay listed here.</small></p>
<p><label>Run time <select name="hour"><option value="os"<?= $hr === 'os' ? ' selected' : '' ?>>OS default (~06:00)</option>
<?php for($h = 0; $h < 24; $h++):?><option value="<?=$h?>"<?= $hr === $h ? ' selected' : '' ?>><?=sprintf('%02d:00', $h)?></option><?php endfor;?></select></label>
<small style="color:var(--muted)">server time · waits for a running backup to finish · skipped on nights with under 1 GB free disk</small></p>
<button class="btn">💾 Save</button>
</form>
<p style="margin-bottom:0"><small style="color:var(--muted)"><?= $au['security'] ? 'Runs '.Helpers::e($when) : 'Automatic updates are off' ?><?= ($au['security'] && $au['next'] > 0) ? ' · next '.Helpers::e(date('D H:i', $au['next'])) : '' ?>
<?php if($lr):?> · last run <?=Helpers::e(date('D j M H:i', $lr['at']))?>: <?= $lr['result'] === 'failed' ? '<b style="color:var(--err)">failed</b>' : ($lr['result'] === 'installed' ? (int)$lr['count'].' package(s) installed' : 'nothing to install') ?><?php endif;?>
 · results arrive as panel notifications (failures also by email)</small></p>
<script>(function(){const s=document.getElementById('au-sec'),a=document.getElementById('au-all');if(s&&a)s.addEventListener('change',()=>{a.disabled=!s.checked;if(!s.checked)a.checked=false;});})();</script>
<?php endif;?></div>
<script>
(function(){
  const pre = document.getElementById('lv-log'), st = document.getElementById('lv-state'), ti = document.getElementById('lv-title');
  const prog = document.getElementById('lv-prog'), bar = document.getElementById('lv-bar'), ph = document.getElementById('lv-phase');
  let off = 'last', buf = '', first = true, wasRunning = <?= $liveOpen ? 'true' : 'false' ?>, fails = 0, plan = 0, ran = <?= !empty($_GET['ran']) ? 'true' : 'false' ?>;
  function paint(){
    const lines = buf.split('\n').slice(-1500);
    pre.textContent = '';
    for (const l of lines) {
      const d = document.createElement('div'); d.textContent = l || ' ';
      if (/^!!|FAILED|^E: /.test(l)) d.style.color = 'var(--err)';
      else if (/^=== |^PLAN |^--- |REBOOT REQUIRED/.test(l)) d.style.fontWeight = '600';
      pre.appendChild(d);
    }
    const near = pre.scrollHeight - pre.scrollTop - pre.clientHeight < 80;
    if (near || first) pre.scrollTop = pre.scrollHeight;
    const m = buf.match(/^PLAN (\d+) packages/m); plan = m ? +m[1] : 0;
    const done = (buf.match(/^Setting up /gm) || []).length;
    let phase = 'Refreshing package lists…', pct = 5;
    if (/^Get:\d+ .*\.deb|^Get:\d+ http\S+ \S+ \S+ \S+ [0-9]/m.test(buf) && plan) { phase = 'Downloading packages…'; pct = 15; }
    if (/^Unpacking /m.test(buf)) { phase = 'Installing…'; pct = 25; }
    if (plan && done) { pct = Math.min(98, 25 + Math.round(done / plan * 73)); phase = 'Installing… ' + Math.min(done, plan) + ' of ' + plan + ' set up'; }
    if (/^--- openlitespeed/m.test(buf)) phase = 'Web server upgrading — the panel may drop for a few seconds…';
    if (/^=== .* done ===\s*$/m.test(buf)) { phase = 'Finished'; pct = 100; }
    bar.style.width = pct + '%';
    ph.textContent = phase;
  }
  function tick(){
    fetch('/admin/update-log?off=' + off, {credentials: 'same-origin', cache: 'no-store'})
      .then(r => { if (!r.ok) throw 0; return r.json(); })
      .then(j => {
        fails = 0;
        if (first) buf = '';
        buf += j.text || ''; off = j.off;
        const running = j.state === 'running';
        if (running) wasRunning = true;
        if (first && !buf.trim()) buf = 'No update has been run from the panel yet.';
        prog.style.display = (running || wasRunning) ? '' : 'none';
        // coloured only while a run is in progress; idle/finished stay plain
        st.textContent = running ? 'running' : ((wasRunning || ran) ? (/!!|FAILED/.test(buf) ? 'finished with errors' : 'finished') : 'idle');
        st.className = 'pill mini' + (running ? ' warn' : '');
        ti.textContent = running ? 'Update running' : 'Last update run';
        paint(); first = false;
        if (running) setTimeout(tick, 1500);
        else if (wasRunning) { ph.textContent = 'Finished — refreshing the package list…'; setTimeout(() => { location.href = '/admin/update?ran=1'; }, 2500); }
      })
      .catch(() => {
        // the web server restarts during its own upgrade: keep trying
        fails++;
        st.textContent = 'reconnecting…'; st.className = 'pill mini warn';
        if (fails < 120) setTimeout(tick, Math.min(5000, 1000 * fails));
      });
  }
  tick();
})();
</script>
<div class="card"><h3>2 — KarnakPanel itself <small style="color:var(--muted)">current: <?=Helpers::e($cur)?></small></h3>
<p style="color:var(--muted)">Manual updates only — the panel never updates itself. Fetch a release, then install it when ready. Every install snapshots code+DB, migrates, swaps atomically, health-checks, and rolls back on failure. Progress shows live at the top of this page.</p>
<form method="post" action="/admin/panel-fetch" style="margin-bottom:8px"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><button class="btn small">⬇ Fetch latest release from karnakpanel.com</button></form>
<?php if(!empty($staged)):?><form method="post" action="/admin/panel-install-staged" onsubmit="return confirm('Install <?=Helpers::e($staged)?> now? Snapshot + atomic swap + auto-rollback on failure.')"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><button class="btn">⬆️ Install <?=Helpers::e($staged)?></button></form>
<p style="color:var(--muted)"><small>After installing, the new version appears within ~2 minutes — PHP caches the old release briefly. Not a failed update; just wait and refresh.</small></p><?php endif;?>
<details style="margin-top:8px"><summary style="cursor:pointer;color:var(--muted)">Or upload a release file manually</summary>
<form method="post" action="/admin/panel-update" enctype="multipart/form-data" style="margin-top:8px">
<input type="hidden" name="csrf" value="<?=Helpers::csrf()?>">
<input type="file" name="pkg" accept=".tar.gz" required> <button class="btn">Upload &amp; install release</button></form></details>
<?php if(!empty($rels)):?><table><tr><th>Kept release</th><th></th></tr>
<?php foreach($rels as $rel):?><tr><td><code><?=Helpers::e($rel)?></code><?php if($rel===$cur):?> <span class="pill">live</span><?php endif;?></td>
<td><?php if($rel!==$cur):?><form method="post" action="/admin/panel-rollback" style="display:inline" onsubmit="return confirm('Roll back panel+scripts+DB to <?=Helpers::e($rel)?>?')"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><input type="hidden" name="release" value="<?=Helpers::e($rel)?>"><button class="btn small">Rollback here</button></form><?php endif;?></td></tr><?php endforeach;?></table><?php endif;?>
<?php if(!$pTop):?><details id="pl-fold" style="margin-top:10px"><summary style="cursor:pointer;color:var(--muted)">Last panel update log</summary><div style="margin-top:8px"><?= $panelCard ?></div></details><?php endif;?></div>
<div class="card"><h3>🛡️ File integrity <small style="color:var(--muted)">tripwire-lite over panel code, scripts and control configs — checked daily, alerts on unexpected change</small></h3>
<p>Status: <?php if(($integ ?? '') === 'ok'):?><span class="pill">OK</span><?php else:?><b style="color:var(--danger,red)"><?=Helpers::e(substr((string)($integ ?? 'unknown'),0,200))?></b><?php endif;?></p>
<form method="post" action="/admin/integrity-baseline" onsubmit="return confirm('Record current files as the clean baseline? Only do this right after intentional changes you made yourself.')"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><button class="btn small">Re-baseline now</button></form>
<p style="color:var(--muted)">Updates and SSL/mail scripts re-baseline themselves — anything else changing means a hand edit or worse.</p></div>
<div class="card"><h3>3 — Reboot <small style="color:var(--muted)">whole server — panel, sites, mail and FTP drop for ~1-2 min</small></h3><form method="post" action="/admin/reboot" onsubmit="return confirm('REBOOT the whole server now? Everything goes offline for a minute or two. Only needed after kernel updates (see warning above) — otherwise leave it alone.')"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><button class="btn danger">🔴 Reboot now</button></form>
<p style="color:var(--muted)">Scheduled +1 minute so this page answers first. Cancel from SSH with <code>shutdown -c</code>.</p></div>
<script>
(function(){
  const pre = document.getElementById('pl-log'); if (!pre) return;
  const st = document.getElementById('pl-state'), ti = document.getElementById('pl-title'), fold = document.getElementById('pl-fold');
  const prog = document.getElementById('pl-prog'), bar = document.getElementById('pl-bar'), ph = document.getElementById('pl-phase');
  let off = 'last', buf = '', first = true, fails = 0;
  let wasRunning = <?= !empty($_GET['plive']) ? 'true' : 'false' ?>;
  const ran = <?= !empty($_GET['pran']) ? 'true' : 'false' ?>;
  const bad = /REFUSED|ABORTED|FAILED|rolled back to|WARN: rolled back/;
  // milestones panel-update.sh / panel-rollback.sh print, in order
  const steps = [
    [/^=== .* panel update from/m, 'Checking the release signature…', 5],
    [/^signature: VALID/m, 'Saving a database snapshot…', 15],
    [/^db snapshot:/m, 'Unpacking and verifying every file…', 30],
    [/^file hashes: OK/m, 'Syntax check, carrying settings across…', 45],
    [/^carried storage\/|^migrated:/m, 'Database migrations…', 58],
    [/^switching to /m, 'Switching release, restarting the web server — the panel drops for a few seconds…', 70],
    [/^health check: waiting/m, 'Waiting for the panel to come back…', 78],
    [/^health check: OK/m, 'Panel is back — tidying up old releases…', 86],
    [/^finishing:/m, 'Finishing: permissions, cron table, integrity baseline…', 92],
    [/^=== .* rollback requested/m, 'Rolling back…', 10],
    [/^db restored from/m, 'Database restored, restarting the web server…', 55],
    [/^=== .*(update to \S+|rollback to \S+) COMPLETE ===/m, 'Finished', 100],
  ];
  function paint(running){
    const lines = buf.split('\n').slice(-1500);
    pre.textContent = '';
    for (const l of lines) {
      const d = document.createElement('div'); d.textContent = l || ' ';
      if (bad.test(l)) d.style.color = 'var(--err)';
      else if (/^=== /.test(l)) d.style.fontWeight = '600';
      pre.appendChild(d);
    }
    if (first || pre.scrollHeight - pre.scrollTop - pre.clientHeight < 80) pre.scrollTop = pre.scrollHeight;
    let phase = 'Starting…', pct = 2;
    for (const [re, t, p] of steps) if (re.test(buf)) { phase = t; pct = p; }
    if (bad.test(buf) && pct < 100) phase = running ? 'Problem found — rolling back…' : 'Did not complete — see the log';
    bar.style.width = pct + '%'; ph.textContent = phase;
  }
  function tick(){
    fetch('/admin/panel-update-log?off=' + off, {credentials: 'same-origin', cache: 'no-store'})
      .then(r => { if (!r.ok) throw 0; return r.json(); })
      .then(j => {
        fails = 0;
        if (first) buf = '';
        buf += j.text || ''; off = j.off;
        const running = j.state === 'running';
        if (running) { wasRunning = true; if (fold) fold.open = true; }
        if (first && !buf.trim()) buf = 'No panel update has been run on this server yet.';
        prog.style.display = (running || wasRunning) ? '' : 'none';
        const failed = bad.test(buf) && !/COMPLETE ===/.test(buf.split('\n').filter(Boolean).pop() || '');
        st.textContent = running ? 'running' : ((wasRunning || ran) ? (failed ? 'did not complete' : 'finished') : '');
        st.className = 'pill mini' + (running ? ' warn' : (failed && (wasRunning || ran) ? ' err' : ''));
        ti.textContent = running ? 'Panel update running' : 'Last panel update';
        paint(running); first = false;
        if (running) setTimeout(tick, 1500);
        // finished: reload so the version, kept releases and staged file are current
        else if (wasRunning && !ran) { ph.textContent += ' — reloading…'; setTimeout(() => { location.href = '/admin/update?pran=1'; }, 3000); }
      })
      .catch(() => {
        // the web server restarts mid-update: keep trying
        fails++;
        st.textContent = 'reconnecting…'; st.className = 'pill mini warn';
        if (fails < 120) setTimeout(tick, Math.min(5000, 1000 * fails));
      });
  }
  tick();
})();
</script>

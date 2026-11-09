<?php $csrf = Helpers::csrf(); ?>
<h2>🚚 Migrate <small style="color:var(--muted)">bring accounts in from another server — admin</small></h2>

<div class="card mgprog" id="migprog" hidden>
<div class="mghead"><h3 id="migTitle">⏳ Import in progress</h3><span class="pill" id="migState">running</span></div>
<div class="bar mgbar"><i id="migBar" style="width:0%"></i></div>
<div class="mgline"><b id="migStage">queued</b><span id="migPct"></span><span class="muted" id="migAge"></span></div>
<small class="muted" id="migDetail"></small>
<small class="muted mgfoot">You can leave this page — the import keeps running. Full report: Logs → software.</small>
</div>

<nav class="tabs etabs" hidden>
<a class="tab" href="#karnak" data-etab="karnak">KarnakPanel server</a>
<a class="tab" href="#cpanel" data-etab="cpanel">cPanel</a>
<a class="tab" href="#hestia" data-etab="hestia">HestiaCP</a>
</nav>

<section class="etab" id="etab-karnak">
<?php $pairings = $pairings ?? []; $tgt = array_values(array_filter($pairings, fn($p) => $p['role'] === 'target')); $src = array_values(array_filter($pairings, fn($p) => $p['role'] === 'source')); ?>
<div class="card"><h3>📦 Account Transfer <small class="muted">from another KarnakPanel server</small></h3>
<p class="mgintro">Moves hosting accounts with everything they own: sites, files, mail, databases, DNS zones, FTP and cron, with every password kept. The old server's copy is never changed while it copies, and stays there until you delete it.</p>
<div class="ktkeys">
  <div class="ktbox"><b>NEW server</b><small>accounts move here</small><span class="ktkey priv">🔒 private key<br><small>made here, never leaves, deleted when you revoke</small></span></div>
  <div class="ktarrow"><span>code 1 →</span><small>public half only</small><span>← code 2</span><small>old server's fingerprint</small></div>
  <div class="ktbox"><b>OLD server</b><small>accounts are here now</small><span class="ktkey pub">🔑 public key<br><small>read-only login, only from the new server's IP, expires on its own</small></span></div>
</div>
<ul class="mgkept">
<li><b>You never copy a private key.</b> Both codes contain no secrets: paste them on screen, in chat, anywhere. If anything asks you for a private key or a root password, stop.</li>
<li>Servers find each other by <b>IP address</b>, so it works while domains are still pointing elsewhere or propagating.</li>
<li>Both screens show the same three <b>check words</b> once paired. Different words mean a different server answered: revoke and start again.</li>
<li>Copies run over SSH (encrypted), pulled by the new server. Both servers must run the same panel version.</li>
</ul></div>

<div class="card" id="ktwho"><h3>Which server is this?</h3>
<div class="ktroles">
<button type="button" class="btn ktrole" data-role="new">🆕 The NEW server<small>accounts will move here</small></button>
<button type="button" class="btn ktrole ghost" data-role="old">📤 The OLD server<small>the accounts are here now</small></button>
</div>

<div class="ktflow" id="ktnew" hidden>
<ol class="ktsteps">
<li><b>Make code 1 here.</b>
  <div class="ktrow"><label class="ktinline">Valid for <select id="kthours"><option value="6">6 hours</option><option value="24" selected>24 hours</option><option value="72">72 hours</option></select></label>
  <button type="button" class="btn" id="ktmake">Make pairing code</button></div>
  <div id="ktcode1" hidden><textarea class="ktcode" readonly rows="3"></textarea>
  <div class="ktrow"><button type="button" class="btn ghost ktcopy">Copy</button><small class="muted">On the OLD server: Migrate → KarnakPanel server → <b>The OLD server</b>, paste it there. Expires <span class="ktexp"></span>.</small></div></div></li>
<li><b>Paste code 2 from the OLD server.</b>
  <textarea class="ktcode" id="ktcode2" rows="3" placeholder="KT1-SOURCE-…" autocomplete="off" spellcheck="false"></textarea>
  <div class="ktrow"><button type="button" class="btn" id="ktlink" disabled>Connect</button><small class="muted">Checks the OLD server's fingerprint and logs in once to test.</small></div></li>
<li><b>Compare the check words</b> with the OLD server's screen.<div id="ktlinked" hidden></div></li>
</ol></div>

<div class="ktflow" id="ktold" hidden>
<ol class="ktsteps">
<li><b>Paste code 1 from the NEW server.</b>
  <textarea class="ktcode" id="ktcodeT" rows="3" placeholder="KT1-TARGET-…" autocomplete="off" spellcheck="false"></textarea>
  <div class="ktrow"><button type="button" class="btn" id="ktallow">Allow this server to be copied</button><small class="muted">Adds a read-only login for that one server's IP and key, until the code expires.</small></div></li>
<li><b>Copy code 2 back to the NEW server.</b><div id="ktcodeS" hidden><textarea class="ktcode" readonly rows="3"></textarea>
  <div class="ktrow"><button type="button" class="btn ghost ktcopy">Copy</button></div>
  <div class="ktwords"><small>Check words: the NEW server must show the same</small><b></b></div>
  <small class="muted ktallowed"></small></div></li>
</ol></div>
<p class="ktmsg" id="ktmsg" hidden></p>
</div>

<div class="card"><h3>🔗 Pairings on this server</h3>
<?php if (!$pairings): ?><p class="muted">None. A pairing ends by itself when its code expires, or when you revoke it.</p>
<?php else: ?>
<div class="tablewrap"><table class="kttable"><thead><tr><th>This server is</th><th>Other server</th><th>Check words</th><th>State</th><th>Ends</th><th></th></tr></thead><tbody>
<?php foreach (array_merge($tgt, $src) as $p): $isT = $p['role'] === 'target'; ?>
<tr><td><?= $isT ? '🆕 NEW (copies to here)' : '📤 OLD (copied from here)' ?></td>
<td><code><?= Helpers::e($p['ip'] ?: '…') ?></code></td>
<td><b><?= Helpers::e($p['words'] ?: '—') ?></b></td>
<td><span class="pill <?= $p['state'] === 'linked' || $p['state'] === 'active' ? 'ok' : ($p['state'] === 'expired' ? 'err' : '') ?>"><?= Helpers::e($p['state'] === 'waiting' ? 'waiting for code 2' : $p['state']) ?></span></td>
<td><small><?= Helpers::e($p['exp']) ?></small></td>
<td class="ktacts"><?php if ($isT && $p['state'] === 'linked'): ?><button type="button" class="btn ktpick" data-id="<?= Helpers::e($p['id']) ?>" data-ip="<?= Helpers::e($p['ip']) ?>">Choose accounts</button><?php endif; ?>
<?php if ($isT && $p['state'] === 'waiting'): ?><button type="button" class="btn ghost ktresume" data-id="<?= Helpers::e($p['id']) ?>">Paste code 2</button><?php endif; ?>
<button type="button" class="btn ghost danger ktrevoke" data-id="<?= Helpers::e($p['id']) ?>">Revoke</button></td></tr>
<?php endforeach; ?>
</tbody></table></div>
<small class="muted">Revoke on the NEW server also closes the OLD server's side; on the OLD server it shuts the login at once. Neither touches accounts that already moved.</small>
<?php endif; ?>
</div>

<div class="card" id="ktpicker" hidden><h3>☑️ Choose accounts <small class="muted" id="ktpfrom"></small></h3>
<div id="ktplist"><p class="muted">Asking the OLD server…</p></div>
<div class="ktrow"><button type="button" class="btn" id="ktstart" disabled>Start transfer</button><small class="muted" id="ktsel"></small></div>
<small class="muted">One account at a time, checks first. The OLD copy stays live and unchanged until you cut over. Moved cron jobs and app daemons wait here, paused, so nothing runs twice. Cut over (per account, once copied) holds the old copy, copies what changed, passes its mail on, starts the jobs here, points DNS here and reissues SSL.</small>
</div>

<div class="card" id="ktjobs" hidden><h3>🚚 Transfers</h3><div id="ktjoblist"></div>
<small class="muted">You can leave this page: transfers keep running. Full log: Logs → account transfers.</small></div>

<div class="card"><h3>🖥️ Whole-server move <small class="muted">SSH, every account at once</small></h3>
<p class="muted">As root on the old server run <code>panel-transfer.sh</code>, then on the new one <code>panel-transfer-import.sh &lt;bundle&gt;</code>, copy <code>/home</code> and <code>/var/mail/virtual</code> with rsync, and finish with <code>panel-transfer-import.sh --finish</code>. Both servers must run the same panel version. Database and FTP passwords are regenerated (saved root-only on the new server). For a few accounts, or to keep passwords, use Account Transfer above.</p></div>
</section>

<section class="etab" id="etab-cpanel">
<div class="card mgpick"><h3>Which cPanel method?</h3>
<table class="mgtable"><thead><tr><th>Method</th><th>You need</th><th>Best for</th></tr></thead><tbody>
<tr><td><a href="#mg-cp-root">Root SSH</a></td><td>root password on the cPanel server</td><td>Your own server: fastest, no FTP</td></tr>
<tr><td><a href="#mg-cp-remote">Account login</a></td><td>the site's cPanel login + an API token</td><td>Shared hosting where you have no root</td></tr>
<tr><td><a href="#mg-cp-upload">Upload a backup</a></td><td>a cPanel full-backup file (.tar.gz)</td><td>Backups you already downloaded</td></tr>
</tbody></table>
<small class="muted">All three need the panel user and domain created here first (Accounts, then Domains). They bring files, databases and mailboxes; DNS, subdomains and cron stay manual. WordPress database passwords are recovered from wp-config; other apps get new ones (listed in Logs → software).</small></div>

<div class="card" id="mg-cp-root"><h3>🔑 Root SSH <small class="muted">fastest, for server owners</small></h3>
<p class="mgintro">We run <code>pkgacct</code> on the cPanel server, copy the archive here, delete the remote copy and import it. A WHM API token alone can't move accounts, so root SSH is needed.</p>
<form class="mgform" method="post" action="/accounts/import-root">
<input type="hidden" name="csrf" value="<?=$csrf?>">
<label>cPanel server<input name="host" placeholder="host name or IP" required autocomplete="off"></label>
<label>Root user<input name="rootuser" value="root" autocomplete="off"></label>
<label>Root password<input name="rootpass" type="password" required autocomplete="new-password"></label>
<label>cPanel user<input name="cpuser" required autocomplete="off"></label>
<label>Panel user here<input name="username" required></label>
<label>Domain here<input name="domain" required></label>
<div class="mgsubmit"><button class="btn">Fetch &amp; import</button><small class="muted">The password is used once for SSH and never stored.</small></div>
</form></div>

<div class="card" id="mg-cp-remote"><h3>🌐 Account login <small class="muted">no root needed</small></h3>
<p class="mgintro">On the cPanel account: Security → Manage API Tokens → create one. The token starts the backup and the password pulls it over FTP; we then delete the remote copy and import it. Big accounts take a while.</p>
<form class="mgform" method="post" action="/accounts/import-remote">
<input type="hidden" name="csrf" value="<?=$csrf?>">
<label>cPanel server<input name="host" placeholder="host name or IP" required autocomplete="off"></label>
<label>cPanel user<input name="cpuser" required autocomplete="off"></label>
<label>cPanel password<input name="cppass" type="password" required autocomplete="new-password"></label>
<label>API token<input name="cptoken" autocomplete="off"></label>
<label>Panel user here<input name="username" required></label>
<label>Domain here<input name="domain" required></label>
<div class="mgsubmit"><button class="btn">Fetch &amp; import</button><small class="muted">Both secrets are used once and never stored. The source must allow UAPI and outbound FTP.</small></div>
</form></div>

<div class="card" id="mg-cp-upload"><h3>📥 Upload a backup</h3>
<form class="mgform" method="post" action="/accounts/import" enctype="multipart/form-data">
<input type="hidden" name="csrf" value="<?=$csrf?>">
<label class="wide">cPanel full backup (.tar.gz)<input type="file" name="pkg" accept=".tar.gz" required></label>
<label>cPanel user<input name="cpuser" required></label>
<label>Panel user here<input name="username" required></label>
<label>Domain here<input name="domain" required></label>
<div class="mgsubmit"><button class="btn">Import backup</button></div>
</form></div>
</section>

<section class="etab" id="etab-hestia">
<div class="card"><h3>🟦 HestiaCP <small class="muted">everything one Hestia user owns</small></h3>
<p class="mgintro">Brings sites, subdomains, databases, mailboxes, DNS records and cron. Create the <b>panel user</b> here first (Accounts); domains are created for you and subdomains land as records in their parent zone.</p>
<ul class="mgkept">
<li>Kept as-is: database names, users and passwords, mailbox passwords, aliases, catch-alls.</li>
<li>Cron jobs arrive <b>paused</b> for you to review. SSL is re-issued once DNS points here.</li>
<li>Sites that already have files in their folder here are not overwritten.</li>
</ul></div>

<div class="card"><h3>🔑 Root SSH</h3>
<p class="mgintro">We run <code>v-backup-user</code> on the Hestia server, copy it here and delete the remote copy. Hestia keeps a fixed number of backups per user, so making a new one deletes that user's <b>oldest</b> backup if the limit is reached.</p>
<form class="mgform" method="post" action="/accounts/import-hestia">
<input type="hidden" name="csrf" value="<?=$csrf?>">
<label>Hestia server<input name="host" placeholder="host name or IP" required autocomplete="off"></label>
<label>SSH port<input name="port" value="22" inputmode="numeric" autocomplete="off"></label>
<label>Root user<input name="rootuser" value="root" autocomplete="off"></label>
<label>Root password<input name="rootpass" type="password" required autocomplete="new-password"></label>
<label>Hestia user<input name="huser" required autocomplete="off"></label>
<label>Panel user here<input name="username" required></label>
<div class="mgsubmit"><button class="btn">Fetch &amp; import</button><small class="muted">The password is used once for SSH and never stored.</small></div>
</form></div>

<div class="card"><h3>📥 Upload a backup <small class="muted">Hestia → Backups → download</small></h3>
<form class="mgform" method="post" action="/accounts/import-hestia-upload" enctype="multipart/form-data">
<input type="hidden" name="csrf" value="<?=$csrf?>">
<label class="wide">Hestia backup (.tar)<input type="file" name="pkg" accept=".tar" required></label>
<label>Hestia user<input name="huser" required autocomplete="off"></label>
<label>Panel user here<input name="username" required></label>
<div class="mgsubmit"><button class="btn">Import backup</button></div>
</form></div>
</section>

<script>
(function(){
var m = new URLSearchParams(location.search).get('job');
if (!m || !/^[a-f0-9]{16}$/.test(m)) return;
var $ = function(id){ return document.getElementById(id); };
$('migprog').hidden = false;
var last = null, lastAt = Date.now();
function age(){
  var s = Math.round((Date.now() - lastAt) / 1000);
  $('migAge').textContent = s < 5 ? 'updated just now' : 'updated ' + (s < 90 ? s + ' s' : Math.round(s / 60) + ' min') + ' ago';
}
setInterval(age, 1000);
async function poll(){
  try {
    var r = await fetch('/accounts/import-status?job=' + m, {credentials:'same-origin'});
    if (!r.ok) { setTimeout(poll, 10000); return; }
    var d = await r.json();
    var sig = d.stage + '|' + d.pct + '|' + d.detail;
    if (sig !== last) { last = sig; lastAt = Date.now(); }
    var done = d.stage === 'done', failed = d.stage === 'failed';
    $('migStage').textContent = d.stage || 'queued';
    $('migPct').textContent = (d.pct != null && !failed) ? d.pct + '%' : '';
    $('migDetail').textContent = d.detail || '';
    $('migBar').style.width = (done ? 100 : Math.min(100, d.pct || 0)) + '%';
    $('migBar').className = failed ? 'hot' : '';
    $('migState').textContent = done ? 'finished' : failed ? 'failed' : 'running';
    $('migState').className = 'pill' + (done ? ' ok' : failed ? ' err' : '');
    $('migTitle').textContent = done ? '✅ Import finished' : failed ? '❌ Import failed' : '⏳ Import in progress';
    age();
    if (!done && !failed) setTimeout(poll, 3000);
  } catch(e){ setTimeout(poll, 10000); }
}
poll();
})();
</script>
<script>
(function(){
var CSRF = <?= json_encode($csrf, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
var $ = function(id){ return document.getElementById(id); };
function el(t, cls, txt){ var e = document.createElement(t); if (cls) e.className = cls; if (txt != null) e.textContent = txt; return e; }
function msg(t, bad){ var m = $('ktmsg'); m.hidden = !t; m.textContent = t || ''; m.className = 'ktmsg ' + (bad ? 'bad' : 'good'); }
async function call(url, fields){
  var fd = new FormData(); fd.append('csrf', CSRF);
  Object.keys(fields || {}).forEach(function(k){ [].concat(fields[k]).forEach(function(v){ fd.append(Array.isArray(fields[k]) ? k + '[]' : k, v); }); });
  var r, d;
  try { r = await fetch(url, {method:'POST', body:fd, credentials:'same-origin'}); d = await r.json(); }
  catch(e){ throw new Error('No answer from this panel (network or timeout): try again'); }
  if (!r.ok || d.error) throw new Error(d.error || ('error ' + r.status));
  return d;
}
function busy(b, on, label){ if (on){ b.dataset.l = b.textContent; b.textContent = label || 'Working…'; b.disabled = true; } else { b.textContent = b.dataset.l || b.textContent; b.disabled = false; } }
document.querySelectorAll('.ktcopy').forEach(function(b){ b.addEventListener('click', function(){
  var ta = b.closest('div[id]').querySelector('textarea'); ta.select();
  (navigator.clipboard ? navigator.clipboard.writeText(ta.value) : Promise.reject()).then(function(){ b.textContent = 'Copied ✓'; }, function(){ document.execCommand('copy'); b.textContent = 'Copied ✓'; });
  setTimeout(function(){ b.textContent = 'Copy'; }, 2500);
}); });

// which server is this?
var pairId = null;
function role(r){
  document.querySelectorAll('.ktrole').forEach(function(b){ b.classList.toggle('ghost', b.dataset.role !== r); });
  $('ktnew').hidden = r !== 'new'; $('ktold').hidden = r !== 'old'; msg('');
}
document.querySelectorAll('.ktrole').forEach(function(b){ b.addEventListener('click', function(){ role(b.dataset.role); }); });

// NEW server
$('ktmake').addEventListener('click', async function(){
  var b = this; busy(b, true, 'Making…'); msg('');
  try {
    var d = await call('/admin/transfer/pair-new', {hours: $('kthours').value});
    pairId = d.id; var c = $('ktcode1'); c.hidden = false; c.querySelector('textarea').value = d.code; c.querySelector('.ktexp').textContent = d.exp;
    $('ktlink').disabled = false; b.textContent = 'Make a new code'; b.disabled = false; b.classList.add('ghost');
  } catch(e){ busy(b, false); msg(e.message, true); }
});
document.querySelectorAll('.ktresume').forEach(function(b){ b.addEventListener('click', function(){
  pairId = b.dataset.id; role('new'); $('ktlink').disabled = false; $('ktcode2').focus(); $('ktwho').scrollIntoView({behavior:'smooth'});
}); });
$('ktlink').addEventListener('click', async function(){
  var b = this; if (!pairId) return; busy(b, true, 'Connecting…'); msg('');
  try {
    var d = await call('/admin/transfer/link', {id: pairId, code: $('ktcode2').value});
    var box = $('ktlinked'); box.hidden = false; box.textContent = '';
    var w = el('div', 'ktwords'); w.appendChild(el('small', null, 'Check words: the OLD server shows these too')); w.appendChild(el('b', null, d.words)); box.appendChild(w);
    box.appendChild(el('p', 'muted', 'Connected to ' + d.source + ' (it sees this server as ' + d.seen_as + '). Panel versions: old ' + d.src_ver + ', here ' + d.ver + '.'));
    if (d.warn) box.appendChild(el('p', 'ktmsg bad', d.warn));
    var go = el('button', 'btn', 'Words match: choose accounts'); go.type = 'button';
    go.addEventListener('click', function(){ picker(pairId, d.source.split(':')[0]); });
    var no = el('button', 'btn ghost danger', 'They differ: revoke'); no.type = 'button';
    no.addEventListener('click', function(){ revoke(pairId, no); });
    var row = el('div', 'ktrow'); row.appendChild(go); row.appendChild(no); box.appendChild(row);
    b.textContent = 'Connected ✓';
  } catch(e){ busy(b, false); msg(e.message, true); }
});

// OLD server
$('ktallow').addEventListener('click', async function(){
  var b = this; busy(b, true, 'Allowing…'); msg('');
  try {
    var d = await call('/admin/transfer/allow', {code: $('ktcodeT').value});
    var c = $('ktcodeS'); c.hidden = false; c.querySelector('textarea').value = d.code;
    c.querySelector('.ktwords b').textContent = d.words;
    c.querySelector('.ktallowed').textContent = 'Allowed: read-only copying by ' + d.target + ' only, until ' + d.exp + '. Revoke below to end it sooner.';
    b.textContent = 'Allowed ✓';
  } catch(e){ busy(b, false); msg(e.message, true); }
});

// pairings table
async function revoke(id, b){
  if (!confirm('End this pairing? Transfers already finished are not affected.')) return;
  busy(b, true, 'Revoking…');
  try { await call('/admin/transfer/revoke', {id: id}); location.reload(); }
  catch(e){ busy(b, false); alert(e.message); }
}
document.querySelectorAll('.ktrevoke').forEach(function(b){ b.addEventListener('click', function(){ revoke(b.dataset.id, b); }); });
document.querySelectorAll('.ktpick').forEach(function(b){ b.addEventListener('click', function(){ picker(b.dataset.id, b.dataset.ip); }); });

// account picker
var pickPair = null;
function mb(n){ return n == null ? '?' : n >= 1024 ? (n / 1024).toFixed(1) + ' GB' : n + ' MB'; }
async function picker(id, ip){
  pickPair = id; var P = $('ktpicker'); P.hidden = false; $('ktpfrom').textContent = 'on ' + ip;
  var L = $('ktplist'); L.textContent = ''; L.appendChild(el('p', 'muted', 'Asking the OLD server…'));
  $('ktstart').disabled = true; P.scrollIntoView({behavior:'smooth'});
  var d;
  try { d = await (await fetch('/admin/transfer/accounts?pairing=' + id, {credentials:'same-origin'})).json(); }
  catch(e){ d = {error: 'No answer from this panel: try again'}; }
  L.textContent = '';
  if (d.error){ L.appendChild(el('p', 'ktmsg bad', d.error)); return; }
  if (!d.accounts.length){ L.appendChild(el('p', 'muted', 'The OLD server has no hosting accounts to move.')); return; }
  var w = el('div', 'tablewrap'), t = el('table', 'mgtable ktacc'), h = el('tr');
  var all = el('input'); all.type = 'checkbox'; all.title = 'all';
  var th = el('th'); th.appendChild(all); h.appendChild(th);
  ['Account', 'Domains', 'Size', 'Mail · DBs · FTP · cron', 'PHP', ''].forEach(function(x){ h.appendChild(el('th', null, x)); });
  var hd = el('thead'); hd.appendChild(h); t.appendChild(hd); var tb = el('tbody');
  d.accounts.forEach(function(a){
    var r = el('tr'), c = el('input'); c.type = 'checkbox'; c.value = a.username; c.className = 'ktu';
    var td = el('td'); td.appendChild(c); r.appendChild(td);
    var u = el('td'); u.appendChild(el('b', null, a.username)); if (a.package) u.appendChild(el('small', 'muted ktsub', a.package)); r.appendChild(u);
    r.appendChild(el('td', 'ktdoms', (a.domains || []).join(', ') || '—'));
    r.appendChild(el('td', null, mb(a.disk_mb)));
    r.appendChild(el('td', null, [a.mailboxes, a.dbs, a.ftp, a.cron].join(' · ')));
    r.appendChild(el('td', null, (a.php || []).join(', ')));
    var note = el('td');
    if (a.status !== 'active') note.appendChild(el('span', 'pill warn mini', a.status));
    if (a.clash && a.clash.length){ c.disabled = true; note.appendChild(el('span', 'pill err mini', 'already here')); note.title = a.clash.join('\n'); note.appendChild(el('small', 'ktsub ktbad', a.clash.join(' · '))); }
    r.appendChild(note); tb.appendChild(r);
  });
  t.appendChild(tb); w.appendChild(t); L.appendChild(w);
  function count(){ var n = L.querySelectorAll('.ktu:checked').length; $('ktstart').disabled = !n; $('ktsel').textContent = n ? n + ' selected' : ''; }
  L.addEventListener('change', count);
  all.addEventListener('change', function(){ L.querySelectorAll('.ktu:not(:disabled)').forEach(function(c){ c.checked = all.checked; }); count(); });
}
$('ktstart').addEventListener('click', async function(){
  var b = this, us = [].map.call($('ktplist').querySelectorAll('.ktu:checked'), function(c){ return c.value; });
  if (!us.length || !pickPair) return;
  busy(b, true, 'Starting…');
  try { await call('/admin/transfer/start', {pairing: pickPair, users: us}); $('ktpicker').hidden = true; busy(b, false); poll(true); $('ktjobs').scrollIntoView({behavior:'smooth'}); }
  catch(e){ busy(b, false); alert(e.message); }
});

// live bars
var STAGES = ['checks', 'account', 'sites', 'files', 'databases', 'mail', 'settings', 'verify', 'ready'];
var CUTS = ['cut-checks', 'cut-hold', 'cut-sync', 'cut-mail', 'cut-start', 'cut-dns', 'live'];
var CUTNAME = {'cut-queued': 'cutover queued', 'cut-checks': 'checks', 'cut-hold': 'hold old', 'cut-sync': 'last copy', 'cut-mail': 'mail on', 'cut-start': 'cron + daemons', 'cut-dns': 'DNS', 'live': 'live here'};
function cutting(st){ return st.indexOf('cut-') === 0; }
function size(b){ var u = ['B', 'KB', 'MB', 'GB', 'TB'], i = 0; while (b >= 1024 && i < 4){ b /= 1024; i++; } return (i ? b.toFixed(1) : b) + ' ' + u[i]; }
function dur(s){ s = Math.round(s); return s < 90 ? s + ' s' : s < 5400 ? Math.round(s / 60) + ' min' : (s / 3600).toFixed(1) + ' h'; }
function item(it, jobLive){
  var cut = cutting(it.stage), final = it.stage === 'ready' || it.stage === 'failed' || it.stage === 'live', live = !final && it.stage !== 'queued';
  var stalled = live && (jobLive || cut) && it.idle > (it.stage === 'cut-queued' ? 900 : 180);
  var box = el('div', 'ktitem' + (it.stage === 'failed' ? ' bad' : final && it.here ? ' good' : ''));
  var head = el('div', 'kthead'); head.appendChild(el('b', null, it.username));
  var gone = (it.stage === 'ready' || it.stage === 'live') && !it.here;
  var st = gone ? ['', 'no longer here'] : it.stage === 'live' ? ['ok', '🟢 live here'] : it.stage === 'ready' ? ['ok', '✅ ready here'] : it.stage === 'failed' ? ['err', '❌ failed'] : it.stage === 'queued' ? ['', 'queued'] : stalled ? ['warn', 'no progress for ' + dur(it.idle)] : ['', (CUTNAME[it.stage] || it.stage) + ' · ' + it.pct + '%'];
  head.appendChild(el('span', 'pill ' + st[0], st[1])); box.appendChild(head);
  var bar = el('div', 'bar mgbar'), i = el('i'); i.style.width = (final && it.stage !== 'failed' ? 100 : it.pct) + '%'; if (it.stage === 'failed') i.className = 'hot'; bar.appendChild(i); box.appendChild(bar);
  var list = cut || it.stage === 'live' ? CUTS : STAGES;
  var steps = el('div', 'ktstages'), at = list.indexOf(it.stage), end = it.stage === list[list.length - 1];
  list.forEach(function(s, n){ steps.appendChild(el('span', n < at || end ? 'done' : n === at ? 'now' : '', list === CUTS ? CUTNAME[s] : s)); });
  if (it.stage !== 'failed') box.appendChild(steps);
  var line = el('div', 'mgline');
  if (it.bytes_total > 0 && live){
    line.appendChild(el('span', null, size(it.bytes_done) + ' of ' + size(it.bytes_total)));
    if (it.rate_bps > 0){ line.appendChild(el('span', null, size(it.rate_bps) + '/s')); line.appendChild(el('span', 'muted', 'about ' + dur(Math.max(0, it.bytes_total - it.bytes_done) / it.rate_bps) + ' left')); }
  }
  if (line.childNodes.length) box.appendChild(line);
  if (it.detail) box.appendChild(el('small', 'muted ktdetail', it.detail));
  if (it.stage === 'live' && it.here) box.appendChild(dnsBox(it));
  if (it.report){ var dt = el('details', 'ktrep'); dt.appendChild(el('summary', null, 'Report')); dt.appendChild(el('pre', 'ktreport', it.report)); box.appendChild(dt); }
  if (cut && stalled){
    var ub = el('button', 'btn ghost', 'Put the old server back'); ub.type = 'button';
    ub.title = 'The cutover stopped making progress. This turns the holding page off on the old server, delivers its held mail there and unsuspends the account. Refused if mail was already passed on to this server.';
    ub.addEventListener('click', async function(){
      if (!confirm('Put ' + it.username + ' back on the old server (holding page off, held mail delivered there, account active)?')) return;
      busy(ub, true, 'Working…');
      try { await call('/admin/transfer/cutundo', {item: it.id}); poll(true); } catch(e){ busy(ub, false); alert(e.message); }
    });
    box.appendChild(ub);
  }
  if (it.stage === 'ready' && it.here && !jobLive){
    var cb = el('button', 'btn', 'Cut over to this server'); cb.type = 'button';
    cb.addEventListener('click', async function(){
      var c = prompt('Cut ' + it.username + ' over to this server?\n\n'
        + '1. OLD server: sites show a holding page, new mail waits in its queue, the account is suspended.\n'
        + '2. What changed since the copy is copied again (files, databases, mail, passwords).\n'
        + '3. OLD server passes mail on to this server; cron jobs and app daemons start here.\n'
        + '4. DNS on BOTH servers points at this server; SSL is reissued once public DNS shows it.\n\n'
        + 'If anything fails before step 3 the OLD server is put back exactly as it was. Usually a minute or two of holding page.\n\n'
        + 'Type the account name to go ahead:');
      if (c === null) return;
      busy(cb, true, 'Starting…');
      try { await call('/admin/transfer/cutover', {item: it.id, confirm: c.trim()}); poll(true); } catch(e){ busy(cb, false); alert(e.message); }
    });
    box.appendChild(cb);
  }
  return box;
}
// DNS check (live accounts): what public resolvers answer now. Asked only
// while open (a refresh a minute, stopping once every answer is this
// server); state lives here, outside the list poll() rebuilds.
var DNS = {}, RNAME = {'1.1.1.1': 'Cloudflare', '8.8.8.8': 'Google', '9.9.9.9': 'Quad9'};
function dnsDone(d){ return d && d.names.length && d.names.every(function(n){ return Object.keys(n.a || {}).every(function(r){ var s = n.a[r].s; return s === 'here' || s === 'none'; }); }); }
function dnsBody(id){
  var S = DNS[id], b = el('div', 'ktdnsbody'), d = S.data;
  if (!d){ b.appendChild(el('small', S.err ? 'ktbad' : 'muted', S.err || 'Asking public resolvers…')); return b; }
  var res = d.resolvers.length ? d.resolvers : [];
  var ok = d.names.filter(function(n){ return res.every(function(r){ var a = (n.a || {})[r]; return a && (a.s === 'here' || a.s === 'none'); }); }).length;
  b.appendChild(el('div', 'ktdnssum ' + (dnsDone(d) ? 'ok' : ''), dnsDone(d) ? '✅ Every resolver sends these names to this server' : ok + ' of ' + d.names.length + ' names reach this server at every resolver'));
  var t = el('table', 'kttable ktdnst'), h = el('tr'); h.appendChild(el('th', null, 'Name'));
  res.forEach(function(r){ h.appendChild(el('th', null, RNAME[r] || 'Upstream ' + r)); });
  var th = el('thead'); th.appendChild(h); t.appendChild(th);
  var tb = el('tbody');
  d.names.forEach(function(n){
    var tr = el('tr'); tr.appendChild(el('td', 'ktdnsn', n.name));
    res.forEach(function(r){
      var a = (n.a || {})[r], c;
      if (!a) c = el('td', 'muted', '?');
      else if (a.s === 'here') c = el('td', 'dok', '✅ here');
      else if (a.s === 'old') c = el('td', 'dwait', '⏳ old' + (a.ttl != null ? ' · ' + (a.ttl < 90 ? a.ttl + ' s' : Math.round(a.ttl / 60) + ' min') + ' left in cache' : ''));
      else if (a.s === 'other') c = el('td', 'dbad', '⚠ ' + a.ips);
      else if (a.s === 'none') c = el('td', 'muted', '— no record');
      else c = el('td', 'muted', '? no answer');
      c.setAttribute('data-l', RNAME[r] || r); tr.appendChild(c);
    });
    tb.appendChild(tr);
  });
  t.appendChild(tb); b.appendChild(t);
  var notes = el('ul', 'ktdnsnotes');
  d.zones.forEach(function(z){
    var m = z.s === 'here' ? '✅ ' + z.zone + ': its nameservers are on this server.'
      : z.s === 'old' ? '⚠ ' + z.zone + ': its nameservers (' + z.ns + ') are the OLD server. It already answers with this server\'s address, so visitors arrive here, but before you switch the old server off, change the nameservers (and any glue records) at the domain registrar to this server\'s.'
      : z.s === 'elsewhere' ? '⚠ ' + z.zone + ': its DNS is hosted elsewhere (' + z.ns + '). The cutover can\'t change that: edit its A records there (registrar or DNS host) to ' + (d.me[0] || 'this server') + '.'
      : z.s === 'mixed' ? '⚠ ' + z.zone + ': its nameservers (' + z.ns + ') are split between servers: point them all at one at the registrar.'
      : '? ' + z.zone + ': no resolver said which nameservers it has (not registered, or blocked here).';
    notes.appendChild(el('li', null, m));
  });
  d.names.forEach(function(n){ if (n.aaaa && n.aaaa.s !== 'here') notes.appendChild(el('li', null, '⚠ ' + n.name + ' also has an IPv6 address (' + n.aaaa.ips + ') that the cutover didn\'t change: IPv6 visitors still go there. If it was the old server, update or remove it on the DNS page.')); });
  if (d.note) notes.appendChild(el('li', 'muted', d.note));
  if (notes.childNodes.length) b.appendChild(notes);
  var f = el('div', 'mgline');
  f.appendChild(el('small', 'muted', 'Checked ' + new Date(S.at).toLocaleTimeString() + (dnsDone(d) ? '' : ' · refreshes every minute while open') + '. "Old" clears by itself when that resolver\'s cache runs out.'));
  var cb = el('button', 'btn ghost', S.busy ? 'Checking…' : 'Check now'); cb.type = 'button'; cb.disabled = !!S.busy;
  cb.addEventListener('click', function(){ dnsFetch(id); }); f.appendChild(cb);
  b.appendChild(f);
  return b;
}
function dnsPaint(id){ var o = document.querySelector('[data-item="' + id + '"] .ktdnsbody'); if (o) o.replaceWith(dnsBody(id)); var s = document.querySelector('[data-item="' + id + '"] .ktdnspill'); if (s) dnsPill(id, s); }
function dnsPill(id, p){ var d = DNS[id] && DNS[id].data; p.textContent = !d ? '' : dnsDone(d) ? 'all here' : 'propagating'; p.className = 'ktdnspill pill ' + (!d ? '' : dnsDone(d) ? 'ok' : 'warn'); p.hidden = !d; }
async function dnsFetch(id){
  var S = DNS[id]; if (!S || S.busy) return;
  clearTimeout(S.timer); S.busy = true; dnsPaint(id);
  try { S.data = await call('/admin/transfer/dnscheck', {item: id}); S.err = ''; S.at = Date.now(); }
  catch(e){ S.err = e.message; S.data = null; }
  S.busy = false; dnsPaint(id);
  if (S.open && !dnsDone(S.data)) S.timer = setTimeout(function(){ dnsFetch(id); }, 60000);
}
function dnsBox(it){
  var S = DNS[it.id] = DNS[it.id] || {open: false, data: null, err: '', at: 0, busy: false, timer: null};
  var dt = el('details', 'ktdns'), sm = el('summary', null, 'DNS check: is the world sending this account here yet? ');
  var p = el('span'); dnsPill(it.id, p); sm.appendChild(p); dt.appendChild(sm);
  dt.appendChild(dnsBody(it.id)); dt.open = S.open;
  dt.addEventListener('toggle', function(){
    S.open = dt.open;
    if (!S.open){ clearTimeout(S.timer); return; }
    if (!S.data || Date.now() - S.at > 55000) dnsFetch(it.id);
    else if (!dnsDone(S.data)){ clearTimeout(S.timer); S.timer = setTimeout(function(){ dnsFetch(it.id); }, 60000 - (Date.now() - S.at)); }
  });
  return dt;
}
var timer = null;
async function poll(force){
  clearTimeout(timer);
  var d;
  try { d = await (await fetch('/admin/transfer/status', {credentials:'same-origin'})).json(); } catch(e){ timer = setTimeout(poll, 10000); return; }
  var jobs = d.jobs || [], L = $('ktjoblist');
  $('ktjobs').hidden = !jobs.length;
  var open = {}; L.querySelectorAll('details.ktrep[open]').forEach(function(x){ open[x.closest('[data-item]').dataset.item] = 1; });
  L.textContent = '';
  var anyLive = false, movedLater = {};
  jobs.forEach(function(j){
    var live = j.state === 'running' || j.state === 'waiting' || j.state === 'queued';
    anyLive = anyLive || live || j.items.some(function(x){ return cutting(x.stage); });
    var jb = el('div', 'ktjob'), h = el('div', 'kthead');
    var ready = j.items.filter(function(x){ return x.stage !== 'failed' && x.stage !== 'queued' && (x.stage === 'ready' || x.stage === 'live' || cutting(x.stage)); }).length, failed = j.items.filter(function(x){ return x.stage === 'failed'; }).length;
    h.appendChild(el('span', null, 'Run #' + j.id + ' from ' + (j.source_ip || '?') + ' · ' + j.created_at));
    h.appendChild(el('span', 'pill ' + (live ? '' : failed ? 'err' : 'ok'), live ? (j.state === 'waiting' ? 'waiting its turn' : 'running') + ' · ' + ready + '/' + j.items.length + ' ready' : ready + '/' + j.items.length + ' ready' + (failed ? ', ' + failed + ' failed' : '')));
    jb.appendChild(h);
    j.items.forEach(function(it){ var n = item(it, live); n.dataset.item = it.id; if (open[it.id]){ var dt = n.querySelector('details.ktrep'); if (dt) dt.open = true; } jb.appendChild(n); });
    var retryable = j.items.filter(function(x){ return x.stage === 'failed' && !movedLater[x.username]; }).length;
    j.items.forEach(function(x){ if (x.stage !== 'failed') movedLater[x.username] = 1; });
    if (!live && retryable){
      var rb = el('button', 'btn ghost', 'Retry the ' + retryable + ' failed'); rb.type = 'button';
      rb.addEventListener('click', async function(){ busy(rb, true, 'Starting…'); try { await call('/admin/transfer/retry', {job: j.id}); poll(true); } catch(e){ busy(rb, false); alert(e.message); } });
      jb.appendChild(rb);
    }
    if (!live && j.items.some(function(x){ return x.stage === 'ready' && x.here; })) jb.appendChild(el('small', 'muted ktnext', 'Ready accounts work on this server now, but visitors and mail still go to the OLD server, where the copy stays live and unchanged. Check the account here first, then press Cut over: it switches the account across with only a short holding page. Afterwards the OLD copy stays suspended (not deleted) until you remove it there.'));
    L.appendChild(jb);
  });
  timer = setTimeout(poll, anyLive ? 2000 : 30000);
}
poll();
})();
</script>

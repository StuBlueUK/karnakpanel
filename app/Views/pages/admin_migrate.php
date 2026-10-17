<h2>🚚 Migrate <small style="color:var(--muted)">accounts in and out — admin</small></h2>
<div class="card"><h3>📥 cPanel import <small style="color:var(--muted)">full-backup tarball into an existing user + domain — files, DBs, mailboxes. DNS, subdomains and cron stay manual.</small></h3>
<form class="inline" method="post" action="/accounts/import" enctype="multipart/form-data">
<input type="hidden" name="csrf" value="<?=Helpers::csrf()?>">
<input type="file" name="pkg" accept=".tar.gz" required>
<input name="cpuser" placeholder="cPanel user" required style="max-width:130px">
<input name="username" placeholder="panel user" required style="max-width:130px">
<input name="domain" placeholder="target domain" required style="min-width:180px">
<button class="btn">Import cPanel backup</button></form>
<small style="color:var(--muted)">WordPress DB passwords are recovered from wp-config automatically; other apps get random passwords (see Logs → software for the mapping).</small></div>
<div class="card"><h3>🌐 cPanel remote import <small style="color:var(--muted)">no root needed — just the site's own login</small></h3>
<ol style="margin:0 0 8px;padding-left:20px;font-size:14px">
<li>Create the target panel user + domain first (Accounts, then Domains).</li>
<li>On the <b>source</b> cPanel: Security → Manage API Tokens → create one (any name), and have the <b>cPanel username + password + token</b> ready. (Modern cPanel refuses password API calls — the token triggers the backup; the password pulls it.)</li>
<li>Enter everything below. The source builds a full backup and we pull it over, delete the remote copy, then import as above. Big accounts take a while — progress shows below.</li>
</ol>
<form class="inline" method="post" action="/accounts/import-remote">
<input type="hidden" name="csrf" value="<?=Helpers::csrf()?>">
<input name="host" placeholder="source host (or IP)" required style="min-width:170px" autocomplete="off">
<input name="cpuser" placeholder="cPanel user" required style="max-width:130px" autocomplete="off">
<input name="cppass" type="password" placeholder="cPanel password" required autocomplete="new-password" style="max-width:150px">
<input name="cptoken" placeholder="API token" autocomplete="off" style="max-width:170px">
<input name="username" placeholder="panel user" required style="max-width:130px">
<input name="domain" placeholder="target domain" required style="min-width:170px">
<button class="btn">Fetch &amp; import</button></form>
<small style="color:var(--muted)">Both secrets are used once for the fetch and never stored. Needs the source to allow UAPI + outbound FTP; failures say why in Logs → software.</small></div>
<div class="card"><h3>🔑 cPanel root import <small style="color:var(--muted)">for server owners — fastest, no FTP involved</small></h3>
<ol style="margin:0 0 8px;padding-left:20px;font-size:14px">
<li>Create the target panel user + domain first.</li>
<li>Enter the <b>source</b> host plus <b>root SSH</b> (user, usually <code>root</code>, + password).</li>
<li>We run <code>pkgacct</code> on the source, copy the archive home, remove the remote copy, then import. Same progress box below.</li>
</ol>
<form class="inline" method="post" action="/accounts/import-root">
<input type="hidden" name="csrf" value="<?=Helpers::csrf()?>">
<input name="host" placeholder="source host (or IP)" required style="min-width:170px" autocomplete="off">
<input name="rootuser" placeholder="root user (root)" value="root" style="max-width:110px" autocomplete="off">
<input name="rootpass" type="password" placeholder="root password" required autocomplete="new-password" style="max-width:150px">
<input name="cpuser" placeholder="cPanel user" required style="max-width:130px" autocomplete="off">
<input name="username" placeholder="panel user" required style="max-width:130px">
<input name="domain" placeholder="target domain" required style="min-width:170px">
<button class="btn">Fetch as root &amp; import</button></form>
<small style="color:var(--muted)">Root password is used once for SSH and never stored. A WHM API token alone cannot move accounts (no shell) — root SSH is required.</small></div>
<div class="card"><h3>🟦 HestiaCP import <small style="color:var(--muted)">everything one Hestia user owns — sites, subdomains, DBs, mail, DNS records, cron</small></h3>
<ol style="margin:0 0 8px;padding-left:20px;font-size:14px">
<li>Create the target <b>panel user</b> first (Accounts). Domains are created for you — subdomains land as records in their parent zone.</li>
<li>Either give <b>root SSH</b> to the Hestia server (we run <code>v-backup-user</code>, copy it here, delete the remote copy), or download the user's backup in Hestia (Backups → download) and upload it.</li>
<li>Kept as-is: DB names/users/passwords (MariaDB), mailbox passwords, aliases, catch-alls. Cron jobs arrive <b>paused</b> for review. SSL is reissued once DNS points here.</li>
</ol>
<form class="inline" method="post" action="/accounts/import-hestia">
<input type="hidden" name="csrf" value="<?=Helpers::csrf()?>">
<input name="host" placeholder="Hestia host (or IP)" required style="min-width:170px" autocomplete="off">
<input name="port" placeholder="SSH port" value="22" inputmode="numeric" style="max-width:80px" autocomplete="off">
<input name="rootuser" placeholder="root user (root)" value="root" style="max-width:110px" autocomplete="off">
<input name="rootpass" type="password" placeholder="root password" required autocomplete="new-password" style="max-width:150px">
<input name="huser" placeholder="Hestia user" required style="max-width:130px" autocomplete="off">
<input name="username" placeholder="panel user" required style="max-width:130px">
<button class="btn">Fetch &amp; import</button></form>
<form class="inline" method="post" action="/accounts/import-hestia-upload" enctype="multipart/form-data" style="margin-top:8px">
<input type="hidden" name="csrf" value="<?=Helpers::csrf()?>">
<input type="file" name="pkg" accept=".tar" required>
<input name="huser" placeholder="Hestia user" required style="max-width:130px" autocomplete="off">
<input name="username" placeholder="panel user" required style="max-width:130px">
<button class="btn">Import Hestia backup</button></form>
<small style="color:var(--muted)">Root password is used once for SSH and never stored. Heads-up: Hestia keeps a fixed number of backups per user — making a new one deletes that user's <b>oldest</b> backup if the limit is reached. Existing sites with files in their docroot are not overwritten. Full report: Logs → software.</small></div>
<div class="card" id="migprog" style="display:none"><h3>⏳ Import progress</h3>
<div class="bar" style="max-width:420px"><i id="migBar" style="width:0%"></i></div>
<p><b id="migStage">queued</b> <span id="migPct"></span><br><small id="migDetail" style="color:var(--muted)"></small></p></div>
<script>
(function(){
var m = new URLSearchParams(location.search).get('job');
if (!m || !/^[a-f0-9]{16}$/.test(m)) return;
document.getElementById('migprog').style.display = '';
async function poll(){
  try {
    var r = await fetch('/accounts/import-status?job=' + m, {credentials:'same-origin'});
    if (!r.ok) return;
    var d = await r.json();
    document.getElementById('migStage').textContent = d.stage || 'queued';
    document.getElementById('migPct').textContent = (d.pct != null) ? d.pct + '%' : '';
    document.getElementById('migDetail').textContent = d.detail || '';
    document.getElementById('migBar').style.width = Math.min(100, d.pct || 0) + '%';
    if (d.stage !== 'done' && d.stage !== 'failed') setTimeout(poll, 5000);
  } catch(e){ setTimeout(poll, 10000); }
}
poll();
})();
</script>
<div class="card"><h3>📦 KarnakPanel transfer <small style="color:var(--muted)">whole-box moves between panel servers</small></h3>
<p style="color:var(--muted)">Server moves run over SSH today: <code>panel-transfer.sh</code> on the source builds a version-gated bundle, <code>panel-transfer-import.sh</code> restores it on a fresh target (plus rsync for homes/mail), then <code>--finish</code>. Direct-admin import and bundle upload land here next.</p></div>

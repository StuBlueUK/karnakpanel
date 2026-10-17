<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<?php $__bname = !empty($__brand['name']) ? $__brand['name'] : 'KarnakPanel'; $__blogo = !empty($__brand['logo_hash']) ? '/brand/logo?h='.$__brand['logo_hash'] : ''; ?>
<title><?= Helpers::e($__bname) ?> <?= isset($page_title) ? '— '.Helpers::e($page_title) : '' ?></title>
<?php if($__blogo):?><link rel="icon" href="<?=Helpers::e($__blogo)?>" type="image/png"><?php else:?><link rel="icon" href="/assets/img/logo.svg?v=<?=filemtime(__DIR__.'/../../public/assets/img/logo.svg')?>" type="image/svg+xml"><?php endif;?>
<link rel="stylesheet" href="/assets/css/karnakpanel.css?v=<?=filemtime(__DIR__.'/../../public/assets/css/karnakpanel.css')?>">
<?php if(!empty($__brand['accent'])):?><style><?= Branding::css($__brand['accent']) /* strict #rrggbb only */ ?></style><?php endif;?>
</head>
<body>
<?php if ($user): ?>
<header class="topbar">
  <?php if($__blogo || !empty($__brand['name'])):?>
  <div class="brand"><?php if($__blogo):?><img src="<?=Helpers::e($__blogo)?>" alt="<?=Helpers::e($__bname)?>" style="width:auto;max-width:180px"><?php endif;?><?php if(!empty($__brand['name'])):?> <span><strong><?=Helpers::e($__bname)?></strong></span><?php endif;?></div>
  <?php else:?>
  <div class="brand"><img src="/assets/img/logo.svg?v=<?=filemtime(__DIR__.'/../../public/assets/img/logo.svg')?>" alt="KarnakPanel"> <span>Karnak<strong>Panel</strong></span></div>
  <?php endif;?>
  <div class="search"><input id="toolSearch" placeholder="🔍 Search tools (domains, email, ftp, dns, cron...)"></div>
  <div class="userbox">
    <button id="themeToggle" class="hlink" title="toggle theme">🌙 / ☀️</button>
    <span class="role"><?= Helpers::e($user['role']) ?></span>
    <span class="username"><?= Helpers::e($user['username']) ?></span>
    <a class="hlink" href="/notifications" title="Notifications">🔔<?php if(!empty($unread)):?> <span class="badge"><?=$unread?></span><?php endif;?></a>
    <a class="hlink" href="/password" title="Change password">🔑 Password</a>
    <a class="hlink" href="/2fa" title="Two-factor authentication">🔐 2FA</a>
    <form method="post" action="/logout" style="display:inline;margin:0"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><button class="hlink" title="Log out">Logout</button></form>
  </div>
</header>
<?php if($imp = Auth::impersonator()):?><div class="impbar">👁 Viewing as <b><?=Helpers::e($user['username'])?></b><span>you are <?=Helpers::e($imp['username'])?> — all actions logged</span><form method="post" action="/impersonate/return" style="margin:0"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><button class="btn small">Return to <?=Helpers::e($imp['username'])?></button></form></div><?php endif;?>
<div class="shell">
<aside class="sidebar">
  <?php if($__brand):?>
  <?php if(!empty($__brand['support_url'])):?><div><a class="btn small" style="display:block;text-align:center" href="<?=Helpers::e($__brand['support_url'])?>" target="_blank" rel="noopener">💬 Get support</a></div><?php endif;?>
  <?php if(!empty($__brand['support_email'])):?><div style="margin-top:6px;text-align:center"><small><a href="mailto:<?=Helpers::e($__brand['support_email'])?>"><?=Helpers::e($__brand['support_email'])?></a></small></div><?php endif;?>
  <?php else:?>
  <?php $donate = (string)Config::get('app.donate_url'); if (!preg_match('#^https?://#i', $donate)) $donate = 'https://karnakpanel.com'; ?>
  <div><a class="btn small" style="display:block;text-align:center" href="<?=Helpers::e($donate)?>" target="_blank" rel="noopener">❤ Support KarnakPanel</a></div>
  <div style="display:grid;gap:6px;margin-top:6px">
  <a class="btn small" style="display:block;text-align:center" href="https://hostbms.com" target="_blank" rel="noopener">Visit Hostbms.com — Free Billing</a>
  </div>
  <?php endif;?>
  <div class="stat-card"><h4>Disk</h4><div class="bar"><i style="width:<?= $quota ? $quota['pct'] : 0 ?>%"></i></div><small><?= $quota ? Helpers::e(Helpers::quotaText($quota)) : '—' ?></small></div>
  <div class="stat-card"><h4>Bandwidth <small style="color:var(--muted)">this month</small></h4><div class="bar"><i style="width:<?= ($quota && empty($quota['unlimited']) && $bwBytes !== null && $quota['bw_quota_gb'] > 0) ? min(100, round($bwBytes/($quota['bw_quota_gb']*1073741824)*100, 1)) : 0 ?>%"></i></div><small><?= $bwBytes !== null ? Helpers::fmtMb($bwBytes/1048576).(!empty($quota['unlimited']) ? ' used / unlimited' : ' / '.(int)$quota['bw_quota_gb'].' GB') : 'metering soon' ?></small></div>
  <nav>
    <a href="/">🏠 Dashboard</a>
    <?php if (in_array($user['role'],['admin','reseller'])): ?>
    <a href="/accounts">👥 Accounts</a>
    <a href="/reseller">🏪 Reseller Center</a>
    <a href="/fleet">🔄 Fleet Updates</a>
    <a href="/api-tokens">🔌 API Tokens</a>
    <?php endif; ?>
    <a href="/domains">🌐 Domains</a>
    <a href="/redirects">↪️ Redirects</a>
    <a href="/files">🗂️ File Manager</a>
    <div class="navgroup">Mail &amp; data</div>
    <a href="/email">✉️ Email</a>
    <a href="/databases">🗄️ Databases <small>MariaDB</small></a>
    <a href="/dns">🧭 DNS <small>BIND</small></a>
    <a href="/ftp">📁 FTPS</a>
    <div class="navgroup">Manage</div>
    <a href="/stats">📊 Stats</a>
    <a href="/disk">💿 Disk usage</a>
    <a href="/backups">💾 Backups</a>
    <a href="/cron">⏱️ Cron Jobs</a>
    <a href="/daemons">⚙️ Daemons</a>
    <a href="/php">🐘 PHP Versions</a>
    <a href="/software">🧰 Software</a>
    <a href="/toolkit">🧪 App Toolkit <small>staging</small></a>
    <?php if ($user['role']==='admin'): ?>
    <a href="/admin/services">⚙️ Services</a>
    <a href="/admin/mail">📨 Mail queue</a>
    <a href="/admin/logs">📋 Logs</a>
    <a href="/admin/update">⬆️ Update</a>
    <a href="/admin/sessions">👥 Sessions</a>
    <a href="/admin/firewall">🧱 Firewall</a>
    <a href="/admin/phpini">🐘 PHP.ini</a>
    <a href="/admin/php-pools">🛡️ PHP pools</a>
    <a href="/admin/disk">🧹 Disk</a>
    <a href="/admin/ssh">🔑 SSH</a>
    <a href="/admin/migrate">🚚 Migrate</a>
    <?php endif; ?>
  </nav>
  <div style="margin-top:14px;padding-top:10px;border-top:1px solid var(--line);text-align:center"><small style="color:var(--muted)">&copy; <?=date('Y')?> <a href="https://karnakpanel.com" target="_blank" rel="noopener">KarnakPanel.com</a></small></div>
</aside>
<main class="content">
  <?php if ($flash_ok): ?><div class="alert ok"><?= Helpers::e($flash_ok) ?></div><?php endif; ?>
  <?php if ($flash_err): ?><div class="alert err"><?= Helpers::e($flash_err) ?></div><?php endif; ?>
  <?php require $__view; ?>
</main>
</div>
<?php else: ?>
<main class="login-wrap"><?php require $__view; ?></main>
<?php endif; ?>
<script src="/assets/js/karnakpanel.js"></script>
</body></html>

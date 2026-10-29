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
  <button id="navToggle" class="navtoggle" type="button" aria-label="Menu" aria-controls="sidebar" aria-expanded="false">☰</button>
  <?php if($__blogo || !empty($__brand['name'])):?>
  <div class="brand"><?php if($__blogo):?><img src="<?=Helpers::e($__blogo)?>" alt="<?=Helpers::e($__bname)?>" style="width:auto;max-width:180px"><?php endif;?><?php if(!empty($__brand['name'])):?> <span><strong><?=Helpers::e($__bname)?></strong></span><?php endif;?></div>
  <?php else:?>
  <div class="brand"><img src="/assets/img/logo.svg?v=<?=filemtime(__DIR__.'/../../public/assets/img/logo.svg')?>" alt="KarnakPanel"> <span>Karnak<strong>Panel</strong></span></div>
  <?php endif;?>
  <div class="search"><input id="toolSearch" placeholder="🔍 Search tools (domains, email, ftp, dns, cron...)"></div>
  <?php // support links: header on desktop, inside the 👤 menu on phones
  $__sup = [];
  if ($__brand) {
      if (!empty($__brand['support_url'])) $__sup[] = [$__brand['support_url'], '💬 Get support', true];
      if (!empty($__brand['support_email'])) $__sup[] = ['mailto:'.$__brand['support_email'], '✉️ '.$__brand['support_email'], false];
  } else {
      $donate = (string)Config::get('app.donate_url'); if (!preg_match('#^https?://#i', $donate)) $donate = 'https://karnakpanel.com';
      $__sup[] = [$donate, '❤ Support KarnakPanel', true];
      $__sup[] = ['https://hostbms.com', 'Hostbms.com — Free Billing', true];
  }
  $__supHtml = '';
  foreach ($__sup as [$h, $t, $ext]) $__supHtml .= '<a href="'.Helpers::e($h).'"'.($ext ? ' target="_blank" rel="noopener"' : '').'>'.Helpers::e($t).'</a>';
  ?>
  <?php if($__supHtml !== ''):?><div class="hdrlinks"><?=$__supHtml?></div><?php endif;?>
  <div class="userbox">
    <button id="searchToggle" class="hlink searchtoggle" type="button" aria-label="Search tools">🔍</button>
    <button id="themeToggle" class="hlink" title="Toggle light/dark theme" aria-label="Toggle theme">🌓</button>
    <a class="hlink" href="/notifications" title="Notifications" aria-label="Notifications">🔔<?php if(!empty($unread)):?> <span class="badge"><?=$unread?></span><?php endif;?></a>
    <details class="acctmenu">
      <summary class="hlink" title="Account">👤 <span class="username"><?= Helpers::e($user['username']) ?></span> <span class="role"><?= Helpers::e($user['role']) ?></span> ▾</summary>
      <div class="acctpop">
        <a href="/password">🔑 Change password</a>
        <a href="/2fa">🔐 Two-factor auth</a>
        <?php if($__supHtml !== ''):?><div class="acctsup"><?=$__supHtml?></div><?php endif;?>
        <form method="post" action="/logout"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><button type="submit">🚪 Log out</button></form>
      </div>
    </details>
  </div>
</header>
<?php if($imp = Auth::impersonator()):?><div class="impbar">👁 Viewing as <b><?=Helpers::e($user['username'])?></b><span>you are <?=Helpers::e($imp['username'])?> — all actions logged</span><form method="post" action="/impersonate/return" style="margin:0"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><button class="btn small">Return to <?=Helpers::e($imp['username'])?></button></form></div><?php endif;?>
<div class="shell">
<aside class="sidebar" id="sidebar">
  <div class="stat-card"><h4>Disk</h4><div class="bar"><i style="width:<?= $quota ? $quota['pct'] : 0 ?>%"></i></div><small><?= $quota ? Helpers::e(Helpers::quotaText($quota)) : '—' ?></small></div>
  <div class="stat-card"><h4>Bandwidth <small style="color:var(--muted)">this month</small></h4><div class="bar"><i style="width:<?= ($quota && empty($quota['unlimited']) && $bwBytes !== null && $quota['bw_quota_gb'] > 0) ? min(100, round($bwBytes/($quota['bw_quota_gb']*1073741824)*100, 1)) : 0 ?>%"></i></div><small><?= $bwBytes !== null ? Helpers::fmtMb($bwBytes/1048576).(!empty($quota['unlimited']) ? ' used / unlimited' : ' / '.(int)$quota['bw_quota_gb'].' GB') : 'metering soon' ?></small></div>
  <?php
  // grouped by task; current page highlighted (prefix match keeps sub-pages lit)
  $__path = (string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
  $__na = function (string $href, string $label, array $also = []) use ($__path): string {
      $on = $href === '/' ? $__path === '/' : ($__path === $href || str_starts_with($__path, $href.'/'));
      foreach ($also as $x) $on = $on || $__path === $x || str_starts_with($__path, $x.'/');
      return '<a href="'.$href.'"'.($on ? ' class="active" aria-current="page"' : '').'>'.$label.'</a>';
  };
  $__isAdmin = $user['role'] === 'admin';
  ?>
  <nav>
    <?=$__na('/', '🏠 Dashboard')?>
    <div class="navgroup">Websites</div>
    <?=$__na('/domains', '🌐 Domains <small>+ redirects</small><span class="vh"> subdomains</span>', ['/redirects'])?>
    <?=$__na('/dns', '🧭 DNS <small>BIND</small>')?>
    <?php if (Builder::enabled()): ?><?=$__na('/builder', '🎨 Karnak Builder <small>drag &amp; drop</small>')?><?php endif; ?>
    <?=$__na('/software', '🧰 Software <small>1-click</small>')?>
    <?=$__na('/toolkit', '🧪 App Toolkit <small>staging</small>')?>
    <div class="navgroup">Email</div>
    <?=$__na('/email', '✉️ Email')?>
    <div class="navgroup">Files &amp; data</div>
    <?=$__na('/files', '🗂️ File Manager')?>
    <?=$__na('/ftp', '📁 FTPS')?>
    <?=$__na('/databases', '🗄️ Databases <small>MariaDB</small>')?>
    <?=$__na('/backups', '💾 Backups')?>
    <?=$__na('/disk', '💿 Disk usage')?>
    <div class="navgroup">Advanced</div>
    <?=$__na('/php', '🐘 PHP Versions')?>
    <?=$__na('/cron', '⏱️ Cron Jobs')?>
    <?=$__na('/daemons', '🔁 Daemons')?>
    <?=$__na('/stats', '📊 Stats')?>
    <?php if (in_array($user['role'],['admin','reseller'])): ?>
    <div class="navgroup">Clients</div>
    <?=$__na('/accounts', '👥 Accounts')?>
    <?=$__na('/reseller', '🏪 Reseller Center')?>
    <?=$__na('/fleet', '🔄 Fleet Updates')?>
    <?=$__na('/api-tokens', '🔌 API Tokens')?>
    <?php endif; ?>
    <?php if ($__isAdmin): ?>
    <div class="navgroup">Server</div>
    <?=$__na('/admin/services', '⚙️ Services')?>
    <?=$__na('/admin/abuse', '🔥 Abuse board')?>
    <?=$__na('/admin/mail', '📨 Mail queue')?>
    <?=$__na('/admin/firewall', '🧱 Firewall')?>
    <?=$__na('/admin/ssh', '🔑 SSH')?>
    <?=$__na('/admin/sessions', '🪪 Sessions')?>
    <?=$__na('/admin/logs', '📋 Logs')?>
    <?=$__na('/admin/phpini', '📝 PHP.ini')?>
    <?=$__na('/admin/php-pools', '🛡️ PHP pools')?>
    <?=$__na('/admin/php-ext', '🧩 PHP extensions')?>
    <?=$__na('/admin/disk', '🧹 Disk cleanup')?>
    <?=$__na('/admin/migrate', '🚚 Migrate')?>
    <?=$__na('/admin/update', '⬆️ Update')?>
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
<script src="/assets/js/karnakpanel.js?v=<?=filemtime(__DIR__.'/../../public/assets/js/karnakpanel.js')?>"></script>
</body></html>

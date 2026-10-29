# KarnakPanel changelog

## 1.5.45 — 2026-10-29 (Website builder template gallery, PHP sandbox)
- Website builder: the New site picker is now a template gallery.
  - Each template shows its colours, style, page count and font, and the
    list can be filtered by category.
  - Preview opens a live, click-through copy of the template (desktop or
    phone width) before you pick it. "Use this" selects it.
- Website builder: 25 new templates (75 in all), including a new "Shops &
  retail" category and the first templates in the Editorial, Soft, Retro
  and Luxe styles. Quick looks no longer list the same palette twice.
- Security: customer PHP runs in a systemd sandbox.
  - The system is read-only to PHP except the folders sites write to.
  - A PHP worker sees only its own account's processes.
  - PHP gets its own /dev/shm, no longer shared with cron jobs, apps or
    the host. Sites on the same PHP version still share one.
  - Setuid file creation and low-level system calls (mount, ptrace, raw
    I/O, kernel modules) are blocked. mail() keeps working.
  - Applied automatically on update. A PHP version that won't start with
    the sandbox is put back without it. As root,
    `/usr/local/skypanel/scripts/fpm-sandbox.sh off` turns it off.
- Security: cron jobs and supervised apps also get a private /dev/shm.
- Panel: old web server config backups from panel switches are pruned
  (newest 3 kept), and a PHP runtime switch ends the old panel process.

## 1.5.44 — 2026-10-28 (Account isolation: panel user, web server limits, private /tmp)
- Security: the panel no longer runs as the web server's user.
  - The panel now runs as its own system user (karnak-panel). It is
    switched over automatically on update, with a check before and an
    automatic switch-back if the check fails.
  - The panel may run only the server scripts its own code names. Any
    other script is refused.
- Security: the web server reads only website folders. Everything else
  in a home (mail, backups, logs, cron output) is readable by the account
  and the panel only.
- Security: private /tmp per account.
  - Cron jobs and supervised apps get their own /tmp and /var/tmp, which
    other accounts can't see.
  - Website PHP no longer reaches the shared /tmp; it uses the account's
    own temp folder, which cron jobs also see as /tmp.
  - Apps that write to /tmp by a fixed path will fail. As root, put one
    account back on the shared /tmp with
    `touch /var/lib/karnakpanel/tmp-shared/<user>` and then
    `/usr/local/skypanel/scripts/fpm-harden.sh <user>`.
  - Running supervised apps switch over on their next restart.
- Security: DNS zone edits and file-manager zip downloads no longer use
  the shared /tmp.
- Fixed: the Exim (mail login) Fail2ban jail stopped working after the
  nightly log rotation. It now follows the new log.
- Fixed: the phpMyAdmin jail counted visits without a password as failed
  logins, so two page loads could ban the admin's own IP. Only real failed
  logins count now.
- Fixed: the mail queue's frozen-message actions failed on an empty queue.
- Fixed: on Dovecot 2.3 the TLS DH parameters were never set, so mail
  clients using DHE ciphers failed to connect.

## 1.5.43 — 2026-10-23 (Mailbox import; one-click Joomla and Drupal; builder blog; security hardening)
- New: Mailbox import (Email → Manage → Import).
  - Copies every folder from another IMAP account (Gmail, Yahoo, iCloud,
    Zoho or any TLS IMAP host) into a mailbox here, in the background, with
    progress.
  - One-way: the old account is never changed, and mail already here is
    kept. Running it again fetches only new mail.
  - The remote password is never stored or put on a command line. Only
    TLS with a valid certificate to public hosts is allowed, and the
    mailbox quota is enforced.
- New: one-click Joomla 6 (domain root or any folder, PHP 8.3+). Database,
  site and admin account are all made by the panel, with no web wizard. A
  failed install removes what it unpacked and its database.
- New: one-click Drupal 11 (domain root, PHP 8.3+).
  - Comes with the Article (tags, comments, image) and Basic page content
    types, and the admin account is made in the panel.
  - Drupal's .htaccess protections are applied as server rules, since
    OpenLiteSpeed ignores .htaccess.
- Karnak Builder:
  - Blog: posts at /blog/ with dates, tags, drafts, tag pages, paging, an
    RSS feed and sitemap entries. New blocks: Latest posts, Post list,
    Post date and tags.
  - Four more site styles (Editorial, Soft, Retro and Luxe) with Quick
    looks.
  - Publishing refuses to write into a folder the builder didn't make, such
    as an installed app's.
- App Toolkit (staging):
  - Cloning a WordPress whose setup was never finished is refused up front,
    leaving no staging domain or DNS record behind.
  - Staging passwords no longer appear in logs or on command lines.
- Fixed: Flarum and PrestaShop lost their server protection rules whenever
  the domain's configuration was rebuilt (for example after an SSL or PHP
  change).
- Faster: the first configuration rebuild after a large app install took
  minutes. App installers now set private permissions directly.
- Fixed: fresh installs were missing a phpMyAdmin access column (repaired
  automatically on update).
- Security:
  - System scripts never follow paths inside customer home folders as root.
    File manager, restores, app installers, staging, imports and mail
    migration all act as the account owner.
  - A database or FTP name could be matched to another account sharing its
    prefix. Names are now checked strictly, and an account deleted and
    re-created under the same name can't see the old holder's backups.
  - cPanel and Hestia imports run customer SQL with a database-scoped
    login, never as the database root.
  - Webmail and phpMyAdmin say so loudly (admin notice, daily retry) when
    they can't be moved off the shared web worker.
  - Database passwords no longer reach logs when a create fails.

## 1.5.42 — 2026-10-19 (Blacklist monitor; Email page overhaul; PHP extensions; builder styles and blocks)
- New: Blacklist monitor.
  - Every night the panel checks the server's mail IPs (IPv4 and IPv6) and
    every hosted domain on Spamhaus, SpamCop, Barracuda, PSBL, GBUdb,
    UCEPROTECT, SURBL and URIBL.
  - The admin gets an alert and an email only when a listing appears or
    clears, never every night.
  - A Blacklists card on the Mail queue page shows each list with its
    removal link and has a Check now button.
  - Lists that refuse this server's DNS resolvers show as "can't check",
    never as clean.
- New: Admin → PHP extensions.
  - Install or remove extensions such as imagick, redis, memcached, soap,
    gmp, bz2, sqlite3, pgsql, mongodb, imap, ldap and ssh2 on every PHP
    version at once, in the background.
  - Each version's status is shown, with missing ones in red. New PHP
    versions get the same set automatically.
  - Removal is refused when it would take other PHP packages with it.
    Installs restart PHP briefly.
- New: DNS at the registrar notice.
  - A nightly check asks public resolvers where each domain's nameservers
    point.
  - When they're with the registrar instead of this server, the DNS, Email
    and Domains pages say so: records added here aren't public, and the
    Email page gives SPF and DMARC records to copy across.
- Email page overhaul.
  - Mailboxes: set the quota on create and edit (capped by the account's
    disk allowance), set your own password, usage bar with a percentage, a
    domain filter, and row actions in one Manage menu so the table fits a
    phone.
  - Forwarders can keep a copy in the mailbox of the same name and can be
    edited in place.
  - Filters can match contains, is or starts with, check Cc and the spam
    score, and can be paused, resumed and edited. (Fixed: a spam-score
    filter only matched one exact score.)
  - Autoresponders take optional start and end dates and can be edited.
  - Mailing lists take many members in one paste.
  - Authentication: one card per domain with SPF, DKIM, DMARC, MTA-STS and
    report status, a score with a readable checklist, and Check and Sign
    side by side.
  - Server tab: queue, frozen, held-mailbox, outbound and guard tiles, and
    held mailboxes from every account with a Release button.
  - Pills stay neutral unless something is wrong.
- Mail authentication: new domains get DMARC (p=quarantine) beside SPF and
  are DKIM-signed when created. Sign adds any missing SPF or DMARC record.
- Cron Jobs page: schedules read in plain English ("every 5 min", "daily at
  02:00"), a common-schedule picker, and each job's last run shows ok or
  failed with its output. The admin's System schedule is grouped and
  explains every job.
- Karnak Builder:
  - Site styles (Aurora, Vivid, Glass, Midnight and Bold) with a second
    accent colour for gradients, one-click Quick looks in the Theme dialog,
    and ten new "Modern & bold" templates (50 in all).
  - New blocks: FAQ, photo slider, social links, opening hours, team and
    price list.
  - Fixed: a page's styling sometimes went missing after switching pages
    in the editor.
- Domains: status and builder pills sit under the domain name instead of
  overflowing; more than two builder sites fold into one "Builder ×N" pill.
- Fixed: the panel's PHP no longer logs "Module intl is already loaded" on
  every worker start.
- Security: three system scripts (DKIM status, DNSSEC status and PHP
  switch) now validate their arguments like the others.

## 1.5.41 — 2026-10-18 (Karnak Builder website builder with 40 templates; remove installed apps)
- New: Karnak Builder, a drag-and-drop website builder in the panel.
  - Build a site at a domain root or in a folder, starting blank or from one
    of 40 templates in 9 categories (trades, food, health, business,
    creative, tech, travel, community and more), picked from a card grid.
  - The editor autosaves, keeps a history you can roll back to, and has
    desktop/tablet/phone views.
  - Share a private preview link before publishing.
  - Publish, take a site offline, and put it back online.
  - Images uploaded in the editor go straight onto the page. Gallery and
    template photo boxes take a picture with a double-click.
  - One editor at a time per site: a second tab can take over the edit lock.
  - Published sites are plain static files. Publishing never overwrites
    files it did not write. Pages are cleaned on the server (no scripts, and
    only YouTube, Vimeo and OpenStreetMap embeds), and images are re-encoded.
  - All builder file work runs as the account's own user.
  - Domains and Subdomains show a pill for each published builder site, and
    the Dashboard has a Karnak Builder quick action.
  - Accounts with no package can use it straight away. On packages the
    limit starts at 0 (off): set "Karnak Builder sites" on each package (or
    reseller allocation) to switch it on for those customers.
- Software → Installed apps: apps can now be removed.
  - The Remove button deletes the app's files and, if "and its database" is
    ticked, its database too (only a database tracked under the same account).
  - Removing an app from a domain root keeps Karnak Builder sites, other
    apps' folders, SSL validation files and autodiscover, and puts the
    "coming soon" page back.
  - Flarum and PrestaShop web-server rules are removed with them.
  - Refused while the app has a staging copy, or when another site lives
    inside its folder.

## 1.5.40 — 2026-10-18 (redesigned Software page with live install progress; FTPS connect host)
- FTPS page shows the exact host name to connect to (the one on the FTP
  server's certificate) with port and TLS mode, and explains that any other
  name or the IP address gives a certificate mismatch warning. Self-signed
  servers get a note to trust the certificate once.
- Software (one-click installs) redesigned: app catalogue cards with what
  each needs (domain root only, PHP version, admin created here or finished
  in the browser); unavailable apps say why. The install form adapts to the
  chosen app (folder and admin fields only where they apply, domains on the
  wrong PHP version disabled with the reason, live preview of the final
  URL, database limit checked before you start). Installs now report real
  progress: "Installing…" while running, then the page refreshes, and a
  failed install shows the reason instead of a misleading "started". A
  second install on the same domain/folder is refused while one runs.
  Installed apps list Open site + Admin links (phpBB "Finish setup" while
  its installer is still there, PrestaShop's renamed back office), the
  WordPress auto-update switch and a Staging shortcut.

## 1.5.39 — 2026-10-18 (redesigned Stats, Services, DNS, Dashboard, Accounts, Firewall; scheduled tasks audit)
- Scheduled tasks audit:
  - Certificates renewed by the system certbot timer now reload the web
    server, FTP and the mail cert too. Before, only the panel's own renewal
    run did, so a timer renewal kept serving the old cert until a restart.
  - Updates no longer reset the nightly backup time you set on Backups, and
    no longer re-enable backups you turned off or the DNS glue check you
    dismissed.
  - Retired jobs are removed on update (quota-check was still running hourly
    on older boxes). The table is written atomically.
  - The nightly backup no longer collides with a "Create backup now" run, and
    a second nightly run is skipped while one is still going. Offsite sync
    waits for the nightly backup to finish (up to 4 h) instead of copying a
    half-written set.
  - Malware sweeps can no longer overlap.
- Stats page redesign:
  - Domain picker and Today / 7 / 14 / 30 / 90-day range buttons.
  - Headline tiles: visitors, requests, data served (with ▲▼ change against
    the previous period, shown only when the log reaches back that far),
    share of real people vs bots, server errors, scanner probes, busiest day.
  - Daily chart splits people from bots, plus by-hour and by-weekday charts.
  - Response-code breakdown and visitors' devices, browsers and systems.
  - Top pages (successful page loads only; assets and certificate checks
    left out) and outside referrers.
  - Bots grouped by name (Googlebot, GPTBot, ClaudeBot, scripts…).
  - Scanner probes (.env, wp-login, phpMyAdmin…) in their own list, so
    "Broken links (404)" shows real broken links; server-error (5xx) paths.
  - Heaviest IPs with error and probe counts, flagged when they look like
    scanners.
  - Account usage: bandwidth and disk tiles with quota bars, pace and
    disk-full estimates, 30-day bandwidth and disk charts, bandwidth per
    site this month. Admins see the owner of the domain being viewed, or
    can pick another account.
  - Shows the time span the log actually covers.
  - Parsing fixes: user agents containing escaped quotes are read correctly,
    and the page no longer breaks in private browsing (localStorage guarded).
- Services page redesign: summary tiles (running count, server uptime,
  watchdog, downtime alerts), services grouped by role (Web & PHP, Mail,
  DNS & databases, Files & access, Security & system). Each tile says what
  the service is for and its ports, and shows uptime, memory, tasks and
  crash auto-restarts, refreshed live every 10 s. Actions: Restart (with a
  warning for the web server, MariaDB, Exim and SSH), Reload where the
  service supports it, Start when down, Stop for FTP / spam filter / ClamAV
  only, plus a "Restart everything that's down" button and Log links.
  Settings are grouped into "Monitoring & alerts" and "Panel identity"
  cards. The Fail2ban card is gone (bans live on the Firewall page).
  Restarting ClamAV from the panel works now (it was missing from
  service-control.sh), and on 22.04 spamassassin restarts the right unit.
- DNS page redesign: the wall of zone buttons is now a searchable zone list
  (instant filter, Enter searches every zone, record count per zone,
  subdomains marked with the zone that holds their records). The open zone
  gets a header (records, DNSSEC, nameservers) and tabs for Records, Quick
  setup, Nameservers, Import / reset, Security and Zone file. The tab is
  remembered across saves. Records show colour-coded types, long values
  (DKIM) fold to 3 lines, and on phones each record is a stacked card.
- Dashboard redesign: account tiles (domains/mailboxes/databases/FTP vs
  package limits, disk, bandwidth), a Quick actions grid of the pages used
  most (add domain, File Manager, webmail, phpMyAdmin, SSL, WordPress…) plus
  admin shortcuts (firewall, abuse board, mail queue, logs, update). Admin
  live panel now refreshes every 5 s with real CPU %, network in/out rates
  and sparklines, compact service chips with restart, top processes, and a
  24 h load + memory chart; new "This server's hosting" card (account,
  domain, SSL-coverage, mailbox and database totals + heaviest accounts).
  Domains show SSL days left.
- Accounts page tidy-up: roomier rows with an avatar, username and email
  together, role badges, disk bars; actions trimmed to Login as + one
  Suspend/Unsuspend button + a "⋯" menu (email, role, reset password/2FA,
  delete). Search sits in the header and Create account folds away behind
  "New account". On phones each account becomes a stacked card. You can no
  longer click Suspend/Delete on your own row (the server always refused).
- Abuse board is now live (refreshes every 5 s, pauses in a hidden tab):
  server tiles (load, CPU, RAM, swap, processes, customers over limit),
  per-user CPU/RAM bars with a 5-minute trend line, rows that flash when a
  customer is over the alert limits, and the alert state from the 5-minute
  checker. CPU is measured over the last second instead of ps's lifetime
  average, so a worker that just started spinning shows up immediately.
- Abuse board: process list per customer (command, RAM, running time,
  "pegging a core" / "busy for 2 h" flags) with Kill and Kill all. Kills
  only touch customer accounts and check the process start time, so a
  reused PID can never be hit. Also shows customers' running MariaDB queries.
- Firewall page redesign: status pills, live tiles (banned now / serious /
  bans today / never-ban / open ports), jail filter chips on Recent bans,
  side-by-side Quick actions + Never ban cards, and rules grouped into Open /
  Restricted / Closed / Blocked with service names (SSH, Panel, Mail…).
  IPv6 rules were missing from the table before; IPv4+IPv6 twins now show as
  one row and are deleted together.
- Firewall: "Never ban" list (fail2ban ignoreip) — add an IP or network
  (no wider than /16), applied to every running jail instantly and kept
  across restarts; "Always allow" on a ban row unbans and adds it.
- Firewall rule delete now matches the rule's text instead of its number:
  fail2ban inserts every ban at rule #1, so on a busy box a page loaded a few
  minutes earlier could delete the wrong rule.
- Firewall page: live "Recent bans" card (fail2ban, refreshes every 10 s) —
  when, IP, plain-English reason, the usernames tried and the actual log
  lines that triggered the ban. Serious bans flash red (attacks on the panel
  or phpMyAdmin, an IP banned 3+ times today, or one hitting several
  services); your own IP is marked. One-click Unban lifts a false positive
  from every jail. Wide tables now scroll inside their card instead of
  squeezing the sidebar.
- SSL progress on the Domains page: a row whose certificate is being
  requested in the background shows a spinning "Issuing SSL…" badge, and the
  page reloads itself when the certificate is in place (or shows "SSL failed"
  with the reason on hover). "Issue SSL" clicks show a please-wait overlay
  until Let's Encrypt answers.
- Subdomain SSL no longer fails when another certbot run (a scheduled
  renewal or another domain's request) is busy: it retries for up to 2 min.
  When a request does fail, the Logs page now shows the actual reason
  instead of certbot's generic "Ask for help" line.
  - Backup copies of the cron table go to `/var/lib/karnakpanel/cron-bak/`,
    not `/etc/cron.d/`.
- Redirects moved from the sidebar to a third tab on the Domains page
  (Domains · Subdomains · Redirects); `/redirects` still works.
- Support links (or your branded "Get support" / support email) moved from the
  sidebar to dark pills in the top bar beside search; on phones they sit in
  the 👤 account menu.
- Email page is split into tabs: Mailboxes · Forwarding & rules ·
  Authentication · Server (admin: sending-limit defaults, mail guard, spam
  scanning, SMTP relay). Saving a form brings you back to the same tab, and
  links like `/email#auth` open a tab directly. Connection settings fold into
  a "How to connect a mail app" dropdown. Without JavaScript every section
  still shows on one page.
- Layout: the sidebar is regrouped (Websites · Email · Files & data · Advanced ·
  Clients · Server), highlights the current page, stays put while you scroll
  (with a slim scrollbar that shows on hover), and gains an Abuse board link. Password, 2FA and Log out sit in one
  👤 account menu. On phones, search sits behind 🔍, tables scroll sideways
  and the dashboard tiles are a compact 3-across grid. The dashboard shows
  compact usage bars against your package limits. Tool search also filters the
  sidebar (Enter opens the first match). Small sizes read "3 KB", not "0 MB".
- Creating a subdomain no longer waits for Let's Encrypt: the certificate is
  requested in the background and the 🔒 badge appears on its row when it's
  done (usually within a minute).
- Fix: a slow Let's Encrypt request could hold the page for a minute, report
  failure, yet still issue a certificate the site never used. Requests now
  have a proper time limit that actually stops certbot.
- Fix: deleting a subdomain or an account now always removes its
  certificates, so certbot stops trying to renew certificates for sites that
  are gone.
- "Create backup now" runs in the background: the page returns at once,
  shows "backup running" until it's done, and the backup appears in the list.
  Before, large accounts hit the page's time limit and
  could show a backup in the list that was never written. The API
  `POST /api/backups/create` also starts it in the background now.
- The Email and Disk usage pages read all mailbox sizes in one quick check;
  if the mail server is stuck they show "—" within a few seconds instead of
  waiting 15 s per mailbox.
- Fix: when a panel action hits its time limit, the page now returns at the
  limit instead of waiting for leftover helper processes.
- Phones and narrow screens get a ☰ menu button that opens the sidebar.
- Dashboard "Needs attention" card: expiring/expired SSL certificates and
  domain registrations, accounts near their disk quota, and (admins) stopped
  services, a large or frozen mail queue, and nightly backups that stopped.

## 1.5.38 — 2026-10-18 (supervised apps, suspensions, subdomain mail DNS)
- Security fix: a supervised app (Daemons page) whose command or working
  folder contained a line break could add its own lines to the service
  file and run commands as root. The command is now kept in a root-only
  store, checked again every start, and never written into the service
  file. Apps also get the same link protection as cron jobs. After
  updating, existing apps are moved to the new format automatically; any
  service file that doesn't match what the panel wrote is stopped and
  removed (the update log names it).
- Security fix: suspending an account that had no password left its
  cron jobs and apps running. Suspension is now recorded separately and
  checked by both; existing suspended accounts are marked on update.
- Security fix: database passwords no longer appear in the process list
  while backups and restores run, and several system scripts check their
  input and refuse symbolic links more strictly.
- Fix: mail on subdomains. A subdomain shares its parent's DNS zone, but
  its SPF, DMARC, MTA-STS and TLS report records were saved where no zone
  published them. They now go into the parent zone under the subdomain's
  name. The first mailbox, forwarder or list on a subdomain adds its MX
  and SPF records (only when missing), turning on DKIM publishes the key
  when the zone is hosted here, and a subdomain without its own DMARC
  record shows as covered by the parent's. The DNS page offers the mail
  presets (Google, Microsoft 365, this server, DMARC) for subdomains.
- After updating: records saved in the old place are moved into the
  parent zone, and DKIM keys that were turned on but never published in
  a zone hosted here are published (mail from those domains failed DKIM
  until now). A different key already published is reported, never
  replaced.
- Deleting a subdomain is refused while it still has mailboxes,
  forwarders or lists, and now removes all of its records from the parent
  zone, not just its address.
- Fix: backups, database backups and the update check could report a
  failure when they had succeeded; the PrestaShop installer was broken.

## 1.5.37 — 2026-10-17 (account create: never take over an existing system user)
- Security fix: creating an account with the name of a user that already
  existed on the server (for example "ubuntu", "debian" or "syslog" on
  cloud images) took that user over: it kept its login shell and its
  sudo/admin groups, was given the customer's password and was added to
  the FTP allow-list. Creating an account now checks first and refuses
  any name that already exists on the server, before anything is changed.
- Fix: an account is now created completely or not at all. Before, the
  panel said "created" and kept the account even when the server setup
  failed or timed out. Now a failed setup removes what it made and shows
  the reason; billing systems calling the API get an error instead of
  "ok" (409 when the name is taken or already exists on the server).
- Names used by Linux distributions and cloud images (ubuntu, debian,
  centos, ec2-user, sudo, wheel, staff and similar) can no longer be
  chosen for new accounts. Existing accounts are not affected.
- Password changes and account deletion refuse to touch a system user
  (low user id, a login shell, or membership of sudo/admin/adm/wheel and
  similar groups). Accounts that 1.5.36's group repair hasn't fixed yet
  may refuse a password change until it has run.

## 1.5.36 — 2026-10-17 (security: passwords out of logs, group fix; password + 2FA resets)
- Security fix: passwords were written in plain text to the server's
  auth log (/var/log/auth.log, the systemd journal) and to the panel log
  whenever an account, FTP login, database or app install was created or
  had its password changed. Passwords now travel privately to the system
  scripts and never appear in a log or a process list. Passwords set
  before this update may already be in those logs: change any that
  matter (accounts, FTP logins, app admin logins).
- Security fix: on Ubuntu, an account named "admin" (or any name that
  matches a built-in system group) had its files, its site's PHP and its
  FTP logins placed in that system group. On Ubuntu the "admin" group
  can use sudo. It also broke the File Manager: files uploaded by FTP
  could not be edited ("Permission denied"). Ownership now always uses
  the account's own group.
- After updating: affected accounts are repaired automatically (files,
  PHP settings and FTP logins moved to the account's own group, and the
  account removed from the system group). Nothing changes on boxes
  without such an account.
- New: Accounts → 🔑 Reset PW. Admins (any account) and resellers (their
  own customers) can set a new random password, shown once. It changes the
  panel login and the FTP/system password, and logs the user out.
- New: Accounts → 📵 Reset 2FA, for users who lost their authenticator.
  Switches their two-factor off and logs them out; they log in with their
  password and set it up again. Admins any account, resellers their own
  customers.

## 1.5.35 — 2026-10-17 (database user limits)
- Fix: busy sites stopped with HTTP 500 and MariaDB error 1226 ("has
  exceeded the 'max_connections_per_hour' resource"). Every database user
  was capped at 360 connections an hour, and every PHP page view opens a
  connection, so one view every 10 seconds was enough to hit it.
- New defaults per database user: 36,000 connections an hour (10 a
  second), 1,000,000 queries and 100,000 updates an hour, 25 at once.
- New: admins can change these on the Databases page (Database user
  limits; 0 = no limit), and optionally apply them to all existing
  database users. The panel, the API, the WordPress / phpBB / Flarum /
  Vvveb / PrestaShop installers and the cPanel / Hestia / panel-transfer
  importers all use the same settings.
- After updating: database users that still have the old caps are raised
  to the new ones automatically. Users whose limits you changed by hand
  are left alone.

## 1.5.34 — 2026-10-16 (usage alerts, reseller branding, DNS nameservers)
- Before you update: set Services → Panel host if it is empty. Usage-alert
  emails are sent from that domain; without it they fall back to the
  server's provider hostname, which spam filters dislike.
- After updating: the first check (within 5 minutes) emails every customer
  who is already above 80% of their disk or monthly bandwidth, all at once.
- New: usage alerts at 80% and 95% of disk space and monthly bandwidth,
  before the 100% limits lock FTP / take sites offline or suspend the
  account. The customer gets a panel notification and an email; their
  reseller gets a notification. One alert per level; it re-arms below 75%,
  and bandwidth alerts reset each month. Admin accounts are not alerted.
- New: reseller branding (Reseller Center → Branding): panel name, logo,
  accent colour, support link and support email, shown to the reseller and
  all of their clients. Branded clients see the reseller's support link in
  the sidebar instead of the KarnakPanel / HostBMS links. The login page,
  webmail and system emails (other than usage alerts) keep the standard
  look. Admins set a reseller's branding by logging in as that reseller.
- New: a small "© KarnakPanel.com" line at the bottom of the sidebar.
- DNS: new zones use the server's nameservers (ns1/ns2 of the panel
  domain) instead of their own ns1/ns2.<domain>, so customer domains no
  longer each need registrar glue. Existing zones keep what they have; the
  DNS page offers "Use server nameservers" to switch. Reseller white-label
  nameservers are unchanged.
- DNS: a panel hostname like panel.example.com no longer gets a zone of its
  own; its records belong in example.com's zone (seeded when you add
  example.com). The installer prints the records to create.
- DNS page: delegation check, quick-setup templates (Google, Microsoft 365,
  local mail, DMARC, CAA), paste-import of zone text, zone export, type
  filter and wildcard hosts.
- Packages: edit and delete plans; resellers only see their own plans.
- Disk: the admin account shows as unlimited (it was never limited, but
  showed "4.88 GB"). New default disk quota is 5 GB (5120 MB, was 5000 MB);
  existing accounts and plans keep their numbers.
- Sessions page lists only logged-in sessions, with a count of pre-login
  visits (these showed as "unknown" before).
- Fix: domain names with digits or hyphens past the first label (like
  ns1.my-brand.com) were refused.
- Fix: deleting a subdomain that was first added as its own domain (before
  its parent) now removes its old DNS zone too, instead of leaving it behind.

## 1.5.33 — 2026-10-16 (HestiaCP import + account isolation)
- New: HestiaCP import on the Migrate page. It brings over everything one
  Hestia user owns into an existing panel user: root SSH (we run
  `v-backup-user`, copy the backup, delete the remote copy) or an uploaded
  backup `.tar`.
  - Every web and mail domain is created. Subdomains become records in
    their parent zone. Site files, .htaccess rewrites (converted for OLS)
    and the PHP version come along.
  - MySQL databases keep their names, users and (MariaDB) passwords.
    Mailboxes keep their passwords where Dovecot can read the hash, plus
    all folders. Aliases, forward-only boxes and catch-alls become
    forwarders. Auto-replies come along.
  - Custom DNS records are imported and moved off the old server's IP.
    Cron jobs arrive paused for review.
  - Report only (Logs → software): PostgreSQL DBs, forward-and-keep-copy
    boxes, private/ dirs, files hard-coding old paths, SSL to reissue.
- Domains and subdomains share one creation path (`DomainProvisioner`).
- Fix: a CAA record could break its whole DNS zone. The value was written
  with doubled quotes, so BIND refused the zone and no record in it updated.
- Fix: after BIND refused a zone, DNS saves for that domain were blocked
  for 10 minutes. The refused copy is now always cleaned up.
- Fix: the "coming soon" placeholder page no longer reappears next to a
  site's index.php when rewrite rules are saved.
- Security: hosting accounts can no longer read each other's files. Cron
  jobs run code as the account, so file permissions (not open_basedir) are
  what separate accounts.
  - New `home-perms.sh` closes everything inside a home to other accounts
    while keeping access for the web server and the panel. It runs on
    account create, domain add, restore, imports, update and daily.
  - PHP and cron temp files go to a private `~/.tmp` per account, not the
    shared /tmp (which stays allowed for apps that hard-code it).
  - Cron job commands no longer show in the process list of other accounts
    (they can carry passwords).
  - /var/log/karnakpanel and its logs are closed to hosting accounts.
  - File manager chmod keeps "other" off (644 becomes 640); the site still
    serves the file.
  - Account backups skip `~/.tmp`.
- Security: Linux accounts (hosting accounts, root) can no longer log in to
  IMAP or send mail over SMTP AUTH. Dovecot used to fall back to system
  logins when a mailbox login failed. Mailbox logins are unchanged; existing
  servers are patched on update.
- Panel transfer: the source's cron table is now merged into the new
  server's (shipped lines only) instead of copied over it.
- Security: hosting accounts can no longer create symlinks, which closes a
  cross-account read. The web server reads every home as one user, so one
  account could link to another account's files (such as its
  wp-config.php) and fetch them through its own site.
  - PHP (all FPM versions) and cron jobs get EPERM on symlink creation.
  - Customer sites only serve a file symlink when the link and its target
    have the same owner (OLS checks the last path component only, so the
    creation block is the real fix).
  - Cron jobs now run from /etc/cron.d through a root launcher that drops
    to the account, so existing jobs are moved automatically on update.
    Jobs of suspended accounts no longer run.
  - The rewrite rules editor refuses T=/H= flags, which could serve PHP
    source as plain text.
  - New read-only `symlink-scan.sh` lists existing cross-account links.
  Root over SSH is not affected.
- The file editor is now a code editor (CodeMirror 5, bundled with the
  panel). It has syntax highlighting chosen by file type, line numbers,
  bracket and tag matching, auto-closing, find and replace, go to line,
  Ctrl+/ to comment, wrap and font size, and Ctrl+S to save. Plain
  .html/.htm files also get a Visual mode with a formatting toolbar. The
  page is shown in a sandboxed frame, so its scripts, forms and redirects
  never run in the panel. Visual mode is not offered for files with
  PHP/SSI/template tags.
- Fix: saving in the editor no longer turns LF line endings into CRLF.
  Browsers send textarea text with CRLF, so a shell script edited in the
  panel could stop running ("bash\r"). Each file now keeps its own line
  endings.
- Subdomains now have rewrite rules. Each row on the Subdomains tab has a 📝
  button that opens the same editor as on the Domains tab, including the
  docroot .htaccess import. A 📝 rewrites tag shows which subdomains have
  custom rules, and marks them as stale if their .htaccess has changed since
  it was imported.
- The API Tokens page has a card about HostBMS.com, a free billing system
  with a full working module for managing KarnakPanel. A token's 📍 pinned-IP tag now
  uses the normal tag style, the same as "any host".
- The file manager has had a big upgrade:
  - Dotfiles such as .htaccess, .user.ini and .env can now be created,
    edited and renamed. A "show hidden" switch shows or hides them. Names
    can also contain spaces and ()+@,~.
  - Delete now moves items to a 🗑 Trash, where they can be restored or
    deleted for good. Items in the trash are deleted automatically after 30
    days. There is also a "Delete permanently" option for when you are over
    your disk quota.
  - Copy, cut and paste work across folders.
  - You can drag and drop several files at once. Each file has a progress
    bar, and big files are sent in parts, so the per-request upload limit no
    longer caps the file size.
  - The file manager can now extract .tar, .tar.gz, .tar.bz2 and .tar.xz
    archives as well as .zip, and can pack a selection as .zip or .tar.gz.
  - Other additions: breadcrumbs, sortable columns, readable sizes, a
    permissions and owner column, a search through subfolders, and an image
    preview.
- Security: the file manager scripts are hardened.
  - Extracting and packing now run as the account's own user, not root.
  - Archives with symlinks, `..` paths or more data than the remaining quota
    are refused.
  - Extracting no longer resets every file in the folder to 644, which used
    to undo a 600 on wp-config.php.
  - Live site folders can't be renamed or moved.
  - The panel's own folders (.ssh, .php-sessions, .trash) are protected.

## 1.5.32 — 2026-10-15 (PHP refresh: 8.3/8.4/8.5, panel on 8.5)
**Admins:** sites still on PHP 8.1 keep running until you move them. The PHP
page lists them, with a button to move them all to 8.3, and 8.1 is removed
from the server automatically once nothing uses it. Sites on 8.2 keep
working until 31 Dec 2026, but move them to 8.3 or later before then.
- PrestaShop (which needs PHP 8.2) can now only be installed on a domain that
  is already on 8.2. The Software page explains why it's unavailable
  otherwise, instead of asking you to switch to a version you can't pick.
- New subdomains get an SSL certificate. If the parent domain already has
  one, the panel requests a certificate for the subdomain when you create it.
  The Subdomains tab now has an SSL column, with an Issue SSL button for any
  subdomain that doesn't have one yet (for example, while DNS is still
  settling). Deleting a subdomain also removes its certificate.
- Deleting an account now removes everything that belonged to its domains
  in the panel database: app installs, parked domains, mail forwarders,
  filters, mailing lists, mail scores and preview links. Before, those were
  only removed when you deleted a single domain. Parked domains are also
  detached from the web server and DNS first.
- Deleting a domain's certificate now also removes it from the mail server's
  per-domain certificate list. Deleted accounts no longer leave their PHP
  error log behind for the next account to inherit.
- Panel logins no longer drop after the panel's PHP version changes, and old
  panel sessions are cleaned up. PHP 8.5 for the panel had no session folder
  set, so sessions went to /tmp and were never deleted.
- Removing PHP 8.1 no longer switches off database, curl, intl and opcache
  support for the other PHP versions. A LiteSpeed packaging fault did this
  when 8.1 was removed, and it only showed after the next PHP restart.
  Removal now runs in a safe order, checks every remaining version's
  modules, and puts back anything that changed.
- PHP refresh: customer sites can now use PHP 8.3 (the default), 8.4 or
  8.5. PHP 8.2 stays for sites already on it until its end of life
  (31 Dec 2026) but isn't offered for new ones; PHP 8.1 is removed. Updating
  installs the new versions, and removes 8.1 only once no site, plan or
  config on the server uses it. Until then, the PHP page shows admins which
  accounts are still on it, with a button to move them all to 8.3. No site
  changes version on its own.
- The panel itself now runs on PHP 8.5. The update checks that the panel
  still answers after the switch and puts the old version back if it doesn't.
- Switching a site's PHP version no longer briefly takes down every other
  site on the new version. The old version now lets go of the account's
  connection before the new one starts; the switched site itself is only
  unavailable for under a second.
- Fresh installs: sending mail from a mail client (ports 587/465) works
  straight away. Before, the mail server couldn't read its certificate
  until its first restart and refused encrypted connections.
- Installer: a failed step now says which one failed and where the log is;
  a failed ClamAV step (e.g. too little RAM) no longer stops the whole
  install; the final health check also covers webmail, the Dovecot config
  and the mail-filter delivery step.
- Mail filters now work. A mailbox with an active filter did not
  receive mail, because the mail server config was missing the step that
  hands filtered mail to the filtering engine. Updating fixes the config
  automatically.
- New installs can now use Debian 13 as well as Ubuntu 24.04 LTS. Same
  stack and features; PHP comes from the Debian PHP repository
  (packages.sury.org, signing key pinned in the installer), and the mail
  server config uses Dovecot 2.4 syntax there. Automatic Debian security
  updates are switched on if the image didn't have them.
- Webmail now runs the current upstream Roundcube release (1.7.4) instead
  of Ubuntu's package, which gets no free security updates (22.04's has
  never been patched). The download is checked against a pinned checksum
  and Roundcube's signature; logins, mail folders and settings carry over,
  and the database is backed up first. Every update re-checks it, and if the
  new webmail fails its health check the old one is restored. Webmail
  security fixes now arrive as KarnakPanel updates.
- New installs now need Ubuntu 24.04 LTS. Ubuntu 22.04 is refused for
  fresh installs: it reaches end-of-life in April 2027 and its webmail
  (Roundcube) package gets no free security updates. Existing 22.04 panels
  keep receiving KarnakPanel updates and can re-run the installer, and
  their dashboard now shows a notice to move to a 24.04 server
  (Admin -> Migrate).
- Mail to a non-existent address at a hosted domain is now refused while
  the sender is still connected, instead of being accepted and bounced
  later. This stops the server mailing bounces to forged senders
  (backscatter), which gets servers blocklisted.
  A forwarder whose target domain no longer exists now refuses mail the
  same way — check old forwarders if senders report rejections.
- postmaster@ and abuse@ always reach someone: on a domain without its own
  mailbox, forwarder or catch-all for them, they are delivered to the
  domain's first-created mailbox. Create postmaster@/abuse@ (or a forwarder)
  to take them over.
- Mail guard (Email page, admin switch, on by default): a mailbox that looks
  hijacked — sending from many networks, hammering its hourly cap, or
  causing mass bounces — has its outgoing mail held automatically. Its
  queued mail is frozen for review, and the owner and admin are alerted.
  Reset PW on the mailbox restores sending; an admin can release a false
  alarm.
- Fixed: abuse/egress cron notifications could fail on unusual text;
  fail2ban status checks no longer show as errors in the panel log.

## 1.5.31 — 2026-10-15 (mail security fix + deliverability autopilot)
- Security update — recommended for all installs. Mail sent to
  <name>@<a hosted domain> with no mailbox or catch-all there could be
  delivered to the server account that happened to be called <name>, even
  if that account belongs to another customer. Hosted domains now only
  deliver to their own mailboxes, forwarders, lists and catch-alls; the
  update fixes existing servers automatically.
- Action needed: check the Alert email on Admin → Services (it starts as
  the email given at install) is a real mailbox, forwarder or outside
  address, then press Save. Server mail (cron output, alerts) is forwarded
  there; a hosted address with no mailbox used to fall through to the
  admin account's server folder and is now rejected.
- New accounts can no longer be called postmaster, abuse, hostmaster,
  webmaster or security. Existing accounts with these names keep working.
- MTA-STS and TLS reporting: publish a policy in testing mode, then enforce
  once the policy fetch and mail-server certificates check out.
- DMARC and TLS reports: aggregate reports sent to your domains are
  collected and shown per domain — who sends as you, and how often they pass.
- Deliverability autopilot: once enough reports show your real mail
  passing, the panel suggests the next step (DMARC none → quarantine →
  reject, MTA-STS testing → enforce). You click each step; nothing changes
  on its own. Alerts for new failing senders, TLS failures and steps that
  are ready.

## 1.5.30 — 2026-10-14 (subdomain tabs + cron reliability)
- Domains page now has Domains | Subdomains tabs: top-level domains on one
  tab, subdomains (with click-to-open links) on the other. The separate
  Subdomains menu entry is gone — everything lives under Domains.
- The panel's own domain row now records the full site path, matching every
  other domain (upgraders are healed automatically on update).
- Cron tables are now keyed by command, so entries like certbot can no longer
  be refused as duplicates or clobbered by re-renders.

## 1.5.29 — 2026-10-14 (subdomains + redirects)
- Subdomains page (📂): create a subdomain under one of your domains. It
  gets its own site and docroot, and an A record in the parent zone. Removal
  refuses while the docroot still holds files, so a click can't wipe a site.
- Redirects page (↪️): 301/302 redirects from a path to a full URL, per
  domain. Stored with the same rewrite engine as imported .htaccess rules.

## 1.5.28 — 2026-10-14 (security update + installer hardening)
- Security update — recommended for all installs. Includes all 1.5.27
  hardening plus tighter handling of install-time files. After updating,
  change the admin password if you still use the one shown at install.
- Installer: the release is now checked against a signature from the
  KarnakPanel build host before anything runs; SSH keys are only installed
  for root when you supply or confirm them; firewall follows your real SSH
  port; the OpenLiteSpeed admin console (:7080) is closed by default (SSH
  tunnel, or KARNAK_ADMIN_IP to allow one address); a health check runs at
  the end; unattended installs via KARNAK_ACCEPT_LICENSE=1.
- Mail is IMAP-only: POP3 ports (110/995) are closed in the firewall.
- The server's `php` command now points at PHP 8.3 (it could drift to a
  newer build missing extensions). Cron jobs that call plain `php` run on
  8.3 — pin a version (`php8.2 ...`) if a job needs another.

## 1.5.27 — 2026-10-14 (security update + Fleet Updates phase 2)
- Security update — recommended for all installs. Hardens file
  permissions, credential handling, login and two-factor protection, and
  service isolation. Please update promptly.
- Fleet Updates phase 2: one-click WordPress core/plugin updates (per site
  or "update all safe") with a restore point taken first and automatic
  rollback on failure; optional nightly schedule (admin sets the hour).
- Databases: leaving the password blank generates a random one; custom
  passwords must be 8+ characters.
- API tokens: optional expiry (30/90/365 days; default never) and a
  last-used time + IP per token.
- Admin > Services: the Fail2ban card shows live jail/ban status.

## 1.5.26 — 2026-10-14 (Fleet Updates v1)
- New reseller/admin-only Fleet Updates page: nightly scan of every
  panel-installed WordPress (live core vs wordpress.org, plugin count,
  status pills), strictly owner-scoped for resellers. Read-only in v1 —
  one-click updates with restore points follow in phase 2.

## 1.5.25 — 2026-10-14 (UI batch + installer)
- Email authentication: DMARC column plus a registrar copy-block (SPF /
  DKIM / DMARC values) for domains on external nameservers.
- Cron jobs: pinned-PHP examples and a save-time nudge against bare `php`.
- Sidebar HostBMS button under Support. Installer ships bcmath on all FPM
  pools.

## 1.5.24 — 2026-10-14 (explicit routing for every name)
- New domains get explicit listener maps for apex + www + mail on both
  :80 and :443 (alias-only routing proved unreliable for new names on
  prod). ACME challenge dir created with worker traversal from day one.
  ssl-issue ensures the same maps before challenging and always passes
  --expand (adding a SAN to an existing cert failed without it).

## 1.5.23 — 2026-10-14 (per-domain mail TLS)
- Mail daemons served only the box cert (Thunderbird mismatch). Now:
  mail-sni.sh maps every live LE cert by SAN (Dovecot local_name blocks +
  Exim allowlist lookups — taint-safe, unknown SNI falls back to the box
  cert), issuance adds a mail.<domain> SAN like www, renewals re-converge
  via deploy-hook. Proven live: per-host certs on 993/465/587, fallback
  intact, local delivery unaffected.

## 1.5.22 — 2026-10-14 (rewrite awareness)
- Domains table shows rewrite pills: which sites run custom rules, and a
  stale warning when the docroot .htaccess changed since import (hand saves
  clear tracking — the editor is then the source of truth). Same flags in
  the API domain list.

## 1.5.21 — 2026-10-14 (SSL preservation)
- Re-rendering a vhost (rewrite syncs, re-runs) no longer strips its Let's
  Encrypt block or force-HTTPS rules: existing vhssl + force-https stanzas
  are carried over (cert files must still exist, else dropped loudly).
  Found because a rewrite render left a prod subdomain on the shared cert.

## 1.5.20 — 2026-10-14 (.htaccess parity for OpenLiteSpeed)
- OLS ignores .htaccess, so projects depending on it broke on arrival.
  Domains page now has a rewrite-rules editor (RewriteCond/RewriteRule
  only — anything else is refused, live site keeps serving) plus a
  one-click .htaccess importer that converts what OLS understands and
  reports every skipped line with its reason (php_* -> PHP page, Auth ->
  forthcoming Directory Privacy, etc.). Same actions in the API.
- Hard lessons baked in: custom rules splice byte-verbatim (a shell-escape
  dance silently killed every $), OLS matches vhost-level without the
  leading slash (proven live both ways), mysql CLI escaping needs --raw
  for multi-line values (new gotcha).

## 1.5.19 — 2026-10-14 (full resource API)
- Token API grows from provisioning-only to full resource coverage, same
  auth model (SHA-256 tokens, IP pins, admin/reseller/user scoping):
  domains list/create/delete, DNS records list/add/update/delete (same
  validation + BIND poison-rollback as the panel), mailboxes + forwarders,
  databases list/create/delete/reset (namespaced, password-once), backups
  list/create, SSL issue. Every endpoint proven live end to end.

## 1.5.18 — 2026-10-13 (security fix-forward)
- File-manager containment + backup IDOR gate re-shipped (the 1.5.16/17
  tarballs carried pre-fix PHP after a partial stage + snapshot-dance
  revert — verified fixed in this build). nosniff on file downloads.
  Local Semgrep p/php pack: zero findings.

## 1.5.17 — 2026-10-13 (usage graphs)
- Stats page gains a per-account card: 30-day bandwidth + disk bars against
  quotas, month-pace projection and a burn-down hint ("full in ~Nd", shown
  only with a real positive trend). New disk_daily history (migration 039,
  collector hook in the hourly run — no new cron), admin ?user= inspection.

## 1.5.16 — 2026-10-13 (restic backup engine, opt-in)
- Incremental per-account snapshots (restic 0.19.1, pinned + checksummed)
  replace full nightly tarballs when enabled: files + own-DB snapshots with
  account tags, 14 daily / 8 weekly / 6 monthly pins, weekly integrity
  check, monthly restore proof, per-box toggle on the Backups page.
- Snapshot browser (own-scope), DB restore and file download from snapshots
  — both re-verify ownership (snapshot tag + resource row).
- Nightly engine dispatch inside backup-all (tarballs resume when off);
  restic offsite mirrors the repo beside the tarball mirror. Offsite honesty:
  the UI states mirrors are not append-only.

## 1.5.15 — 2026-10-13 (security)
- File-manager containment rebuilt (Semgrep AI Highs, confirmed real): the
  old realpath()+prefix check passed sibling-prefix homes and followed
  symlinks, with the panel (nobody + read ACLs) and root scripts acting as
  confused deputies. Now: per-component lstat walk in PHP
  (Helpers::fmResolve) and all nine file-*.sh scripts — symlinks refused
  anywhere in the path, trailing-slash boundary enforced. Proven on test:
  7/7 script attacks + panel download/rename/delete/chmod/edit refused,
  victim byte-intact, legit ops unaffected.
- Backup DB restore gated on dump membership (guessing another tenant's
  dump filename now stops at "Not your dump").
- Semgrep mediums: justified suppressions with reasons (PDO placeholders,
  static route dispatch, JSON_HEX, random-hex stage paths, sanitized zone
  reads, owned-install argv) — no behavior change.

## 1.5.14 — 2026-10-13 (installer-only; panel code is 1.5.12)
- Fixed fresh installs shipping a dead Exim: the relay commit fused a
  comment into an authenticator name (one-line typo, every install since
  1.5.11). Existing boxes were never affected (self-update doesn't touch
  the mail config). The installer now refuses to continue if the rendered
  mail config doesn't parse.

## 1.5.13 — 2026-10-13 (installer-only; panel code is 1.5.12)
- Fixed fresh installs FATALing with "no install candidate" on healthy
  boxes: `apt-cache | grep -q` under `pipefail` is a SIGPIPE trap (grep
  exits on first match, apt-cache dies writing the rest, pipeline fails).
  All candidate checks now capture-then-grep — proven on the failing box.
- Installer survives hostile networks: direct-source PPA fallback (vendored
  key) when the Launchpad API is filtered (Vultr), race-free candidate
  checks (every apt update fires apt-news in the background — same-second
  reads can come back empty on a good box), proven by simulation.
- Services page gains the Panel-host setting — the checklist glue step had
  no way to be set, so it sat at TODO forever even with correct glue.
- SMTP card layout: send-test form on its own row (was overlapping Save).
- SMTP relay (smarthost) for port-25-blocked networks, with test-mail proof,
  file-only secrets and self-installing Exim stanzas for upgraders.
- cPanel migration completes: sub/addon domains (rows, vhosts, zones, files,
  mail), autoresponders, user filters, MD5-crypt passwords — all proven
  against a real cPanel backup end to end.
- Setup checklist honesty: panel host excluded, glue step gated on a nightly
  probe, dismiss retires its cron, re-show button brings it all back.
- Cron audit: dead quota-check line gone, 5-minute jobs staggered, downloads
  telemetry moved to unshipped prod-only tooling.
- Installer PPA add retries with clear guidance instead of a traceback.

## 1.5.10 — 2026-10-12
- 22.04 installer fix: the spam filter unit is `spamassassin.service` there
  (`spamd.service` only on 24.04+) — installer, watchdog and Services page
  now resolve the right name per box instead of assuming.
- All Semgrep reds cleared: LIMIT/OFFSET bound as integers (21 sites),
  installer provisions the admin account (no seed credential in the repo),
  unknown-script gate in ServiceManager, documented suppressions only where
  the scanner is wrong.
- Notify paths log failures instead of swallowing them (12 scripts).
- 2FA code page stacks field above the button.

## 1.5.9 — 2026-10-11
- Per-domain mail handling (Email Routing equivalent): Local ↔ Remote
  toggle per domain — remote routes inbound by public MX while local
  mailboxes, forwarders, lists and autoresponders go dormant and reactivate
  on toggle-back; mailbox creation into remote domains refused with guidance.
- Disk usage viewer: per-account breakdown (site files, mailboxes,
  databases) with click-to-drill-down folders, 5-minute cached refresh, and
  quota header/sidebar now read live instead of login-time numbers.
- Quota enforcer hardened: single-flight lock, idle-priority measurement,
  per-user timeout (slow trees skip, never zero), pass-duration logging.
- Fixed silently-dead notifications: quota and backup completion alerts
  never fired (ambiguous SQL column); both deliver now.
- Small UI: duplicate webmail button removed, remote-mail pill neutral,
  short DKIM flash (full key lives in the auth table).

## 1.5.8 — 2026-10-11
- Security sweep fixes: TOTP second factor throttled + alarmed, backup
  dumps scoped per tenant, file-manager XSS closed (HEX-encoded sinks +
  path gate), login CSRF, logout via POST, impersonation-return auth,
  generic DB-down message, bcrypt timing parity, /tmp hardening, dead
  DNS route removed, installer stops opening UFW 9000.
- Mail queue details: per-message stuck reasons, spool totals, failing
  destinations, next-retry times on inspection.
- DNS zones mirror template rows into the database (no more empty DNS
  pages or glue-dropping re-renders).
- UI consistency: neutral pills unless a problem, domlink site links,
  wrapping domain tables, no-wrap badges, clipped docroots with hover,
  spaced action buttons, setup checklist pills, favicon, severity glyphs
  for color-blind readability, update-delay note.

## 1.5.7 — 2026-10-11
- App Toolkit: one-click WordPress staging clones (password-locked, mail
  gated, hidden from search) with refresh, files-only or full-DB push
  (divergence warning + automatic restore points), snapshot restore and
  destroy.
- Mail queue browser: paginated per-message list with sender-auth badges,
  per-message view/retry/delete, search filter, plus recent delivery
  outcomes (delivered/bounced/deferred).
- Deliverability scorer honors DMARC org-domain inheritance (compliant
  subdomains stop scoring 0; sp= overrides respected).
- Single-message mail retry uses requeue (forced delivery hung web requests
  on dead routes); staging-aware vhost realm lock; installer-side NS glue
  repair for zone re-renders.

## 1.5.6 — 2026-10-11
- Updater purges staged installers for installed-or-older releases (the
  Update page no longer offers the just-installed version).
- Installer welcome: clear screen, ASCII logo, server specs + public IP.
- Installer placeholder guard: pasting panel.example.com / admin@example.com
  now prompts for the real domain + email (fresh installs).
- Header theme-toggle button outline fix.

## 1.5.5 — 2026-10-11
- Admin role changes with guards (last-admin/self/client-holding refusals,
  live sessions killed) — audited: no power contamination.
- Seed plans renamed Bronze/Silver/Gold (pristine-only migration 035).
- Installer licence gate + intro, header dark pills.

## 1.5.4 — 2026-10-11
- cPanel remote + root import with live progress (proven live vs 11.138).
- Per-account PHP truth (switch/create/delete converge, forum stranding over).
- One-click client plan changes with allocation + downgrade guards.
- UI polish batch: header text links, version pills, FTPS explainer, backup
  MB, domlink site links, disk into Server card, no-shift live stats, live
  version line, autofill theming, Migrate credential attrs.
- Installer: empty-domain refusal, release-upgrade block, update state hooks,
  app HTTPS sync on issuance, abuse snapshot self-exclusion.

## 1.5.3 — 2026-10-11
- cPanel remote import (no root: host + login + API token) and root import
  (SSH pkgacct + scp), both with live progress on the Migrate page. Proven
  against real cPanel 11.138: token fallback (password UAPI 401s), FTP
  directory-watch (list_backups lies without a backup dir), generic archive
  wrapper detection, FTPS-first/plain fallback, connect timeouts.
- Importer learns modern pkgacct layout + scaffold-tolerant empty checks.
- UI batch: header action tints + admin gradient, neutral pills (accounts,
  pools + versions, backup schedule, update live/OK), backup sizes in MB,
  domlink site links, disk merged into Server card, no-shift live stats.
- Installer: empty-domain refusal, release-upgrade MOTD + command block,
  pool-preserving account repairs, update banner state refresh.

## 1.5.2 — 2026-10-11
- PrestaShop one-click (8.2.8, pinned + checksummed, full CLI install with
  back-office URL reported; rate caps lifted for the run, re-applied after).
- Forum outage fixed + prevented: domain saves no longer rebuild (and
  downgrade) existing FPM pools — deliberate moves stay on the PHP page.
- Installer: empty domain refuses fast, localhost email warns loud;
  release-upgrade MOTD + command blocked (pin + cache clear + refusal shim).
- Update/rollback now refresh the available-update banner state on completion.
- UI: neutral pills (services, accounts, pools, backup schedule), backup
  sizes in MB, domlink site links (https-aware), disk merged into Server card.

## 1.5.1 — 2026-10-11
- Installer: the panel-domain prompt now shows on the terminal (it was
  hidden in the log file, looking frozen), and the launch email is written
  into the admin account — fresh installs can Issue SSL immediately instead
  of hitting the admin@localhost refusal.
- Accounts page: per-row email edit (the SSL error pointed here but no
  edit existed). Same ACME-grade validation as Issue-SSL.
- Issue-SSL covers www only when it resolves here (public DNS) — apex-only
  setups no longer fail the whole issuance on a missing www record.

## 1.5.0 — 2026-10-10
- Interface overhaul: status pills everywhere (color only when attention
  needed), grouped sidebar, SSL merged into Domains (per-row issue with
  busy state), services cert on admin Services, Migrate hub page,
  autocomplete fixed panel-wide, expiry markers, live dashboard stats.
- One-click updates: fetch latest signed release + staged one-click
  install (manual always, never automatic).
- Installer: panel-domain onboarding (zone+vhost+LE when DNS resolves,
  interactive retry loop), root mail + alert email seeded, vsftpd TLS
  preserved, migration tracking seeded.
- Safety: watchdog confirm-before-restart, admin suspend lockout guards,
  explicit second-admin creation.
- Stats: searchable picker, remembered domain, daily trend, top IPs, 404s.
- Ops: download stats + weekly digest, release-check gate.
- Fixes: FTP stomp-proof homes, reseller labels + units.
- First-update repair (found by drill): fresh installs record bundled
  migrations as applied, so the first self-update no longer replays and
  aborts; 029 re-runnable, 017 marked single-run. Update + rollback cycle
  proven end-to-end (code, database, health check).
- Fresh-install repairs (proven on bare 24.04): fail2ban ships complete
  (weblogin filter + port-scoped action + placeholder log so protection
  starts with zero domains), passwords set pre-hashed (provider-image
  quality rules no longer break them), mailboxes arrive with Maildir
  folders, homes grant the shared group list rights.
- FTP fixed properly: suffix logins share the home group (list + write
  work), `local_umask=007` keeps uploads readable by site + manager,
  domain trees grant owning-group rights, TLS-only pinned to LE cert
  (`ftp-tls.sh`), installer preserves TLS across re-runs.
- Security batch: per-domain bot shield (default on, toggleable) +
  login-flood fail2ban jail (port-scoped bans) + breached-password check
  (HaveIBeenPwned k-anonymity) on all password sets + upload webshell
  block + upload-time antivirus + daemon/cron denylists.
- Full security sweep: 132 POST routes, all queries, all views, shell
  handling — clean.
- WHMCS integration: provisioning API (create/suspend/terminate/password/
  usage/packages) + server module + IP-pinned tokens + setup card.
- Server transfer tools: version-gated two-phase export/import (proven).
- File manager: readable editor (size, find/replace, go-to-line, Ln/Col),
  multi-select (delete/zip/download), rename, filter, HTML edits as code.
- Dashboard live stats (15s) + abuse-board refresh + persistent 2FA nudge
  + scannable 2FA QR + reordered sidebar.
- Mail: SPF hard-fail + mail-score strictness hint.
- Licence: proprietary source-available terms (LICENSE).
- Fixes: pool flood wedge, clamd stream-scan on LXC, zip binary in
  installer deps.
- Update-available banners (dashboard + update page, persistent until
  installed, red for security releases) with daily probe.
- Flood fix: FPM pools no longer wedge under load (per-request backend
  connections, queue tuning); pool exhaustion proven graceful.
- Search everywhere: accounts, domains, mailboxes, SSL, DNS zones + records.
- First-run setup checklist (self-ticking) + server-disk card on dashboard +
  per-user usage columns on Accounts (cached, migration 030).
- Mail: client autoconfig (Thunderbird + Outlook), daemons on LE certs with
  renew hook, sectioned DB restores fixed (proven by drill).
- Backups: schedule control (time/disable/enable), auto-registration of
  nightly runs, account delete clears snapshots.
- DNS secondaries per domain; SSL services flag admin-only + cert cleanup on
  delete; watchdog disk alert + orphan cert removed by dry-run.
- Telegram phone alerts (off by default): bot-based pager for box-level
  urgents with quiet hours, chat finder, and in-panel connect guide.
- Watchdog liveness: stale-tick banners on Services + Notifications, plus an
  optional external heartbeat URL for dead-cron detection.
- OS safety trio: release upgrades pinned off, apt sources integrity-watched,
  dist-upgrade warning on the Update page, 22.04 sunset notice in installer.
- CSRF completed on email/FTP/database creates (full-route audit).
- Notifications: per-row delete, delete-all, push card on top.
- Expiry watch reads the parent domain; certbot renewals logged to the viewer;
  log selector uses plain-language names.
- User segregation x4: pcntl fork functions blocked in pools, over-quota
  sites go offline (pool suspend + FTP lock, self-healing), MySQL query caps
  on all app-installer databases, 5-minute per-request CPU cutoff.
- Mail queue tools: thaw frozen, delete frozen, delete-all (DELETE confirm).
  Also fixed the frozen list never matching (exim indents IDs).
- DNS: CAA, SRV and SSHFP records with strict validation + zone render;
  BIND rejections now roll back instead of poisoning future rebuilds.
- SSH page rebuild: live status, add-key form, keypair generator, keys-only
  toggle with lockout guard, 60-second how-to.
- Cron page shows the read-only system schedule to admins.
- File manager Modified column; auto backups now register panel rows
  (plus backfill script); egress direction fix (inbound no longer alerts);
  Exim scans as debian-spamd (homeless-nobody errors gone); panel security
  headers; OLS per-IP request-rate caps.
- Visual Install Bug Removed

## 1.0.9 — 2026-10-08
- Fresh-install fail2ban fix: vsftpd template sets its log file again and the
  installer pre-touches every jail logpath (polling mode refuses to start
  when any is missing — one absent file killed the whole daemon).
- Checkbox/button alignment on the 2FA enforcement and weekend-scan forms.

## 1.0.8 — 2026-10-07
- Encrypted offsite backup mirror (Backups page, admin): S3-compatible
  (AWS/B2/Wasabi/Hetzner/R2/MinIO), SFTP, or local path, via rclone behind a
  crypt wrapper — providers see ciphertext only. Nightly sync, one-click
  fetch-back that plugs into the existing restore UI, failure notifications.
- WordPress auto-update toggle per install (Software page): minor core +
  plugins via mu-plugin, themes + majors stay manual, off pins core updates.
  Content/uploads/settings untouched; backups remain the safety net.

## 1.0.7 — 2026-10-07 (security release; FPM trio + log viewer already live since 1.0.6)
- Signed self-updates: build host signs SHA256SUMS (Ed25519/EC key, private
  never leaves dev); panel-update.sh verifies against install-time trust
  anchor, checks every file hash, and refuses downgrades/replays/unsigned
  tarballs. Trust anchor `/usr/local/skypanel/update-pubkey.pem`.
- 2FA enforcement toggle (admin, /2fa page): requires TOTP for admin +
  reseller logins, herding to setup until enabled. Users never forced.
  Token API unaffected.
- Fail2ban panel-login jail (5 fails, firewall) + found and fixed two live
  issues: fail2ban 1.x defaults to the journal so ALL file jails were blind
  (now `backend = polling`; dovecot got its own log file), and both boxes'
  config.php still pointed logs at the pre-rename path so security logging
  was silently dead (fixed).
- Egress watch: sampling canary alerting once per new (user, destination)
  outbound connection (cron every 6h, seeded baseline).
- File integrity watch: daily tripwire-lite over panel code, scripts and
  control configs with notification + one-click re-baseline on Update page;
  updates and SSL/mail scripts re-baseline themselves.
- FPM hardening: `disable_functions` shell lockdown in every pool, Admin >
  PHP pools status page with one-click re-apply, weekly `fpm-harden --all`
  cron (Sun 03:23).
- Exim weekend scan-skip: optional Sat/Sun spamd bypass (Email page, admin,
  default off). Malware rejects, rate caps and relay policy always apply;
  skipped mail gets `X-Spam-Skipped` and no `X-Spam-Flag`.
- New-IP login alerts: notification + log line when a login comes from a
  different IP than last time (first login silent, hourly dedup).

## 1.0.6 — 2026-10-07
- Software installs use `https://` URLs on SSL-enabled domains (Flarum and
  app links broke under HTTPS with a hardcoded `http://` base).

## 1.0.5 — 2026-10-07
- Flarum one-click fully automatic (CLI install, no browser wizard).
- Force-HTTPS on SSL issuance (ACME path exempt).
- Backup key loss warnings (confirm, flash, persistent reminder) + decrypt
  example box on the Backups page.

## 1.0.4 — 2026-10-07
- SSH hardening only on explicit key (root login + passwords stay reachable
  otherwise, with warnings); FPM pool template comment fix.
- Fix: merged `driver`/`to` lines in the Exim template broke fresh installs
  (configuration error at startup). No live boxes affected.

## 1.0.2 — 2026-10-07
- Abuse board now shows top processes per user (command + args), not just
  totals — answers *what* is eating the server, not just who.
- Installer reports version 1.0.2.

## 1.0.1 — 2026-10-07
- **Mail suite**: vacation autoresponders (one reply/sender/7d), sieve
  filters via Dovecot LMTP (file into folder, forward, discard), managed
  mailing lists (subscribers-only posting), deliverability scoring
  (SPF/DKIM/DMARC/MX/TLS out of 100), per-mailbox + global SMTP rate limits,
  per-list posting caps.
- **Monitoring**: downtime DOWN/RECOVERED mails with configurable recipient,
  cron dead-man watches with recovery mails, domain + SSL expiry watch
  (30/14/7/1-day bands), nightly malware scans with quarantine, resource
  abuse board with sustained-overload alerts.
- **Backups**: AES-256-CBC per-user encryption (transparent on every restore
  path), cPanel full-backup importer (files, DBs with WP credential
  recovery, mailboxes, stored mail, forwarders).
- **Panel UX**: pagination on all list pages, dashboard server IP + panel
  version, FTP default login display, database delete button, reseller
  client usage (domains/disk/BW/sent-today) + package labels, reboot button,
  alert-email and malware-schedule settings, decrypt example box.
- **Preview URLs**: unguessable per-domain links served before DNS moves.
- **Installer**: Ubuntu 22.04/24.04 gate (26.04 refused), Apache re-mask
  ordering fix, dpkg-lock guard, SSH key forwarding + fingerprint finale
  (passwords only disabled on explicit key), per-run log rotation, Roundcube
  ACL + spamd rule seeding, vsftpd TLS enforcement + LE hookup, log dir
  renamed to `/var/log/karnakpanel`, release builders for both tarball
  formats, `karnakpanel` CLI (old name kept as alias), asset rebrand.
- **Fixes**: OLS alias routing (`map <vhost> <host>`, comma aliases —
  parked domains never routed before), FPM pool `;`-only comments, readonly
  `$UID` in monitor scripts, reseller seed triplication (unique key),
  log-reader execute bit, ClamAV spool permissions (mail scanning was
  fail-open), missing `dovecot-lmtpd`, stale negative DNS caching guidance.

## 1.0.0 — 2026-10-06
- First public build: full hosting panel (OpenLiteSpeed + LSPHP/FPM
  multi-PHP, BIND9 + DNSSEC, Exim/Dovecot/SpamAssassin, vsftpd, MariaDB,
  Fail2ban, Roundcube, phpMyAdmin), reseller model with enforced limits,
  incremental + selective backups with panel self-update and rollback,
  one-click WordPress/phpBB/Vvveb, traffic stats, bandwidth auto-suspend,
  2FA, API tokens, file manager, cron, Dark/sky UI.

# KarnakPanel features (line by line)

## Roles & access
- Admin, reseller and user roles with separate dashboards
- Reseller client accounts with package limits
- One-click client plan changes (allocation-capped, downgrade-guarded, pool resized)
- Login-as (impersonate clients, fully logged)
- Reseller branding (panel name, logo, accent colour, support link and email for the reseller and their clients)
- TOTP two-factor authentication
- Second-factor brute-force throttling with admin alerts
- Hashed API tokens with role-scoped access, optional expiry, IP pinning and last-used tracking
- Brute-force login throttling with attempt logging
- Fail2ban panel-login jail (firewall bans)
- New-IP login alerts (notification + log on IP change)
- 2FA enforcement toggle for admin + reseller accounts
- Admin/reseller account password reset (shown once, logs the user out) and lost-authenticator 2FA reset
- Passwords never on a command line or in logs (passed to system scripts privately)
- Account create never takes over an existing system user; created completely or not at all
- Session manager (view and kill sessions)
- Password login hardening with SSH-key-first flow

## Domains & sites
- Addon domains with own docroot, vhost and PHP version
- Subdomains supported the same way
- Parked (aliased) domains, web-only
- Preview URLs (unguessable links before DNS moves)
- Per-account PHP version (8.3 / 8.4 / 8.5; 8.2 kept for existing sites until its end of life; all domains move together)
- Admin PHP extensions: curated catalog (imagick, redis, memcached, soap, gmp, pgsql, mongodb, imap, ldap, ssh2 and more) installed on every PHP version at once
- Hardened PHP pools (no shell functions, jailed, capped, niced) with admin status page + weekly re-apply
- Per-domain PHP error logs
- Per-domain php.ini editor and OPcache toggle
- Maintenance mode holding pages per domain
- Default branded landing page for unhosted domains
- Domain expiry tracking with alert bands

## DNS
- Full zone editor (A, AAAA, CNAME, MX, TXT, NS, CAA, SRV, SSHFP)
- One-click DNSSEC signing with registrar DS output
- Server nameservers for every zone (no per-domain registrar glue), with automatic glue in the panel's own zone and SPF defaults
- Nightly nameserver check via public resolvers: DNS, Email and Domains pages flag zones whose nameservers are still with the registrar
- Delegation check, quick-setup templates, zone text paste-import and export
- Zone validation before every reload (bad zones rejected)
- Import zone from disk and reset-to-defaults

## Email
- Virtual mailboxes (Exim + Dovecot + SpamAssassin)
- SMTP, IMAP and webmail (Roundcube) with TLS enforced (IMAP-only, POP3 closed)
- Forwarders including catch-all, optional copy kept in the same-named mailbox, editable in place
- Plus-addressing support
- Vacation autoresponders (one reply per sender per week) with optional start and end dates
- Server-side sieve filters (file into folder, forward, discard; match contains/is/starts-with on From, To, Cc, Subject or spam score; pause and edit)
- Mailbox quotas on create and edit (capped by the account disk allowance), self-service password change
- Mailbox import from another IMAP account (Gmail, Yahoo, iCloud, Zoho, any TLS IMAP host): one-way background copy of every folder with progress, re-runs fetch only new mail
- Managed mailing lists with subscribers-only posting
- Per-mailbox spam-flag thresholds
- Optional weekend spam-scan skip (Sat/Sun spamd bypass, default off)
- Per-mailbox and global SMTP sending rate limits
- Per-list posting rate caps
- Deliverability scoring (SPF/DKIM/DMARC/MX/TLS out of 100)
- New domains get SPF, DMARC (p=quarantine) and a DKIM signature automatically
- DKIM key generation, published automatically when the zone is hosted here (registrar TXT output otherwise)
- Mail on subdomains: MX/SPF/DKIM/DMARC written into the parent zone under the subdomain name
- MTA-STS + TLS-RPT publishing (testing → gated enforce: policy fetch + MX cert checks first)
- DMARC + TLS aggregate report intake and viewer (who sends as your domain, pass rates per source)
- Deliverability autopilot (guided DMARC none → quarantine → reject and MTA-STS testing → enforce, judged from received aggregate reports, owner clicks each step; alerts for new failing senders, TLS failures and ready steps)
- Mail queue viewer with flush and frozen handling
- Queue browser (paginated per-message list with sender-auth badges, per-message retry/delete/inspect, search filter)
- Recent delivery outcomes (delivered/bounced/deferred from the Exim log, filterable)
- Queue details (per-message stuck reasons, spool totals, failing-destination breakdown, next-retry times)
- Per-domain mail handling (local delivery or external-provider MX routing with dormant-on-remote mailboxes)
- SMTP relay (smarthost for port-25-blocked networks, test-mail proof)
- cPanel migration (files, databases, mailboxes, forwarders, sub/addon domains, autoresponders, filters)
- ClamAV malware rejection on inbound mail

## FTP
- Chrooted FTPS-only accounts (plaintext refused)
- Shows the exact host name on the FTP certificate to connect with (no mismatch warnings)
- Main login plus extra jailed logins per user
- Per-user FTP quota locks when over disk quota
- Panel-synced FTP passwords

## Databases
- One-click MariaDB database + user + grants wizard
- Per-user database resource caps (connections/queries/updates per hour, simultaneous connections), set by the admin and applied to existing users
- Password reset with one-time display
- Delete database with user cleanup
- phpMyAdmin with optional gate password

## Files
- In-browser file manager: breadcrumbs, sorting, search through subfolders, permissions and owner column, dotfiles (show/hide)
- Edit, rename, chmod, create files and folders, copy/cut/paste across folders
- Trash with restore (automatic purge after 30 days) plus permanent delete
- Drag-and-drop multi-file upload with progress; big files are sent in parts (with ClamAV on, files up to clamd's stream limit — 25 MB by default — are antivirus-scanned; larger ones are not)
- Extract .zip/.tar/.tar.gz/.tar.bz2/.tar.xz as the account user (unsafe archives refused); pack as .zip or .tar.gz; download a selection as zip
- Image preview (png/jpg/gif/webp)
- Code editor (CodeMirror): syntax highlighting for PHP, HTML, CSS, JS, JSON, SQL, shell, YAML, Markdown, .htaccess/INI and more; line numbers, bracket/tag matching, auto-close, find/replace, go to line, comment toggle, wrap and font size; keeps the file's line endings and indent style
- Visual (WYSIWYG) mode for plain .html/.htm pages, rendered in a sandboxed frame where the page's scripts never run
- Quota-aware uploads with over-quota blocking

## Cron
- Per-user scheduled tasks synced to system cron (run as the account, no symlink creation)
- Wrapped execution with per-job run history and output
- Dead-man monitoring with overdue and recovery mails
- Schedules shown in plain English with a common-schedule picker; last run ok/failed with output
- Admin system schedule grouped by purpose, every job explained

## SSL
- Let's Encrypt issuance per domain with auto-renewal
- Service certificates for panel, webmail and FTP
- Self-signed fallback for LAN-only servers
- SSL expiry tracking
- Installed apps auto-switch to HTTPS on issuance (WordPress, PrestaShop, Flarum, phpBB)

## Backups
- Full account backups (sites, mail, databases)
- Incremental daily snapshots with hard links
- Selective restore (single file, mailbox, database, path)
- Browse-before-restore with member listing
- AES-256-CBC per-user encryption with transparent restores
- cPanel full-backup importer (files, DBs, mailboxes, forwarders)
- cPanel remote import (login + API token, no root) and root import (SSH pkgacct), both with live progress
- HestiaCP import (root SSH or uploaded backup): sites, subdomains, DBs with passwords, mailboxes, forwarders, DNS records, paused cron
- Automatic nightly runs with retention pruning
- Encrypted offsite mirror (S3-compatible, SFTP, or local path) with one-click fetch-back
- One-click backup download

## Software
- One-click WordPress install (database and config done for you; create the admin account on first visit)
- Per-install WordPress auto-update toggle (minor core + plugins; themes + majors manual)
- Fleet Updates: every WordPress install at a glance (core + plugin status), one-click or scheduled updates with restore points and automatic rollback; resellers see only their own clients
- One-click phpBB install
- One-click Vvveb CMS install (root-only, hardened defaults)
- One-click Flarum forum install (root-only, PHP 8.3+, fully automatic CLI install)
- One-click Joomla 6 install (root or any folder, PHP 8.3+, fully automatic CLI install with the admin account created in the panel; no password ever on a command line)
- One-click Drupal 11 install (domain root, PHP 8.3+, standard profile installed by Drupal's own installer with Article and Basic page content types and the admin account created in the panel; Drupal's .htaccess protections applied as a managed vhost block that survives re-renders; no password ever on a command line)
- One-click PrestaShop shop install (root-only, fully automatic CLI install; needs PHP 8.2, so only on a domain already on 8.2)
- One-click installs show live progress (installing, done, or failed with the reason), check PHP version and database limits before starting, and link straight to each app's admin
- Remove installed apps (files, plus optionally the database; builder sites and other apps at a domain root are kept)
- Karnak Builder: drag-and-drop website builder with 75 templates in 11 categories (a picker with colour swatches, category filter and a live click-through preview of each template before you create the site), site styles (Aurora, Vivid, Glass, Midnight, Bold, Editorial, Soft, Retro, Luxe) with one-click Quick looks, blocks incl. FAQ, slider, opening hours, team and price list, autosave, history, private preview links, publish/offline/back online, image uploads, a static blog (posts at /blog/, tag pages, paging, RSS feed, latest-posts block, drafts); publishes plain static files, never overwrites files it did not write, all file work runs as the account user; per-package site limits
- App Toolkit staging clones for WordPress (password-locked, mail-gated, hidden from search; push files or full DB with divergence warning + restore points)

## Monitoring & stats
- Server stats (load, memory, processes, history graphs)
- Per-domain traffic stats: visitors, people vs bots (bots by name), daily/hourly/weekday charts, devices and browsers, top pages, referrers, broken links, server errors, scanner probes, heaviest IPs, change vs the previous period
- Account usage graphs: bandwidth and disk vs quota with pace and disk-full estimates, bandwidth per site
- Services page grouped by role: live status, uptime, memory and crash auto-restarts; restart, reload, start, stop (non-critical only), restart everything that's down
- Live dashboard: CPU, network rates, top processes, 24 h load/memory chart, heaviest accounts, SSL expiry
- Downtime email alerts with recovery notices
- Blacklist monitor: nightly check of the server's mail IPs (IPv4 + IPv6) and hosted domains on Spamhaus, SpamCop, Barracuda, PSBL, GBUdb, UCEPROTECT, SURBL and URIBL; alerts only when a listing appears or clears, with removal links; lists that refuse this server's resolvers are shown as not checkable, never as clean
- Bandwidth accounting with auto-suspend over quota
- Disk usage measurement with quota enforcement
- Usage alerts at 80% and 95% of disk and monthly bandwidth (panel notification + email, reseller notified)
- Disk usage viewer (per-account breakdown with folder drill-down)
- Quota enforcer (single-flight, idle-priority, per-user timeouts, user alerts)
- Live resource abuse board: per-user CPU/RAM (sampled each second) with trends and flashing offenders, process list with Kill / Kill all for customer processes, customers' running MariaDB queries
- Nightly malware scans with quarantine and owner mails
- Expiry watch for domains and certificates

## Security
- Fail2ban jails (SSH, web, mail, FTP, panel)
- UFW firewall manager with SSH guard, live fail2ban bans with reasons, one-click unban and a never-ban list
- CSRF protection on all state-changing requests
- Bcrypt password storage, nothing in plaintext
- Per-user PHP-FPM isolation with open_basedir
- Sandboxed website PHP (systemd): system files read-only, other accounts' processes invisible, own /dev/shm apart from cron and the host, no setuid files, no mount/ptrace/raw-I/O/kernel-module calls — PHP mail() still works
- Hosting accounts cannot create symlinks (PHP and cron); a file link is served only when its owner matches the target's
- Hosting accounts cannot read each other's files (homes closed by permissions; the web server reads only website folders, not the rest of a home; the panel itself can read all homes)
- Private per-account /tmp: cron jobs and supervised apps get their own /tmp and /var/tmp, and website PHP cannot use the shared /tmp (an admin can opt an account back out for apps that hard-code /tmp); cron jobs and apps also get their own /dev/shm. Not covered: websites on the same PHP version share one /dev/shm, and native code that ignores PHP's open_basedir
- Per-script memory caps and niced worker pools
- The panel runs as its own system user, separate from the web server's, and may run only the root helper scripts its own code calls (checked on every update; the panel can still read all homes)
- Chrooted FTP with shell-less system users
- Security headers ready web stack

## Updates & maintenance
- One-click panel self-update (snapshot, lint, migrate, atomic swap, health check, auto-rollback)
- Signed self-updates (build-host signature verified before apply, no downgrades)
- Self-update prunes consumed staged installers (Update page never offers the installed version)
- Installer welcome banner (server specs, public IP) with placeholder detection (asks for the real domain + email)
- Signed releases: the installer verifies the build-host signature before running anything
- Install-time health check, pre-flight checks and unattended mode
- OpenLiteSpeed admin console firewalled by default; firewall follows the real SSH port
- File integrity watch (daily, alerts + re-baseline)
- Egress watch (new outbound connection alerts per user)
- One-click system package upgrades with reboot detection
- Red reboot button with confirmation and delayed shutdown
- Release rollback to any kept version
- Disk cleanup tools (staging, sessions, old releases)
- Daemon keeper with autostart management
- phpMyAdmin gate password management

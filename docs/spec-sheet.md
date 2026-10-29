# KarnakPanel — Spec Sheet (website copy)

## One line
Self-hosted web hosting control panel for Ubuntu. One install command, full
mail/web/DNS stack, no license server, no lock-in.

## Features
- **Websites** — unlimited addon domains, per-domain PHP 8.1/8.2/8.3 pools,
  free Let's Encrypt SSL, maintenance mode, parked (alias) domains, traffic stats
- **Email** — unlimited mailboxes, IMAP/SMTP, webmail, spam filtering,
  antivirus, SPF/DKIM signing wizards, forwarders, catch-all, quotas enforced
- **Files** — browser file manager (edit, unzip, chmod), TLS-only FTP with
  sub-accounts, per-user isolation
- **Databases** — MariaDB wizard, phpMyAdmin (gated), per-user grants
- **DNS** — authoritative BIND, inline DNSSEC signing, DS records shown
- **Apps** — one-click WordPress, phpBB, Vvveb (CLI-completed, tracked)
- **Automation** — cron with run history, supervised always-on daemons,
  watchdog auto-recovery, bandwidth auto-suspend with monthly reset
- **Backups** — scheduled full + selective restore (single file, mailbox, database)
- **Security** — 2FA/TOTP, login-as support sessions, API tokens (hashed),
  fail2ban (6 jails), firewall manager, notifications center, self-update with rollback

## System requirements
| | Minimum | Recommended |
|---|---|---|
| OS | Ubuntu 22.04 / 24.04 fresh | same |
| RAM | 2 GB | 4 GB+ (with ClamAV) |
| Disk | 20 GB SSD | 50 GB+ SSD |
| Access | root SSH | root SSH + key |

Ports: 22 SSH · 25/587/465 mail · 53 DNS · 80/443 web · 143/993 IMAP ·
21 FTPS · 8090 panel · 8091 webmail · 8092 phpMyAdmin.

## Stack (all open source, no license fees)
OpenLiteSpeed · PHP-FPM 8.1–8.3 · MariaDB · BIND9 · Exim4 + Dovecot +
SpamAssassin + ClamAV (optional) · vsftpd · Fail2ban · Roundcube · framework-free
PHP panel + audited Bash backend.

## Security posture
bcrypt + rate-limited logins, TOTP 2FA, CSRF everywhere, prepared statements,
per-user isolation (FPM pools, chrooted FTP, DB grants), TLS-only data paths,
hashed API tokens, self-update with snapshot + auto-rollback. Independently
audited (see docs; pentest pending).

## Limits (honest)
- Single-server (no clustering/multi-server yet)
- No billing/invoicing (integrate Stripe/PayPal separately)
- No email autoresponders yet; no offsite backup target yet (roadmap)
- Ubuntu only; Apache/nginx not supported (OpenLiteSpeed stack)

# ☁️ KarnakPanel — Sky-Blue Ubuntu Hosting Control Panel

**Suggested name: KarnakPanel** — light, sky-blue/dark Modern Platform UX on an Ubuntu + LiteSpeed stack.

## Stack (Ubuntu 24.04 or Debian 13 for new installs; existing 22.04 supported until migrated — PHP + Bash)
| Component | Package | Role |
|---|---|---|
| Web | **OpenLiteSpeed** + PHP-FPM **8.3/8.4/8.5** per user (8.2 legacy) | panel on LSPHP 8.5 `:8090`, customer vhosts, per-account PHP switch |
| DNS | **BIND9** | `dns-add-zone.sh` creates `/etc/bind/zones/db.*` |
| Mail in/out | **Exim4** + **Dovecot** + **SpamAssassin (spamd)** | Maildir, IMAP/SMTP (no POP3) |
| FTP | **vsftpd** | chrooted, passive ports 40000-50000 |
| DB | **MariaDB** | panel DB `skypanel` + customer DB wizard |
| Security | **Fail2ban** + **OpenSSH** hardened | jails: sshd/lsws/exim/dovecot/vsftpd |
| Jobs | **cron** | per-user crontabs synced from DB, backups, LE renew |
| Panel | **PHP 8 (no framework)** + **Bash** | MVC-lite in `app/`, privileged ops via `scripts/*` + sudo |

## Roles
- **admin** — all + Services/SSH pages, restart daemons, see fail2ban.
- **reseller** — Reseller Center: clients, packages (disk/BW/domains/emails/DBs/FTP/PHP), create `user` accounts.
- **user** — Modern Platform dashboard: Domains, Email, FTP, DBs, DNS, Cron, Files, PHP, SSL, Backups.

> **Security scanned by Semgrep** — every push is scanned; findings are either
> fixed in code or suppressed with in-code reasons. No known-exploitable
> findings outstanding.

## Production install (Ubuntu 24.04 / Debian 13)
```bash
sudo bash installer/install.sh panel.example.com admin@example.com
# Panel → https://SERVER:8090, OLS admin → SSH tunnel to :7080 (firewalled)
# DB pass → /root/.skypanel-dbpass
```

## Layout
```
app/Core|Controllers|Models  — Router, Auth, ServiceManager (sudo scripts), 14 controllers
app/Views + public/assets    — Modern Platform grid, sky-blue (#0ea5e9) / dark (#0b1220), theme toggle, search
installer/lib/*.sh           — one module per daemon (idempotent)
installer/configs/*          — vhost.conf, named.options, vsftpd.conf, jail.local, dovecot/exim
scripts/*.sh                 — create/suspend/vhost/dns/email/ftp/php-switch/ssl/cron-sync/backup/quota
cli/karnakpanel              — `karnakpanel create-account|php-switch|ssl|ssh-key|services`
database/schema.sql          — users, packages, domains, dns, emails, ftp, dbs, cron, backups, tokens
```

## Security notes
- PHP never runs as root; only `sudo /usr/local/skypanel/scripts/*.sh` via `/etc/sudoers.d/skypanel`.
- All DB input prepared statements; CSRF token on POST; passwords `password_hash`.
- Installer prints a random per-install `admin` password once (also in `/root/.skypanel-adminpass`, 0600). Change it, enable 2FA, then create a daily-use account — don't browse as admin.
- LiteSpeed Enterprise can replace OpenLiteSpeed — vhost template is compatible (change `lsphp` paths).

## API
`GET /api/accounts` with header `X-API-KEY: <api_tokens.token>` (admin/reseller scope).

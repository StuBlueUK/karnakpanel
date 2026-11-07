# Installing KarnakPanel on a fresh VPS

## Requirements

- Fresh **Ubuntu 24.04 LTS** or **Debian 13** (other versions are refused;
  22.04 is no longer accepted for new installs — existing 22.04 panels keep
  updating, but should move to a supported server before April 2027)
- 2GB RAM minimum, 20GB disk
- Root SSH access; ports 22/80/443/8090 reachable
- A domain pointed at the server (for the panel + nameservers later)
- An SSH keypair recommended (`ssh-keygen -t ed25519` on your machine)

## Install (two commands, ~15 minutes)

```bash
wget https://karnakpanel.com/downloads/karnakpanel-install.sh
sudo bash karnakpanel-install.sh panel.example.com admin@example.com
```

With an SSH key (disables password logins automatically). Paste the
contents of the `.pub` file from **your own computer** (`cat ~/.ssh/id_ed25519.pub`
there), not a key file found on the server:

```bash
SKY_SSH_KEY='ssh-ed25519 AAAA...your-key... you@laptop' sudo -E bash karnakpanel-install.sh panel.example.com admin@example.com
```

Logged in through `sudo` with a key already? The installer lists the keys you
log in with and offers to reuse them for root (it asks first, never assumes).

Unattended (cloud-init / Ansible, no terminal): pass real values and accept
the licence explicitly: `KARNAK_ACCEPT_LICENSE=1 bash karnakpanel-install.sh panel.yourdomain.com you@yourdomain.com`.

The bootstrap checks the tarball's checksum **and** its signature from the
KarnakPanel build host before running anything.

> `panel.example.com` / `admin@example.com` are placeholders — replace them
> with your real domain and email. If you paste them as-is, the installer
> asks for the real values before doing anything.

What happens: system update → OpenLiteSpeed → PHP 8.3/8.4/8.5 + per-user
FPM → MariaDB → Exim/Dovecot/SpamAssassin → BIND9 → vsftpd (TLS-only) →
Fail2ban → Roundcube + phpMyAdmin → panel on `:8090` → self-signed certs →
cron jobs. Safe to re-run; completed steps are skipped. Logged per attempt
to `/var/log/karnakpanel-install.log`.

## After install

The finale prints: panel URL (`https://YOUR-IP:8090`), a random
per-install `admin` password (shown once on screen, never written to the
install log; saved root-only in `/root/.skypanel-adminpass`), a health
check of the main services, the deployed SSH key fingerprint (or a no-key
warning), and a checklist. The OpenLiteSpeed admin console (`:7080`) is
firewalled: reach it with `ssh -L 7080:127.0.0.1:7080 root@YOUR-IP`, or set
`KARNAK_ADMIN_IP=<your IP>` at install time to allow just that address.

First hour in the panel:

1. Change admin password, enable 2FA.
2. Create a daily-use account (user or reseller) and switch to it — don't
   browse as `admin` day-to-day (admin is all-powerful,
   so a stolen daily session must never own the whole server).
2. Add your domain → zone + vhost + mail auto-provision.
3. At your registrar: point the domain + set `ns1/ns2` glue to the box.
4. SSL page → Let's Encrypt replaces self-signed certs.
5. Backups page → confirm the 02:00 auto-backup ran.

No SSH key yet? Add your `.pub` via Services → SSH, verify a key login
works in a second terminal, then set `PasswordAuthentication no` in
`/etc/ssh/sshd_config` and restart sshd. Lose the private key with passwords
off and no provider console = locked out.

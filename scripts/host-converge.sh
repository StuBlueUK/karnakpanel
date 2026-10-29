#!/usr/bin/env bash
# host-converge.sh — bring already-installed boxes in line with installer
# fixes that fresh installs get directly. Called by post-update.sh (once per
# installed version). Idempotent; quiet when there is nothing to do. No args.
#  1. install logs root-only: older installers left them 644 with the admin
#     password line in them (readable by customer PHP/cron).
#  2. /usr/bin/php pinned to the default (8.3): the PPA php-cli metapackage makes it 8.4/8.5
#     (no mbstring/intl) and roundcube's gc timer fatals every run. Only while
#     alternatives are on "auto" — a manual choice by the owner is kept.
#  3. POP3 ports closed: the stack serves IMAP only (dovecot: imap lmtp), but
#     older installers opened 110/995 in the firewall.
#  4. exim localuser router limited to box-local domains: older configs let
#     <unixuser>@<any hosted domain> reach that account's ~/Maildir
#     (exim-localuser-patch.sh; quiet once patched).
#  5. exim RCPT ACL: mail-guard send hold + recipient verify at RCPT
#     (exim-rcpt-patch.sh; needs migration 049's columns, which panel-update
#     applies before post-update runs this; quiet once patched).
#  6. exim dovecot_lmtp transport: filtered boxes' router named it, older
#     configs never defined it (exim-lmtp-patch.sh; quiet once patched).
#  7. PHP refresh (php-versions.sh): install any selectable FPM version the
#     box lacks + lsphp85/84 (php-install.sh; one apt run per version, a
#     repo that lacks one is a WARN and the panel just doesn't offer it —
#     not a converge failure, so it doesn't re-run daily; next release
#     retries).
#  8. panel :8090 onto lsphp85 (panel-php-patch.sh: health-checked, restores
#     the old vhost on failure; "not ready" is a quiet no-op; a failure is
#     reported once per release, then skipped).
#  9. retire PHP 8.1 (php-retire.sh: purges only when no pool, site, plan or
#     OLS config uses it; otherwise WARN + admin notification, exit 0).
# 10. symlink-guard.sh: customer PHP/cron can't create symlinks, customer
#     vhosts serve owner-matched links only (cross-account leak).
# 11. home-perms.sh all: nothing inside a home readable by other accounts
#     (web server keeps u:nobody first), private ~/.tmp; then fpm-harden
#     --all rewrites every pool onto it (TMPDIR/sys_temp_dir/upload_tmp_dir).
# 12. dovecot PAM passdb removed: unix accounts (hosting accounts, root) could
#     log in to IMAP and SMTP AUTH (dovecot-nopam-patch.sh; quiet once patched).
# 13. database users still on the old 360 connections/hour caps get the
#     current ones (db-caps.sh upgrade; admin-customised users untouched).
# 14. supervised apps off the old unit format (command in ExecStart=, no link
#     filter) onto app-daemon-run.sh; units that don't match the old
#     template exactly are stopped and removed (app-daemon.sh converge).
# 15. suspend markers (account_suspended) for accounts the panel has
#     suspended: before 1.5.38 only the shadow lock marked them, and a
#     password-less account's lock is invisible — its cron jobs kept running.
# 16. mail DNS (mail-dns-converge.sh): a subdomain's mail-policy rows written
#     under its own id (no zone renders them) move into the parent zone;
#     DKIM keys whose hosted zone has no TXT for them get it published.
# 17. certbot renewal post-hook (certbot-hook.sh): the distro certbot.timer
#     renewed without reloading OLS/mail (only our cron line had a hook).
# 18. "DNS is at the registrar" verdicts (dns-delegation-check.sh): zones
#     never checked get a first look in the background, so the DNS / Email /
#     Domains notices show straight after the update, not the next night.
# 19. fail2ban exim jail kept on mainlog (exim-log-hook.sh): the nightly
#     rotation (`nocreate`) left it missing, fail2ban dropped it and the
#     jail watched nothing until its next restart.
# 20. phpMyAdmin jail counts only 401s carrying a username (pma-filter-patch.sh):
#     the browser's credential-less first challenge banned the admin's own IP.
# 21. dovecot 2.3 ssl_dh set (dovecot-dh-patch.sh): our conf skipped the stock
#     10-ssl.conf, so DHE clients failed TLS ("no DH parameters provided").
# 22. panel worker off `nobody` onto karnak-panel (panel-user.sh): OLS's own
#     workers are nobody too, so they shared the panel's sudo rights. Proven
#     by a worker probe or rolled back; a failed switch is not retried for
#     this version (PANEL_USER_RETRY=1). After home-perms (ACL twins).
# Each step is independent: one failing never skips the next.
set -uo pipefail
rc=0
for f in /var/log/karnakpanel-install.log /var/log/karnakpanel-install.log.prev-*; do
  [[ -f "$f" ]] || continue
  if [[ "$(stat -c '%a %U' "$f")" != "600 root" ]]; then
    chown root:root "$f" && chmod 600 "$f" && echo "host-converge: locked $f" || rc=1
  fi
done
HERE="$(cd "$(dirname "$(readlink -f "$0")")" && pwd)"
. "$HERE/php-versions.sh"
if command -v ufw >/dev/null 2>&1; then
  ADDED="$(ufw show added 2>/dev/null || true)"
  # ufw stores port lists sorted ("25,110,143,..."), so parse rather than
  # match text: any plain "allow <list>/tcp" rule carrying 110 or 995 is
  # re-added without them FIRST (mail ports never have a gap), then the old
  # rule is deleted by its exact spec (never by rule number).
  while read -r _ _ PORTS; do
    PORTS="${PORTS%/tcp}"
    KEEP="$(tr ',' '\n' <<<"$PORTS" | grep -vxE '110|995' | paste -sd, - || true)"
    [[ "$KEEP" == "$PORTS" ]] && continue
    if [[ -n "$KEEP" ]]; then ufw allow "$KEEP/tcp" >/dev/null 2>&1 || { rc=1; continue; }; fi
    ufw delete allow "$PORTS/tcp" >/dev/null 2>&1 \
      && echo "host-converge: closed POP3 ports 110/995 (rule $PORTS -> ${KEEP:-none})" || rc=1
  done < <(grep -E '^ufw allow [0-9,]+/tcp$' <<<"$ADDED" || true)
fi
if [[ -f /etc/exim4/exim4.conf ]] && command -v exim >/dev/null 2>&1; then
  OUT="$("$HERE/exim-localuser-patch.sh" 2>&1)" || { echo "host-converge: exim localuser patch failed: $OUT"; rc=1; }
  [[ "$OUT" == "patched + exim reloaded" ]] && echo "host-converge: exim localuser router limited to box-local domains"
  OUT="$("$HERE/exim-rcpt-patch.sh" 2>&1)" || { echo "host-converge: exim rcpt patch failed: $OUT"; rc=1; }
  [[ "$OUT" == "patched + exim reloaded" ]] && echo "host-converge: exim RCPT ACL now verifies hosted recipients + honours mail-guard holds"
  OUT="$("$HERE/exim-role-patch.sh" 2>&1)" || { echo "host-converge: exim role patch failed: $OUT"; rc=1; }
  [[ "$OUT" == "patched + exim reloaded" ]] && echo "host-converge: postmaster@/abuse@ fall back to each domain's first mailbox"
  OUT="$("$HERE/exim-lmtp-patch.sh" 2>&1)" || { echo "host-converge: exim lmtp patch failed: $OUT"; rc=1; }
  [[ "$OUT" == "patched + exim reloaded" ]] && echo "host-converge: mail filters deliver again (dovecot_lmtp transport added)"
fi
if [[ -f /etc/dovecot/dovecot.conf ]] && command -v doveconf >/dev/null 2>&1; then
  OUT="$("$HERE/dovecot-nopam-patch.sh" 2>&1)" || { echo "host-converge: dovecot no-PAM patch failed: $OUT"; rc=1; }
  [[ "$OUT" == "patched + dovecot reloaded" ]] && echo "host-converge: unix accounts can no longer log in to IMAP / SMTP AUTH"
fi
if command -v mysql >/dev/null 2>&1; then
  OUT="$("$HERE/db-caps.sh" upgrade 2>&1)" || { echo "host-converge: db caps upgrade failed: $OUT"; rc=1; }
  grep -E '^db-caps: raised' <<<"$OUT" | sed 's/^/host-converge: /'
fi
MISS_FPM=(); for v in $PHP_SELECTABLE; do php_known "$v" || MISS_FPM+=("$v"); done
MISS_LS=(); for v in 8.5 8.4; do [[ -x "/usr/local/lsws/lsphp${v/./}/bin/lsphp" ]] || MISS_LS+=("$v"); done  # php-ver-ok: panel tier
if (( ${#MISS_FPM[@]} + ${#MISS_LS[@]} )); then
  apt-get -o DPkg::Lock::Timeout=600 update >/dev/null 2>&1 || echo "host-converge: apt update failed — installing from cached indexes"
  if (( ${#MISS_FPM[@]} )); then
    OUT="$(bash "$HERE/php-install.sh" fpm "${MISS_FPM[@]}" 2>&1)" || grep -E '^WARN' <<<"$OUT" | sed 's/^/host-converge: /'
    for v in "${MISS_FPM[@]}"; do php_known "$v" && echo "host-converge: PHP $v (FPM) installed"; done
  fi
  if (( ${#MISS_LS[@]} )); then
    OUT="$(bash "$HERE/php-install.sh" lsphp "${MISS_LS[@]}" 2>&1)" || grep -E '^WARN' <<<"$OUT" | sed 's/^/host-converge: /'
    for v in "${MISS_LS[@]}"; do [[ -x "/usr/local/lsws/lsphp${v/./}/bin/lsphp" ]] && echo "host-converge: lsphp${v/./} installed"; done
  fi
fi
# ini wiring on EVERY lsphp present (not just new ones): wiring fixes such
# as lsphp85's missing session.save_path reach boxes installed earlier
LS_ALL=(); for d in /usr/local/lsws/lsphp8[0-9]; do [[ -x "$d/bin/lsphp" ]] && LS_ALL+=("8.${d: -1}"); done
if (( ${#LS_ALL[@]} )); then
  OUT="$(bash "$HERE/php-install.sh" wire "${LS_ALL[@]}" 2>&1)" || echo "host-converge: lsphp ini wiring failed: $OUT"
  # LSAPI workers read php.ini at spawn — graceful restart so it applies
  if grep -q '^wired:' <<<"$OUT"; then
    grep '^wired:' <<<"$OUT" | sed 's/^/host-converge: /'
    /usr/local/lsws/bin/lswsctrl restart >/dev/null 2>&1 || echo "host-converge: lsws restart failed after lsphp wiring"
  fi
fi
# CLI pin AFTER the installs above: a new php8.5-cli moves an "auto"
# /usr/bin/php to 8.5 — customer crons calling bare `php` must not follow
CLI="/usr/bin/php$PHP_DEFAULT"
if [[ -x "$CLI" ]]; then
  ALT="$(update-alternatives --query php 2>/dev/null || true)"
  if grep -q '^Status: auto' <<<"$ALT" && [[ "$(readlink -f /usr/bin/php)" != "$CLI" ]]; then
    update-alternatives --set php "$CLI" >/dev/null 2>&1 \
      && echo "host-converge: /usr/bin/php pinned to $PHP_DEFAULT" || rc=1
  fi
fi
if [[ -f /usr/local/lsws/conf/vhosts/SkyPanel/vhost.conf ]]; then
  OUT="$("$HERE/panel-php-patch.sh" 2>&1)" || { echo "host-converge: panel PHP patch failed: $OUT"; rc=1; }
  grep -E '^panel moved|^skypanel-php' <<<"$OUT" | sed 's/^/host-converge: /'
fi
# 10. cross-account symlink defences (FPM filter, vhost allowSymbolLink 2,
#     customer cron onto cron-run.sh) — AFTER the PHP installs above
"$HERE/symlink-guard.sh" all || rc=1
"$HERE/home-perms.sh" all || rc=1
OUT="$("$HERE/fpm-harden.sh" --all 2>&1)" || { echo "host-converge: fpm-harden failed: $(grep -i fail <<<"$OUT" | head -3)"; rc=1; }
OUT="$("$HERE/php-retire.sh" 8.1 2>&1)" || { echo "host-converge: php-retire 8.1 failed: $OUT"; rc=1; }  # php-ver-ok: Release A retires 8.1
grep -qE '^WARN|^PHP .* retired' <<<"$OUT" && sed 's/^/host-converge: /' <<<"$OUT"
"$HERE/app-daemon.sh" converge || rc=1
if [[ -r /root/.skypanel-dbpass ]] && command -v mysql >/dev/null 2>&1; then
  . "$HERE/account-check.sh"
  if SUS="$(MYSQL_PWD="$(cat /root/.skypanel-dbpass)" mysql -uskypanel skypanel -N -e "SELECT username FROM users WHERE status='suspended'" 2>&1)"; then
    install -d -m 700 -o root -g root "$SUSPENDED_DIR"
    while read -r SU; do
      valid_account_name "$SU" >/dev/null && [[ ! -e "$SUSPENDED_DIR/$SU" ]] && getent passwd "$SU" >/dev/null || continue
      : > "$SUSPENDED_DIR/$SU"; echo "host-converge: suspend marker set for $SU"
    done <<<"$SUS"
  else echo "host-converge: suspend markers: db query failed"; rc=1; fi
fi
# 16. after the PHP/panel steps above (needs the panel CLI + DB)
"$HERE/mail-dns-converge.sh" || rc=1
"$HERE/certbot-hook.sh" || rc=1
"$HERE/exim-log-hook.sh" || rc=1
"$HERE/pma-filter-patch.sh" || rc=1
"$HERE/dovecot-dh-patch.sh" || rc=1
"$HERE/panel-user.sh" || rc=1
# 18. (query fails before migration 056 = skipped; the cron line covers it)
if [[ -r /root/.skypanel-dbpass ]] && N="$(MYSQL_PWD="$(cat /root/.skypanel-dbpass)" mysql -uskypanel skypanel -N -e "SELECT COUNT(*) FROM domains WHERE ns_checked_at IS NULL" 2>/dev/null)" && [[ "$N" =~ ^[1-9] ]]; then
  ( setsid "$HERE/dns-delegation-check.sh" >/dev/null 2>&1 </dev/null & )
fi
exit $rc

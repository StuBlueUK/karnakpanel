#!/usr/bin/env bash
# sshd-dropin.sh <Key> <value> — set one sshd option where it actually WINS.
# sshd keeps the FIRST value it reads, and Ubuntu/Debian's sshd_config starts
# with `Include /etc/ssh/sshd_config.d/*.conf` — so a cloud image's
# 50-cloud-init.conf "PasswordAuthentication yes" beat our edit further down
# the main file (prod 2026-10-31: SSH page said off, sshd -T said yes). We own
# 00-karnakpanel.conf (sorts first) AND keep the main file in step (boxes
# without the Include). No restart here: callers sshd -t, restart, and then
# confirm with `sshd -T` (sshd-dropin.sh --check <key> <value>).
# Keys allowed: PasswordAuthentication, KbdInteractiveAuthentication,
# PermitRootLogin. IDEMPOTENT.
set -euo pipefail
CONF=/etc/ssh/sshd_config
DROP=/etc/ssh/sshd_config.d/00-karnakpanel.conf
if [[ "${1:-}" == "--check" ]]; then
  K="${2:?}"; V="${3:?}"
  # whole output into awk (no early exit: that SIGPIPEs sshd under pipefail)
  got="$(sshd -T 2>/dev/null | awk -v k="${K,,}" '$1==k && !n++{print $2}' || true)"
  # sshd -T prints the modern alias for prohibit-password
  [[ "$got" == without-password ]] && got=prohibit-password
  [[ "$got" == "$V" ]] && exit 0
  echo "sshd is NOT using $K $V (effective: ${got:-unknown}) — another file in /etc/ssh/sshd_config.d or $CONF overrides it"
  exit 1
fi
K="${1:?usage: sshd-dropin.sh <Key> <value> | --check <Key> <value>}"; V="${2:?}"
case "$K" in
  PasswordAuthentication|KbdInteractiveAuthentication) [[ "$V" == yes || "$V" == no ]] || { echo "bad value"; exit 1; } ;;
  PermitRootLogin) [[ "$V" =~ ^(yes|no|prohibit-password)$ ]] || { echo "bad value"; exit 1; } ;;
  *) echo "key not allowed: $K"; exit 1 ;;
esac
[[ -f "$CONF" ]] || { echo "no $CONF"; exit 1; }
mkdir -p /etc/ssh/sshd_config.d
if [[ ! -f "$DROP" ]]; then
  printf '# KarnakPanel — sorts first so it wins over provider drop-ins\n# (sshd keeps the first value). Managed by the SSH page; edit there.\n' > "$DROP"
fi
if grep -qi "^[[:space:]]*$K[[:space:]]" "$DROP"; then
  sed -i "s/^[[:space:]]*$K[[:space:]].*/$K $V/I" "$DROP"
else
  printf '%s %s\n' "$K" "$V" >> "$DROP"
fi
chown root:root "$DROP"; chmod 644 "$DROP"
# main file in step too, GLOBAL section only (before the first Match: lines
# after it are scoped to that Match, so never edit or append there). The
# first global line (commented default or real) becomes ours; none → line 1.
T="$(mktemp "$CONF.XXXXXX")"
awk -v k="$K" -v v="$V" '
  BEGIN { lk = tolower(k) }
  tolower($1) == "match" { inmatch = 1 }
  !inmatch && !done {
    l = $0; sub(/^[ \t]*#?[ \t]*/, "", l); split(l, w, /[ \t]+/)
    if (tolower(w[1]) == lk && l ~ /^[A-Za-z]+[ \t]+[^ \t]/) { print k " " v; done = 1; next }
  }
  { print }
  END { exit !done }' "$CONF" > "$T" || { { printf '%s %s\n' "$K" "$V"; cat "$CONF"; } > "$T"; }
chown root:root "$T"; chmod 644 "$T"; mv -f "$T" "$CONF"
echo "$K $V"

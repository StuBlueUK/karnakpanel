#!/usr/bin/env bash
# PHP-FPM per-user pools (customer PHP isolation). Stock repos ship one PHP —
# Ondrej Surý's builds provide the customer versions (scripts/php-versions.sh): the Launchpad PPA on Ubuntu,
# packages.sury.org on Debian (same maintainer, same package names). The panel ITSELF stays on
# LSPHP (php-multiphp.sh); FPM is for customer vhosts via OLS `fcgi` handlers.
set -euo pipefail
export DEBIAN_FRONTEND=noninteractive
REPO_SCRIPTS="$(cd "$(dirname "$0")/../../scripts" && pwd)"
. "$REPO_SCRIPTS/php-versions.sh"
# Repo probe = the NEWEST selectable version: the distros carry one PHP of
# their own (noble 8.3, trixie 8.4), so probing an older one would "pass"
# without sury and leave the newer versions uninstallable.
PROBE="php${PHP_SELECTABLE##* }-fpm"
# NEVER `apt-cache ... | grep -q` under pipefail: grep -q exits on first
# match, apt-cache then dies on SIGPIPE writing the rest (exit 141), and the
# pipeline "fails" on a perfectly healthy box — consistently under load
# (proven live: direct pipe missed 3/3 while capture-then-grep hit on the
# same box, same second). Capture first, then grep the capture.
policy_has_candidate() {
  local out
  out="$(apt-cache policy "$PROBE" 2>/dev/null || true)"
  grep -q "Candidate: [0-9]" <<<"$out"
}
. /etc/os-release
if [[ "${ID:-}" == "debian" ]] && ! policy_has_candidate; then
  # Debian: no PPAs. Key = DEB.SURY.ORG Automatic Signing Key, fpr
  # 15058500A0235D97F5D10063B188E2B695BD4743 (configs/apt/sury-php-debian.asc,
  # checked 2026-10-15 against the trixie InRelease; expires 2028-02, sury
  # extends it). Written to the path sury's own keyring package owns, then
  # that package is installed so key rotations arrive through apt.
  HERE="$(cd "$(dirname "$0")" && pwd)"
  apt-get install -y gnupg
  gpg --dearmor < "$HERE/../configs/apt/sury-php-debian.asc" > /usr/share/keyrings/debsuryorg-archive-keyring.gpg
  chmod 644 /usr/share/keyrings/debsuryorg-archive-keyring.gpg
  printf 'Types: deb\nURIs: https://packages.sury.org/php/\nSuites: %s\nComponents: main\nSigned-By: /usr/share/keyrings/debsuryorg-archive-keyring.gpg\n' "$VERSION_CODENAME" > /etc/apt/sources.list.d/sury-php.sources
  apt-get update
  apt-get install -y debsuryorg-archive-keyring || echo "WARN: debsuryorg-archive-keyring not installed — sury key rotations will need a manual key refresh" >&2
elif ! policy_has_candidate; then
  # Launchpad API access is flaky from some networks (seen: full timeouts
  # from Vultr) — retry quietly, then fail with guidance instead of a
  # 40-line Python traceback (the raw error goes to the install log).
  PPA_OK=0
  for i in 1 2 3; do
    if add-apt-repository -y ppa:ondrej/php >>/var/log/karnakpanel-install.log 2>&1; then PPA_OK=1; break; fi
    echo "php-fpm: PPA add attempt $i/3 failed, retrying in 15s (see install log)" >&2
    sleep 15
  done
  if [[ "$PPA_OK" != "1" ]]; then
    # Fallback: add-apt-repository needs api.launchpad.net (Launchpad API),
    # which some providers filter (proven: Vultr — package CDN 200, API dead
    # on v4+v6). The packages live on ppa.launchpadcontent.net, so write the
    # source + key directly — no API involved. Key = Ondrej PPA signing key
    # (fpr B8DC7E53946656EFBCE4C1DD71DAEAAB4AD4CAB6, extracted from a working
    # box 2026-10-13); if Ondrej ever rotates it, the candidate check below
    # fails loudly with guidance instead of installing the wrong thing.
    echo "php-fpm: Launchpad API unreachable — adding the PPA source directly" >&2
    HERE="$(cd "$(dirname "$0")" && pwd)"
    CODENAME="$(lsb_release -cs)"
    mkdir -p /etc/apt/keyrings
    # NOTE: do NOT "clean up" other ondrej source files here — add-apt-repository
    # names its file ondrej-ubuntu-php-<codename>.sources, and a broad cleanup
    # glob deletes the working stock entry (done once live, chased for an hour).
    # Failed attempts leave no debris (verified), so there is nothing to clean.
    install -m644 "$HERE/../configs/apt/ondrej-php.asc" /etc/apt/keyrings/ondrej-php.asc
    printf 'Types: deb\nURIs: https://ppa.launchpadcontent.net/ondrej/php/ubuntu/\nSuites: %s\nComponents: main\nSigned-By: /etc/apt/keyrings/ondrej-php.asc\n' "$CODENAME" > /etc/apt/sources.list.d/ondrej-php.sources
    apt-get update >>/var/log/karnakpanel-install.log 2>&1 || true
    # Candidate check, race-free: every apt-get update fires apt-news.service
    # in the background (APT Post-Invoke hook), which churns the lists while
    # we read them — a same-second `apt-cache policy` can come back EMPTY on
    # a good box (proven live: 3 empties over 20s, perfect state after). So
    # read the downloaded Packages indexes directly (deterministic) and only
    # then confirm via policy. 60s horizon before failing.
    for i in 1 2 3 4 5 6; do
      if grep -rq "^Package: $PROBE$" /var/lib/apt/lists/*ondrej* /var/lib/apt/lists/*sury* 2>/dev/null \
        && policy_has_candidate; then PPA_OK=1; break; fi
      sleep 10
    done
  fi
  if [[ "$PPA_OK" != "1" ]]; then
    echo "FATAL: could not add ppa:ondrej/php after 3 tries AND the direct-source fallback failed." >&2
    echo "Ubuntu needs this PPA for PHP $PHP_SELECTABLE — check 'apt update' output in the install log." >&2
    exit 1
  fi
  apt-get update
fi
# The PPA is present but the packages must actually resolve — otherwise the
# install below limps on (|| true) and dies cryptically three modules later.
# Same apt-news race as above: lists-file first, policy to confirm.
CAND_OK=0
for i in 1 2 3 4 5 6; do
  if grep -rq "^Package: $PROBE$" /var/lib/apt/lists/*ondrej* /var/lib/apt/lists/*sury* 2>/dev/null \
    && policy_has_candidate; then CAND_OK=1; break; fi
  sleep 10
done
# the newest version missing is a WARN (it is optional); only the default
# version failing below is fatal
[[ "$CAND_OK" == "1" ]] \
  || echo "WARN: $PROBE has no install candidate even with the Ondrej/sury repo — check 'apt update' output"
# one apt transaction per version (php-install.sh): a version the repo lacks
# is a loud WARN, never a silent all-or-nothing line. The default version is
# the one the stack cannot live without (webmail/PMA pools, /usr/bin/php).
bash "$REPO_SCRIPTS/php-install.sh" fpm $PHP_SELECTABLE || echo "WARN: not every PHP version installed — see above (the panel offers only the ones present)"
php_known "$PHP_DEFAULT" || { echo "FATAL: PHP $PHP_DEFAULT (FPM) did not install — the stack needs it"; exit 1; }

#!/usr/bin/env bash
# installer/lib/restic.sh — restic engine for backups (pinned binary + local
# repo; cron wires schedules, the panel toggle selects the engine).
# Safe to re-run: restic-install.sh converges (checksum-verified binary,
# password, repo init, unlock check).
set -euo pipefail
export DEBIAN_FRONTEND=noninteractive
bash "$(cd "$(dirname "$0")" && pwd)/../../scripts/restic-install.sh"

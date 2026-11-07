#!/usr/bin/env bash
# server-ip.sh — print the primary outward IPv4 (used for default DNS records)
set -euo pipefail
hostname -I | awk '{print $1}'

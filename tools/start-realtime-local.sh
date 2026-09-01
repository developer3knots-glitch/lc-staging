#!/usr/bin/env bash
# Git Bash: ./tools/start-realtime-local.sh
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "$ROOT/tools/start-realtime-local.ps1"

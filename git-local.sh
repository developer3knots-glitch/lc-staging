#!/usr/bin/env bash
# Use while Cursor locks the broken .git folder
ROOT="$(cd "$(dirname "$0")" && pwd)"
exec git --git-dir="$ROOT/.git-real" --work-tree="$ROOT" "$@"

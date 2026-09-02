#!/usr/bin/env bash
set -euo pipefail

ROOT="/c/xampp/htdocs/WSlivechat"
cd "$ROOT"

if [ ! -d ".git-real" ]; then
  echo "Missing .git-real. Run: git clone https://github.com/developer3knots-glitch/livechat.git /c/xampp/htdocs/livechat-repo"
  echo "Then: cp -r /c/xampp/htdocs/livechat-repo/.git .git-real"
  exit 1
fi

if [ -d ".git" ] && [ ! -f ".git" ]; then
  rm -rf .git
fi

printf 'gitdir: .git-real\n' > .git

echo "Git fixed."
git status -sb

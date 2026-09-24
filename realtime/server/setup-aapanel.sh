#!/usr/bin/env bash
# Run on aaPanel server as root (or www):
#   bash /www/wwwroot/livechat.3knotsdigital.com/realtime/server/setup-aapanel.sh
set -euo pipefail
DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$DIR"

if ! command -v node >/dev/null 2>&1; then
  echo "Node.js missing. Install from aaPanel App Store (Node.js v18+)."
  exit 1
fi

npm install --omit=dev

if [[ ! -f .env ]]; then
  cp .env.example .env
  echo "Created .env — ensure LC_REALTIME_SECRET matches PHP config."
fi

if command -v pm2 >/dev/null 2>&1; then
  pm2 delete livechat-realtime >/dev/null 2>&1 || true
  pm2 start server.js --name livechat-realtime
  pm2 save
  echo "Started with PM2: livechat-realtime"
else
  echo "PM2 not found. Install: npm install -g pm2"
  echo "Then: pm2 start server.js --name livechat-realtime && pm2 save && pm2 startup"
  exit 1
fi

sleep 1
curl -sf http://127.0.0.1:6001/health && echo "" && echo "OK — Node realtime is up."

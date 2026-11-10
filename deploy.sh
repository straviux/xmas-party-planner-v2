#!/usr/bin/env bash
# One-command deploy for the Christmas party planner (production).
#
# Run from the server clone:
#   ./deploy.sh
#
# It pulls the latest main, runs any pending migrations, and rebuilds
# the frontend, stopping at the first step that fails so a half-done
# deploy is never left behind silently. Migrations run on every
# deploy on purpose: `php artisan migrate` is safe to repeat (it only
# applies what is pending), which removes the "did this round need a
# migration?" question entirely.
#
# If the server's plain `php` is not the right binary, point PHP_BIN
# at the right one:  PHP_BIN=/path/to/php ./deploy.sh
set -euo pipefail

cd "$(dirname "$0")"

PHP_BIN="${PHP_BIN:-php}"

echo "==> Pulling latest main"
git pull --ff-only

echo "==> Running migrations (only pending ones apply)"
"$PHP_BIN" artisan migrate --force

echo "==> Building the frontend"
npm run build

echo "==> Deploy done: $(git rev-parse --short HEAD)"

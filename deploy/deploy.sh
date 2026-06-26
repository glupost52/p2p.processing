#!/usr/bin/env bash
# Deploy or update application. Run as deploy user from app root.
#   bash deploy/deploy.sh
set -euo pipefail

APP_DIR="${APP_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
BRANCH="${BRANCH:-main}"

cd "${APP_DIR}"

echo "==> Pull latest (${BRANCH})"
git fetch origin
git checkout "${BRANCH}"
git pull origin "${BRANCH}"

echo "==> Install PHP dependencies"
composer install --no-dev --optimize-autoloader --no-interaction

echo "==> Build frontend"
npm ci
npm run build

echo "==> Laravel maintenance"
php artisan storage:link --force 2>/dev/null || php artisan storage:link
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
php artisan optimize

echo "==> Permissions"
chmod -R ug+rwx storage bootstrap/cache
if command -v sudo >/dev/null 2>&1; then
    sudo chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true
fi

if command -v supervisorctl >/dev/null 2>&1; then
    echo "==> Restart Horizon"
    sudo supervisorctl reread 2>/dev/null || true
    sudo supervisorctl update 2>/dev/null || true
    sudo supervisorctl restart p2p-horizon 2>/dev/null || sudo supervisorctl start p2p-horizon 2>/dev/null || true
fi

echo "==> Done. Health: curl -sI \${APP_URL:-https://platpoint.org}/up"

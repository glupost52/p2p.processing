#!/usr/bin/env bash
# One-time VPS bootstrap for Ubuntu 24.04.
# Run as root: sudo bash deploy/bootstrap-server.sh
set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/p2p.processing}"
APP_USER="${APP_USER:-deploy}"
DOMAIN="${DOMAIN:-platpoint.org}"
GIT_REPO="${GIT_REPO:-https://github.com/glupost52/p2p.processing.git}"
GIT_BRANCH="${GIT_BRANCH:-main}"
DB_NAME="${DB_NAME:-p2p_processing}"
DB_USER="${DB_USER:-p2p}"

if [[ "${EUID}" -ne 0 ]]; then
    echo "Run as root: sudo bash deploy/bootstrap-server.sh"
    exit 1
fi

export DEBIAN_FRONTEND=noninteractive

apt-get update
apt-get upgrade -y

apt-get install -y \
    nginx \
    mysql-server \
    redis-server \
    git \
    curl \
    unzip \
    supervisor \
    certbot \
    python3-certbot-nginx \
    software-properties-common

add-apt-repository -y ppa:ondrej/php
apt-get update

apt-get install -y \
    php8.3-fpm \
    php8.3-cli \
    php8.3-mysql \
    php8.3-redis \
    php8.3-mbstring \
    php8.3-xml \
    php8.3-curl \
    php8.3-zip \
    php8.3-bcmath \
    php8.3-gmp \
    php8.3-intl

if ! command -v composer >/dev/null 2>&1; then
    curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
fi

if ! command -v node >/dev/null 2>&1; then
    curl -fsSL https://deb.nodesource.com/setup_20.x | bash -
    apt-get install -y nodejs
fi

if ! id "${APP_USER}" >/dev/null 2>&1; then
    useradd -m -s /bin/bash "${APP_USER}"
fi

usermod -aG www-data "${APP_USER}"
echo "${APP_USER} ALL=(ALL) NOPASSWD: /usr/bin/supervisorctl" > "/etc/sudoers.d/${APP_USER}-supervisor"
chmod 440 "/etc/sudoers.d/${APP_USER}-supervisor"

mkdir -p "${APP_DIR}"
chown -R "${APP_USER}:www-data" "${APP_DIR}"

if [[ ! -d "${APP_DIR}/.git" ]]; then
    sudo -u "${APP_USER}" git clone --branch "${GIT_BRANCH}" "${GIT_REPO}" "${APP_DIR}"
fi

DB_PASSWORD="$(openssl rand -base64 24 | tr -d '/+=' | head -c 32)"

mysql -e "CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -e "CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASSWORD}';"
mysql -e "GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';"
mysql -e "FLUSH PRIVILEGES;"

ENV_FILE="${APP_DIR}/.env"
if [[ ! -f "${ENV_FILE}" ]]; then
    sudo -u "${APP_USER}" cp "${APP_DIR}/deploy/env.production.example" "${ENV_FILE}"
    sed -i "s|APP_URL=.*|APP_URL=https://${DOMAIN}|" "${ENV_FILE}"
    sed -i "s|DB_DATABASE=.*|DB_DATABASE=${DB_NAME}|" "${ENV_FILE}"
    sed -i "s|DB_USERNAME=.*|DB_USERNAME=${DB_USER}|" "${ENV_FILE}"
    sed -i "s|DB_PASSWORD=.*|DB_PASSWORD=${DB_PASSWORD}|" "${ENV_FILE}"
fi

sudo -u "${APP_USER}" bash -c "cd '${APP_DIR}' && composer install --no-dev --optimize-autoloader --no-interaction"

if ! grep -q '^APP_KEY=base64:' "${ENV_FILE}"; then
    sudo -u "${APP_USER}" php "${APP_DIR}/artisan" key:generate --force
fi

cp "${APP_DIR}/deploy/nginx/platpoint.org.conf" "/etc/nginx/sites-available/${DOMAIN}"
ln -sf "/etc/nginx/sites-available/${DOMAIN}" "/etc/nginx/sites-enabled/${DOMAIN}"
rm -f /etc/nginx/sites-enabled/default
nginx -t
systemctl reload nginx

cp "${APP_DIR}/deploy/supervisor/horizon.conf" /etc/supervisor/conf.d/p2p-horizon.conf
sed -i "s|/var/www/p2p.processing|${APP_DIR}|g" /etc/supervisor/conf.d/p2p-horizon.conf

CRON_LINE="* * * * * cd ${APP_DIR} && php artisan schedule:run >> /dev/null 2>&1"
(crontab -u www-data -l 2>/dev/null | grep -F "schedule:run" || true) | grep -q "schedule:run" || \
    (crontab -u www-data -l 2>/dev/null; echo "${CRON_LINE}") | crontab -u www-data -

sudo -u "${APP_USER}" bash "${APP_DIR}/deploy/deploy.sh"

chown -R www-data:www-data "${APP_DIR}/storage" "${APP_DIR}/bootstrap/cache"

if ! certbot certificates 2>/dev/null | grep -q "${DOMAIN}"; then
    certbot --nginx -d "${DOMAIN}" -d "www.${DOMAIN}" --non-interactive --agree-tos -m "admin@${DOMAIN}" || true
fi

echo ""
echo "Bootstrap complete."
echo "App:     https://${DOMAIN}"
echo "DB user: ${DB_USER}"
echo "DB pass: ${DB_PASSWORD}  (also in ${ENV_FILE})"
echo ""
echo "Next: edit ${ENV_FILE} (Sentry, Telegram, deposit provider) and run:"
echo "  sudo -u ${APP_USER} bash ${APP_DIR}/deploy/deploy.sh"

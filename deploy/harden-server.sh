#!/usr/bin/env bash
# Production security hardening for Ubuntu 24.04 VPS.
# Run as root: sudo bash deploy/harden-server.sh
#
# What it does:
#   - fail2ban for SSH
#   - UFW: 22 open, 80/443 only from Cloudflare IPs
#   - Redis requirepass (shared with Laravel apps)
#   - Ensures Redis/MySQL bind to localhost
set -euo pipefail

APP_DIRS=(
    "/var/www/p2p.processing"
    "/var/www/crypto.proc"
)
REDIS_CONF="/etc/redis/redis.conf"
CF_IPV4_URL="https://www.cloudflare.com/ips-v4"
CF_IPV6_URL="https://www.cloudflare.com/ips-v6"

if [[ "${EUID}" -ne 0 ]]; then
    echo "Run as root: sudo bash deploy/harden-server.sh"
    exit 1
fi

export DEBIAN_FRONTEND=noninteractive

echo "==> Installing packages"
apt-get update -qq
apt-get install -y -qq ufw fail2ban curl

echo "==> Configuring fail2ban (sshd)"
cat > /etc/fail2ban/jail.local <<'EOF'
[DEFAULT]
bantime  = 1h
findtime = 10m
maxretry = 5
backend  = systemd

[sshd]
enabled  = true
port     = ssh
filter   = sshd
maxretry = 5
bantime  = 24h
EOF

systemctl enable fail2ban
systemctl restart fail2ban

echo "==> Ensuring Redis/MySQL bind to localhost"
if [[ -f "${REDIS_CONF}" ]]; then
    sed -i 's/^bind .*/bind 127.0.0.1 -::1/' "${REDIS_CONF}"
    sed -i 's/^protected-mode .*/protected-mode yes/' "${REDIS_CONF}"
fi

MYSQL_CNF="/etc/mysql/mysql.conf.d/mysqld.cnf"
if [[ -f "${MYSQL_CNF}" ]] && ! grep -q '^bind-address' "${MYSQL_CNF}"; then
    echo "bind-address = 127.0.0.1" >> "${MYSQL_CNF}"
fi

echo "==> Configuring Redis password"
REDIS_PASSWORD=""
for app_dir in "${APP_DIRS[@]}"; do
    env_file="${app_dir}/.env"
    if [[ -f "${env_file}" ]]; then
        existing=$(grep -E '^REDIS_PASSWORD=' "${env_file}" | cut -d= -f2- | tr -d '"' || true)
        if [[ -n "${existing}" && "${existing}" != "null" && "${existing}" != "CHANGE_ME" ]]; then
            REDIS_PASSWORD="${existing}"
            break
        fi
    fi
done

if [[ -z "${REDIS_PASSWORD}" ]]; then
    REDIS_PASSWORD="$(openssl rand -base64 32 | tr -d '/+=' | head -c 40)"
fi

if [[ -f "${REDIS_CONF}" ]]; then
    if grep -q '^requirepass ' "${REDIS_CONF}"; then
        sed -i "s/^requirepass .*/requirepass ${REDIS_PASSWORD}/" "${REDIS_CONF}"
    else
        echo "requirepass ${REDIS_PASSWORD}" >> "${REDIS_CONF}"
    fi
fi

for app_dir in "${APP_DIRS[@]}"; do
    env_file="${app_dir}/.env"
    if [[ -f "${env_file}" ]]; then
        if grep -q '^REDIS_PASSWORD=' "${env_file}"; then
            sed -i "s|^REDIS_PASSWORD=.*|REDIS_PASSWORD=${REDIS_PASSWORD}|" "${env_file}"
        else
            echo "REDIS_PASSWORD=${REDIS_PASSWORD}" >> "${env_file}"
        fi
    fi
done

systemctl restart redis-server
systemctl restart mysql 2>/dev/null || true

echo "==> Clearing Laravel config cache (Redis password change)"
for app_dir in "${APP_DIRS[@]}"; do
    if [[ -d "${app_dir}" && -f "${app_dir}/artisan" ]]; then
        sudo -u deploy bash -c "cd '${app_dir}' && php artisan config:clear && php artisan cache:clear" || true
    fi
done

echo "==> Restarting app workers"
systemctl restart php8.3-fpm 2>/dev/null || true
systemctl restart php8.4-fpm 2>/dev/null || true
if command -v supervisorctl >/dev/null 2>&1; then
    supervisorctl stop all 2>/dev/null || true
    pkill -9 -f "artisan horizon" 2>/dev/null || true
    sleep 2
    supervisorctl start all 2>/dev/null || true
fi

echo "==> Configuring UFW"
ufw --force reset
ufw default deny incoming
ufw default allow outgoing
ufw allow 22/tcp comment 'SSH'

echo "   Adding Cloudflare IPv4 ranges"
while IFS= read -r ip; do
    [[ -z "${ip}" ]] && continue
    ufw allow from "${ip}" to any port 80 proto tcp comment 'Cloudflare HTTP'
    ufw allow from "${ip}" to any port 443 proto tcp comment 'Cloudflare HTTPS'
done < <(curl -fsSL "${CF_IPV4_URL}")

echo "   Adding Cloudflare IPv6 ranges"
while IFS= read -r ip; do
    [[ -z "${ip}" ]] && continue
    ufw allow from "${ip}" to any port 80 proto tcp comment 'Cloudflare HTTP v6'
    ufw allow from "${ip}" to any port 443 proto tcp comment 'Cloudflare HTTPS v6'
done < <(curl -fsSL "${CF_IPV6_URL}")

ufw --force enable

echo ""
echo "Hardening complete."
echo ""
ufw status numbered
echo ""
echo "fail2ban: $(systemctl is-active fail2ban)"
echo "Redis password updated in app .env files (not printed)."
echo ""
echo "Verify:"
echo "  curl -sI https://platpoint.org/up"
echo "  curl -sI https://crypto.platpoint.org/up"
echo "  ss -tlnp | grep -E '6379|3306'"

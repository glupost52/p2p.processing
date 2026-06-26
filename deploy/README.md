# Deploy: platpoint.org (Ubuntu 24.04)

Репозиторий: https://github.com/glupost52/p2p.processing

## Первый запуск на чистом VPS

**Сначала** закоммить и запушь папку `deploy/` в `main` — bootstrap клонирует репо с GitHub.

1. DNS: `A` записи `platpoint.org` и `www.platpoint.org` → IP VPS.

2. На сервере:

```bash
ssh root@YOUR_VPS_IP
apt-get update && apt-get install -y git
git clone https://github.com/glupost52/p2p.processing.git /var/www/p2p.processing
cd /var/www/p2p.processing
bash deploy/bootstrap-server.sh
```

Скрипт установит Nginx, PHP 8.3, MySQL, Redis, Supervisor, создаст БД, `.env`, SSL (certbot), запустит деплой.

3. После bootstrap отредактируй `/var/www/p2p.processing/.env` (Sentry, Telegram, deposit provider) и снова:

```bash
sudo -u deploy bash /var/www/p2p.processing/deploy/deploy.sh
```

## Обновление (каждый релиз)

```bash
ssh deploy@YOUR_VPS_IP
bash /var/www/p2p.processing/deploy/deploy.sh
```

## Проверка

```bash
curl -sI https://platpoint.org/up
sudo supervisorctl status p2p-horizon
php /var/www/p2p.processing/artisan horizon:status
```

## Структура

| Файл | Назначение |
|---|---|
| `bootstrap-server.sh` | одноразовая настройка VPS (root) |
| `deploy.sh` | git pull, build, migrate, cache, restart horizon |
| `nginx/platpoint.org.conf` | vhost |
| `supervisor/horizon.conf` | очереди Laravel Horizon |
| `env.production.example` | шаблон prod `.env` |

## Важно

- Horizon и `schedule:run` (cron) обязательны — без них ордера и цены не работают.
- Логотипы платёжных методов: скопируй `storage/app/public/logos/` с бэкапа, затем `php artisan storage:link`.
- Horizon доступен пользователям с ролью **Super Admin** на `/horizon`.

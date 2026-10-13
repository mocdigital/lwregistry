# LW Registry Deployment

Target:
- AlmaLinux 9.8
- Nginx
- PHP 8.4-FPM
- MariaDB
- Supervisor
- Application path: `/var/www/registry`
- Domain: `registrytest.lwcommerce.org`
- Hostname: `server1.registrytest.lwcommerce.org`

## Runtime Requirements

Install PHP 8.4 with the extensions required by Composer platform checks: `ctype`, `curl`, `date`, `dom`, `exif`, `fileinfo`, `filter`, `hash`, `iconv`, `json`, `libxml`, `mbstring`, `openssl`, `pcre`, `pdo_mysql`, `phar`, `reflection`, `session`, `simplexml`, `tokenizer`, `xml`, `xmlwriter`, and `zlib`.

Install Composer 2, Node 24 or another Vite 7 supported runtime (`^20.19.0 || >=22.12.0`), npm, MariaDB, Nginx, Supervisor, firewalld, Fail2Ban, and Certbot.

## System User And Directories

Create a dedicated user and deploy the reviewed source to `/var/www/registry`.

```bash
sudo useradd --system --home-dir /var/www/registry --shell /sbin/nologin registry
sudo mkdir -p /var/www/registry
sudo chown -R registry:registry /var/www/registry
```

Do not copy `.env`, `.mcp.json`, `database/database.sqlite`, local backups, or development artifacts from a workstation. Create `/var/www/registry/.env` from `.env.production.example` and fill secrets only on the server.

## Database

Create a MariaDB database and application user. Production must use `DB_CONNECTION=mysql`; do not use SQLite in production.

Run migrations only through the deployment script or:

```bash
sudo -u registry /usr/bin/php /var/www/registry/artisan migrate --force
```

## Web Server

Install `deployment/nginx/registrytest.conf` as `/etc/nginx/conf.d/registrytest.conf`, then validate and reload Nginx.

```bash
sudo nginx -t
sudo systemctl reload nginx
```

The HTTP configuration is Certbot-ready. After DNS points to the server, issue TLS:

```bash
sudo certbot --nginx -d registrytest.lwcommerce.org
```

Only ports 80 and 443 should be public. Keep PHP-FPM and Reverb bound to local sockets/loopback.

## PHP-FPM

Install `deployment/php-fpm/registry.conf` as `/etc/php-fpm.d/registry.conf`.

```bash
sudo systemctl enable --now php-fpm
sudo systemctl reload php-fpm
```

PHP-FPM logs for this pool go to `/var/log/php-fpm/registry-error.log`.

## Queue Workers

Install `deployment/supervisor/registry-worker.ini` under Supervisor, then reload Supervisor.

```bash
sudo cp deployment/supervisor/registry-worker.ini /etc/supervisord.d/registry-worker.ini
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl status
```

Queue logs are written to `/var/log/supervisor/registry-worker.log`.

## Scheduler

Install `deployment/cron/registry` as `/etc/cron.d/registry`.

```bash
sudo cp deployment/cron/registry /etc/cron.d/registry
sudo chmod 0644 /etc/cron.d/registry
```

The Laravel scheduler runs every minute as `registry`.

## Broadcasting And Reverb

The application uses Laravel notification broadcasting for the notification bell. Reverb is optional operationally: keep `BROADCAST_CONNECTION=log` if real-time notifications are not required.

To enable Reverb:
- Set `BROADCAST_CONNECTION=reverb`.
- Set `REVERB_APP_ID`, `REVERB_APP_KEY`, and `REVERB_APP_SECRET`.
- Keep `REVERB_SERVER_HOST=127.0.0.1` and `REVERB_SERVER_PORT=8080`.
- Install `deployment/supervisor/registry-reverb.ini`.
- Keep the Nginx websocket proxy paths from `deployment/nginx/registrytest.conf`.

Do not expose port 8080 directly to the internet.

## Deployment

From `/var/www/registry`, run:

```bash
sudo ./deployment/scripts/deploy.sh
```

The script:
- Validates the application directory.
- Enables maintenance mode.
- Runs production Composer install.
- Runs `npm ci` and `npm run build`.
- Verifies `public/build/manifest.json`.
- Runs migrations with `--force`.
- Creates the storage symlink if needed.
- Optimizes Laravel.
- Restarts queue workers.
- Restarts Reverb only when enabled.
- Restores writable permissions.
- Disables maintenance mode through a trap if a deploy step fails.

## Health Checks

Run:

```bash
sudo ./deployment/scripts/health-check.sh
```

It checks `/up`, the home page, Nginx, PHP-FPM, MariaDB, Supervisor, queue workers, failed systemd units, writable Laravel directories, the build manifest, and database connectivity without printing credentials.

## Backups

Create `/home/registry/.my.cnf` with MariaDB credentials readable only by `registry`, then run:

```bash
sudo -u registry ./deployment/scripts/backup.sh
```

Backups include a compressed MariaDB dump and `storage/app/public`. The default local retention is 14 days. Configure off-server replication; local backups alone are not a recovery plan.

## Security Hardening

Enable firewalld for SSH, HTTP, and HTTPS only. Enable Fail2Ban for SSH and Nginx authentication/error patterns. Keep `APP_DEBUG=false` in production. Restrict `.env` to the `registry` user and never commit secrets.

## Rollback

Keep the previous reviewed release available before each deployment. To roll back:

1. Put the current app in maintenance mode.
2. Restore the previous source tree or release symlink.
3. Restore the matching `.env` if it changed.
4. Run `composer install --no-dev --prefer-dist --optimize-autoloader`.
5. Run `npm ci` and `npm run build` if frontend assets changed.
6. Run `php artisan migrate:status` and only roll back migrations when the migration is known to be reversible for the data change.
7. Run `php artisan optimize`.
8. Run `php artisan queue:restart`.
9. Bring the app up and run the health check.

## Logs

Key log locations:
- Laravel: `/var/www/registry/storage/logs/laravel.log`
- Nginx access: `/var/log/nginx/registrytest.access.log`
- Nginx error: `/var/log/nginx/registrytest.error.log`
- PHP-FPM pool: `/var/log/php-fpm/registry-error.log`
- Queue worker: `/var/log/supervisor/registry-worker.log`
- Reverb: `/var/log/supervisor/registry-reverb.log`

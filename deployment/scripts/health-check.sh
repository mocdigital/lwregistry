#!/usr/bin/env bash
set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/registry}"
APP_URL="${APP_URL:-https://registrytest.lwcommerce.org}"
PHP_BIN="${PHP_BIN:-/usr/bin/php}"

cd "$APP_DIR"

failures=0

check() {
    local label="$1"
    shift

    if "$@"; then
        echo "ok - $label"
    else
        echo "fail - $label" >&2
        failures=$((failures + 1))
    fi
}

check "application /up endpoint" curl --fail --silent --show-error --max-time 10 "$APP_URL/up"
check "application home HTTP status" curl --fail --silent --show-error --max-time 10 "$APP_URL/"
check "nginx service" systemctl is-active --quiet nginx
check "php-fpm service" systemctl is-active --quiet php-fpm
check "mariadb service" systemctl is-active --quiet mariadb
check "supervisor service" systemctl is-active --quiet supervisord
check "queue worker under supervisor" supervisorctl status registry-worker:registry-worker_00
check "no failed systemd units" bash -c '[[ "$(systemctl --failed --no-legend | wc -l)" -eq 0 ]]'
check "storage writable" test -w storage
check "cache writable" test -w bootstrap/cache
check "public build manifest" test -f public/build/manifest.json
check "Laravel database connectivity" "$PHP_BIN" artisan migrate:status --no-interaction

if supervisorctl status registry-reverb >/dev/null 2>&1; then
    check "Reverb supervisor process" supervisorctl status registry-reverb
fi

if [[ "$failures" -gt 0 ]]; then
    echo "$failures health check(s) failed." >&2
    exit 1
fi

echo "All health checks passed."

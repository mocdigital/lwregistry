#!/usr/bin/env bash
set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/registry}"
BACKUP_DIR="${BACKUP_DIR:-/var/backups/registry}"
RETENTION_DAYS="${RETENTION_DAYS:-14}"
MYSQL_DEFAULTS_FILE="${MYSQL_DEFAULTS_FILE:-/home/registry/.my.cnf}"

timestamp="$(date +%Y%m%d-%H%M%S)"
target_dir="$BACKUP_DIR/$timestamp"

umask 077
mkdir -p "$target_dir"

cd "$APP_DIR"

db_name="$(grep -E '^DB_DATABASE=' .env | tail -n 1 | cut -d '=' -f 2- | tr -d '\"')"

if [[ -z "$db_name" ]]; then
    echo "DB_DATABASE is not set in .env." >&2
    exit 1
fi

mysqldump --defaults-extra-file="$MYSQL_DEFAULTS_FILE" --single-transaction --routines --triggers "$db_name" \
    | gzip > "$target_dir/database.sql.gz"

if [[ -d storage/app/public ]]; then
    tar -C storage/app -czf "$target_dir/public-uploads.tar.gz" public
else
    tar -C storage/app -czf "$target_dir/public-uploads.tar.gz" --files-from /dev/null
fi

sha256sum "$target_dir"/* > "$target_dir/SHA256SUMS"

find "$BACKUP_DIR" -mindepth 1 -maxdepth 1 -type d -mtime +"$RETENTION_DAYS" -exec rm -r {} \;

echo "Backup written to $target_dir"
echo "Configure off-server backup replication; local backups alone are not sufficient."

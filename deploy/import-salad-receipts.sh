#!/usr/bin/env bash
# Idempotent import of receipt-backed Salad top-ups only; never bill GPU usage.
set -Eeuo pipefail
umask 077
[[ "$(id -u)" -eq 0 ]] || { echo 'IMPORT_ROOT_ONLY' >&2; exit 2; }
[[ $# -eq 1 && "$1" =~ ^[a-f0-9]{64}$ ]] || { echo 'EXPECTED_SHA256_REQUIRED' >&2; exit 2; }
release="$(cd -- "$(dirname -- "$0")/.." && pwd -P)"
[[ "$release" == /srv/hache-miner/releases/* ]] || { echo 'PINNED_RELEASE_REQUIRED' >&2; exit 2; }
csv=/srv/hache-miner/private-imports/salad-receipts-2026-10.csv
[[ -f "$csv" && ! -L "$csv" && -f /etc/hache-miner/load-env.php ]] || { echo 'PRIVATE_SOURCE_OR_RUNTIME_NOT_FOUND' >&2; exit 2; }
actual="$(sha256sum "$csv" | awk '{print $1}')"
[[ "$actual" == "$1" ]] || { echo 'SOURCE_SHA256_MISMATCH' >&2; exit 2; }
backups=/root/hache-miner-migration-backups
install -d -o root -g root -m 0700 "$backups"
backup="$(mktemp "$backups/pre-receipts.XXXXXXXX.sql")"
if ! mariadb-dump --single-transaction --quick hache_miner >"$backup"; then
    rm -f "$backup";echo 'IMPORT_BACKUP_FAILED' >&2; exit 2
fi
chmod 0600 "$backup"
[[ -s "$backup" ]] || { rm -f "$backup";echo 'IMPORT_BACKUP_EMPTY' >&2;exit 2; }
php -d auto_prepend_file=/etc/hache-miner/load-env.php \
    "$release/bin/import-salad-receipts.php" "$csv" "$1"
echo 'IMPORT_FINISHED_NO_GPU_OR_SALAD_API_ACTIONS'
echo 'ROOT_BACKUP_CREATED'

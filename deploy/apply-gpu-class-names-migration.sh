#!/usr/bin/env bash
set -Eeuo pipefail
umask 077
[[ "$(id -u)" -eq 0 ]] || { echo ROOT_REQUIRED; exit 2; }
release="$(cd -- "$(dirname -- "$0")/.." && pwd -P)"
[[ "$release" == /srv/hache-miner/releases/* ]] || { echo PINNED_RELEASE_REQUIRED; exit 2; }
[[ -f "$release/database/007_salad_gpu_class_names.sql" ]] || { echo MIGRATION_MISSING; exit 2; }
[[ -f /etc/hache-miner/runtime.php ]] || { echo PRIVATE_RUNTIME_REQUIRED; exit 2; }
mariadb hache_miner -e 'SELECT COUNT(*) FROM salad_gpu_prices' >/dev/null || { echo PRIOR_MIGRATION_006_REQUIRED; exit 2; }
backups=/root/hache-miner-migration-backups
install -d -m 0700 -o root -g root "$backups"
backup="$(mktemp "$backups/pre-007.XXXXXXXX.sql")"
if ! mariadb-dump --single-transaction --quick hache_miner > "$backup"; then
  rm -f "$backup"; echo BACKUP_FAILED; exit 2
fi
chmod 0600 "$backup"
[[ -s "$backup" ]] || { echo BACKUP_EMPTY;exit 2; }
mariadb hache_miner < "$release/database/007_salad_gpu_class_names.sql"
mariadb hache_miner -e 'SELECT organization,gpu_class,name FROM salad_gpu_class_names LIMIT 0' >/dev/null
echo SALAD_GPU_CLASS_NAMES_READY
echo ROOT_ONLY_BACKUP_CREATED

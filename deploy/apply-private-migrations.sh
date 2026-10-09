#!/usr/bin/env bash
# Root-only, additive migrations for HACHE-MINER with protected SQL backup.
set -Eeuo pipefail
umask 077
[[ "$(id -u)" -eq 0 ]] || { echo "ERROR: root required" >&2; exit 2; }
release="$(cd -- "$(dirname -- "$0")/.." && pwd -P)"
[[ "$release" == /srv/hache-miner/releases/* ]] || { echo "ERROR: not a pinned GitHub release" >&2; exit 2; }
[[ -f "$release/database/002_trusted_devices.sql" && -f "$release/database/003_salad_targets.sql" ]] || { echo "ERROR: migrations missing" >&2; exit 2; }
[[ -f /etc/hache-miner/runtime.php && -f /var/lib/hache-miner/master.key ]] || { echo "ERROR: private runtime missing" >&2; exit 2; }
tables="$(mariadb --batch --skip-column-names -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='hache_miner'")"
[[ "$tables" =~ ^(12|13|14)$ ]] || { echo "ERROR: miner schema not recognized" >&2; exit 2; }

backups=/root/hache-miner-migration-backups
install -d -o root -g root -m 0700 "$backups"
backup="$(mktemp "$backups/pre-002-003.XXXXXXXX.sql")"
if ! mariadb-dump --single-transaction --quick hache_miner > "$backup"; then
  rm -f -- "$backup"
  echo "ERROR: DB backup failed; no migrations applied" >&2
  exit 2
fi
chmod 0600 "$backup"
[[ -s "$backup" ]] || { echo "ERROR: backup empty" >&2; exit 2; }

mariadb hache_miner < "$release/database/002_trusted_devices.sql"
mariadb hache_miner < "$release/database/003_salad_targets.sql"

check="$(mariadb --batch --skip-column-names -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='hache_miner' AND TABLE_NAME IN ('trusted_devices','salad_targets')")"
[[ "$check" == 2 ]] || { echo "ERROR: migration verification failed" >&2; exit 2; }
targets="$(mariadb --batch --skip-column-names hache_miner -e "SELECT COUNT(*) FROM salad_targets WHERE (organization_slug='hache' AND project_slug='prl-tests') OR (organization_slug='interactive' AND project_slug='default')")"
[[ "$targets" == 2 ]] || { echo "ERROR: initial Salad targets absent" >&2; exit 2; }
echo "HACHE_MINER_MIGRATIONS_OK"
echo "TARGETS_SEEDED=2"
echo "DB_BACKUP_ROOT_ONLY=$backup"

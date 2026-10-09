#!/usr/bin/env bash
# One-time additive accounting migration. Only root via user's authorized shell.
set -Eeuo pipefail
umask 077
[[ "$(id -u)" -eq 0 ]] || { echo "ROOT_REQUIRED" >&2; exit 2; }
release="$(cd -- "$(dirname -- "$0")/.." && pwd -P)"
[[ "$release" == /srv/hache-miner/releases/* ]] || { echo "PINNED_RELEASE_REQUIRED" >&2; exit 2; }
[[ -f "$release/database/004_accounting_events.sql" ]] || { echo "ACCOUNTING_MIGRATION_MISSING" >&2; exit 2; }
[[ -f /etc/hache-miner/runtime.php && -f /var/lib/hache-miner/master.key ]] || { echo "PRIVATE_RUNTIME_NOT_READY" >&2; exit 2; }
for table in administrators wallets salad_targets group_state reconciled_charges pool_observations; do
 count="$(mariadb --batch --skip-column-names -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='hache_miner' AND TABLE_NAME='$table'")"
 [[ "$count" == 1 ]] || { echo "UNEXPECTED_MINER_SCHEMA:$table" >&2; exit 2; }
done
backups=/root/hache-miner-migration-backups
install -d -o root -g root -m 0700 "$backups"
backup="$(mktemp "$backups/pre-004.XXXXXXXX.sql")"
if ! mariadb-dump --single-transaction --quick hache_miner >"$backup";then
 rm -f -- "$backup"; echo "DB_BACKUP_FAILED" >&2;exit 2
fi
chmod 0600 "$backup"
[[ -s "$backup" ]] || { echo "EMPTY_DB_BACKUP" >&2;exit 2; }
mariadb hache_miner < "$release/database/004_accounting_events.sql"
count="$(mariadb --batch --skip-column-names -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='hache_miner' AND TABLE_NAME='accounting_events'")"
[[ "$count" == 1 ]] || { echo "ACCOUNTING_SCHEMA_NOT_READY" >&2;exit 2; }
mariadb hache_miner -e "SELECT COUNT(*) AS accounting_records FROM accounting_events" >/dev/null
echo "HACHE_MINER_ACCOUNTING_SCHEMA_OK"
echo "IMMUTABLE_LEDGER_ACTIVE"
echo "ROOT_ONLY_DATABASE_BACKUP=$backup"
echo "NO_SALAD_OR_MINING_ACTIONS"

#!/usr/bin/env bash
# Additive migration for official Salad Credits snapshots; never changes cash ledger.
set -Eeuo pipefail
umask 077
[[ "$(id -u)" -eq 0 ]] || { echo "ROOT_REQUIRED";exit 2; }
release="$(cd -- "$(dirname -- "$0")/.." && pwd -P)"
[[ "$release" == /srv/hache-miner/releases/* ]] || { echo "PINNED_RELEASE_REQUIRED";exit 2; }
[[ -f "$release/database/005_salad_credit_snapshots.sql" ]] || { echo "MIGRATION_MISSING";exit 2; }
[[ -f /etc/hache-miner/runtime.php ]] || { echo "PRIVATE_RUNTIME_REQUIRED";exit 2; }
for table in accounting_events salad_targets group_state; do
  exists="$(mariadb --batch --skip-column-names -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='hache_miner' AND table_name='$table'")"
  [[ "$exists" == 1 ]] || { echo "MISSING_DEPENDENCY:$table";exit 2; }
done
backups=/root/hache-miner-migration-backups
install -d -o root -g root -m 0700 "$backups"
backup="$(mktemp "$backups/pre-005.XXXXXXXX.sql")"
if ! mariadb-dump --single-transaction --quick hache_miner >"$backup";then
 rm -f "$backup";echo "BACKUP_FAILED";exit 2
fi
chmod 0600 "$backup"
[[ -s "$backup" ]] || { echo "BACKUP_EMPTY";exit 2; }
mariadb hache_miner < "$release/database/005_salad_credit_snapshots.sql"
mariadb hache_miner -e 'SELECT COUNT(*) FROM salad_credit_snapshots' >/dev/null
echo "SALAD_CREDITS_SNAPSHOTS_READY"
echo "ROOT_ONLY_BACKUP_CREATED"
echo "NO_CASH_LEDGER_OR_GPU_ACTIONS"

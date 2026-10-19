#!/usr/bin/env bash
# Additive, backed-up migration for direct Salad miner telemetry.
set -Eeuo pipefail
umask 077
[[ "$(id -u)" -eq 0 ]] || { echo "ROOT_REQUIRED";exit 2; }
release="$(cd -- "$(dirname -- "$0")/.." && pwd -P)"
[[ "$release" == /srv/hache-miner/releases/* ]] || { echo "PINNED_RELEASE_REQUIRED";exit 2; }
[[ -f "$release/database/010_salad_native_telemetry.sql" ]] || { echo "MIGRATION_MISSING";exit 2; }
[[ -f /etc/hache-miner/runtime.php ]] || { echo "PRIVATE_RUNTIME_REQUIRED";exit 2; }
for table in group_state salad_targets;do
  exists="$(mariadb --batch --skip-column-names -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='hache_miner' AND table_name='$table'")"
  [[ "$exists" == 1 ]] || { echo "MISSING_DEPENDENCY:$table";exit 2; }
done
backups=/root/hache-miner-migration-backups
install -d -o root -g root -m 0700 "$backups"
backup="$(mktemp "$backups/pre-010.XXXXXXXX.sql")"
if ! mariadb-dump --single-transaction --quick hache_miner >"$backup";then
  rm -f "$backup";echo "BACKUP_FAILED";exit 2
fi
chmod 0600 "$backup"
[[ -s "$backup" ]] || { echo "BACKUP_EMPTY";exit 2; }
mariadb hache_miner < "$release/database/010_salad_native_telemetry.sql"
for table in salad_telemetry_instances salad_telemetry_samples salad_telemetry_gpu_samples;do
  count="$(mariadb --batch --skip-column-names -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='hache_miner' AND table_name='$table'")"
  [[ "$count" == 1 ]] || { echo "MIGRATION_VERIFY_FAILED:$table";exit 2; }
done
echo "SALAD_NATIVE_TELEMETRY_READY"
echo "ROOT_ONLY_BACKUP_CREATED"
echo "EXISTING_SALAD_OBSERVATIONS_UNCHANGED"

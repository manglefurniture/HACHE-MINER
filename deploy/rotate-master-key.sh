#!/usr/bin/env bash
# Root-only recovery-safe rotation for an exposed HACHE-MINER master key.
# Does not print secrets and does not modify Hache Natación.
set -Eeuo pipefail
umask 077
[[ "$(id -u)" -eq 0 ]] || { echo "ROOT_ONLY" >&2; exit 2; }
[[ -f /srv/hache-miner/current/bin/rotate-master-key.php ]] || { echo "VERSIONED_ROTATOR_MISSING" >&2; exit 2; }
[[ -f /etc/hache-miner/runtime.php && -f /var/lib/hache-miner/master.key ]] || { echo "PRIVATE_RUNTIME_MISSING" >&2; exit 2; }
[[ -d /root && ! -L /var/lib/hache-miner/master.key ]] || { echo "INVALID_BACKUP_PATH" >&2; exit 2; }
service=hache-miner-poll.service
timer=hache-miner-poll.timer
was_active=0
if systemctl is-active --quiet "$timer"; then
  was_active=1
  systemctl stop "$timer"
fi
restore_timer() {
  status=$?
  trap - EXIT
  if [[ "$was_active" == 1 ]]; then
    systemctl start "$timer" || echo "WARNING: restore timer manually" >&2
  fi
  exit "$status"
}
trap restore_timer EXIT
for n in $(seq 1 90); do
  if ! systemctl is-active --quiet "$service"; then break; fi
  sleep 1
done
if systemctl is-active --quiet "$service"; then
  echo "ERROR: collector still running, rotation deferred" >&2
  exit 2
fi
install -d -o root -g root -m 0700 /root/hache-miner-key-rotation
backupdir="$(mktemp -d /root/hache-miner-key-rotation/rotation.XXXXXXXX)"
chmod 0700 "$backupdir"
if ! mariadb-dump --single-transaction --quick hache_miner > "$backupdir/pre-rotation.sql"; then
  rm -f -- "$backupdir/pre-rotation.sql"
  echo "ERROR: pre-rotation DB backup failed" >&2
  exit 2
fi
[[ -s "$backupdir/pre-rotation.sql" ]] || { echo "ERROR: empty backup" >&2; exit 2; }
chmod 0600 "$backupdir/pre-rotation.sql"
MINER_ROTATION_BACKUP_DIR="$backupdir" php /srv/hache-miner/current/bin/rotate-master-key.php
runuser -u hache-miner -- php -d auto_prepend_file=/etc/hache-miner/load-env.php /srv/hache-miner/current/bin/doctor.php | grep -E '^(PASS master_key|PASS database_connection|READY)' | tail -4
echo 'ROOT_ONLY_ENCRYPTED_CREDENTIALS_RECOVERABLE'
echo 'DO_NOT_SHARE_NEW_KEY_IN_CHAT_OR_SCREENSHOT'
echo 'EXPORT_NEW_KEY_OFF_SERVER_SECURELY_BEFORE_DELETING_LOCAL_RECOVERY_MATERIAL'

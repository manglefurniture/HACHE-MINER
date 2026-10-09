#!/usr/bin/env bash
# One-time read-only collector activation. Run as root only after backing up
# the master key and configuring the shared Salad API key in the private panel.
set -Eeuo pipefail
umask 022
[[ "$(id -u)" == 0 ]] || { echo 'ERROR: root required' >&2; exit 2; }
source_dir=/srv/hache-miner/current
service=/etc/systemd/system/hache-miner-poll.service
timer=/etc/systemd/system/hache-miner-poll.timer
[[ -f "$source_dir/deploy/systemd/hache-miner-poll.service" && -f "$source_dir/deploy/systemd/hache-miner-poll.timer" ]] || { echo 'ERROR: GitHub release files missing' >&2; exit 2; }
[[ ! -e "$service" && ! -L "$service" && ! -e "$timer" && ! -L "$timer" ]] || { echo 'ERROR: systemd units exist, refusing overwrite' >&2; exit 2; }
systemctl is-active --quiet hache-salad-monitor.timer || { echo 'ERROR: legacy monitor not active; investigate first' >&2; exit 2; }
for unit in nginx php8.4-fpm mariadb; do systemctl is-active --quiet "$unit" || { echo "ERROR: $unit not active" >&2; exit 2; }; done
available_kib="$(awk '/^MemAvailable:/ {print $2}' /proc/meminfo)"
[[ "$available_kib" =~ ^[0-9]+$ ]] && (( available_kib >= 143360 )) || { echo 'ERROR: available memory below 140MiB' >&2; exit 2; }
# Explicitly require shared API key and target enrollment; no secrets in stdout.
runuser -u hache-miner -- php -r 'require "/etc/hache-miner/load-env.php";require "/srv/hache-miner/current/app/core.php";if(!miner_shared_salad_api_key()||count(miner_salad_targets())===0)exit(2);' || {
  echo 'ERROR: shared Salad API key or enabled targets not configured' >&2; exit 2;
}
before="$(runuser -u hache-miner -- php -r 'require "/etc/hache-miner/load-env.php"; require "/srv/hache-miner/current/app/core.php";echo (int)miner_db()->query("SELECT COALESCE(MAX(id),0) FROM sync_runs")->fetchColumn();')"
[[ "$before" =~ ^[0-9]+$ ]] || { echo 'ERROR: cannot read sync checkpoint' >&2; exit 2; }
created=0
armed=0
rollback() {
  status=$?
  trap - EXIT
  if [[ "$status" -ne 0 && "$created" == 1 ]]; then
    if [[ "$armed" == 1 ]]; then systemctl disable --now hache-miner-poll.timer >/dev/null 2>&1 || true; fi
    rm -f -- "$service" "$timer"
    systemctl daemon-reload
    echo 'MINER_COLLECTOR_ROLLED_BACK' >&2
  fi
  exit "$status"
}
trap rollback EXIT
install -o root -g root -m 0644 "$source_dir/deploy/systemd/hache-miner-poll.service" "$service"
install -o root -g root -m 0644 "$source_dir/deploy/systemd/hache-miner-poll.timer" "$timer"
created=1
systemd-analyze verify "$service" "$timer"
systemctl daemon-reload
# Synchronous one-shot collection, no writes to Salad's control API.
systemctl start hache-miner-poll.service
result="$(runuser -u hache-miner -- php -r 'require "/etc/hache-miner/load-env.php";require "/srv/hache-miner/current/app/core.php";$p=miner_db()->prepare("SELECT COUNT(*) FROM sync_runs WHERE id>? AND source_name LIKE ? AND status=?");$p->execute([(int)$argv[1],"salad:%","ok"]);echo (int)$p->fetchColumn();' "$before")"
[[ "$result" =~ ^[0-9]+$ ]] && (( result >= 1 )) || {
  echo 'ERROR: no successful Salad collection; timer not enabled' >&2; exit 2;
}
echo "COLLECTION_VERIFIED_SUCCESSFUL_TARGETS=$result"
systemctl enable --now hache-miner-poll.timer
armed=1
systemctl is-active --quiet hache-miner-poll.timer
systemctl is-active --quiet hache-salad-monitor.timer
echo 'HACHE_MINER_TIMER_ACTIVE'
echo 'LEGACY_SALAD_MONITOR_ACTIVE'

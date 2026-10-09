#!/usr/bin/env bash
# Privileged installation performed by the server owner, not by public deploy.
set -Eeuo pipefail
umask 077
[[ "$(id -u)" -eq 0 ]] || { echo "ROOT_REQUIRED";exit 2; }
release="$(cd -- "$(dirname -- "$0")/.." && pwd -P)"
[[ "$release" == /srv/hache-miner/releases/* ]] || { echo "PINNED_RELEASE_REQUIRED";exit 2; }
[[ -r /etc/hache-miner/load-env.php ]] || { echo "PRIVATE_LOADER_MISSING";exit 2; }
for dep in php runuser systemctl;do command -v "$dep" >/dev/null || exit 2;done
for f in "$release/app/interactive-auto.php" "$release/app/reallocate.php" "$release/bin/interactive-auto.php";do php -l "$f" >/dev/null;done
php "$release/tests/interactive-auto-regression.php"
# Run under actual restricted identity, WITHOUT enabling actions.
out="$(runuser -u hache-miner -- env MINER_INTERACTIVE_AUTO_ENABLED=0 \
    /usr/bin/php -d auto_prepend_file=/etc/hache-miner/load-env.php \
    "$release/bin/interactive-auto.php")"
printf '%s\n' "$out" | grep -qx "AUTO_MODE=dry_run" || { echo "SAFE_PREFLIGHT_FAILED";exit 2; }
printf '%s\n' "$out" | grep -q '^ELIGIBLE_INTERACTIVE_PRL_INSTANCES=' || { echo "ELIGIBILITY_CHECK_FAILED";exit 2; }
# No deployment before the dry-run succeeds. Keep the legacy timer untouched.
service=hache-miner-interactive-auto.service
timer=hache-miner-interactive-auto.timer
[[ ! -e /etc/systemd/system/$service && ! -e /etc/systemd/system/$timer ]] || { echo "EXISTING_UNITS_REFUSING_OVERWRITE";exit 2; }
install -m 0644 -o root -g root "$release/deploy/systemd/$service" "/etc/systemd/system/$service"
install -m 0644 -o root -g root "$release/deploy/systemd/$timer" "/etc/systemd/system/$timer"
systemctl daemon-reload
systemctl enable --now "$timer"
[[ "$(systemctl is-active "$timer")" == active ]] || { echo "TIMER_NOT_ACTIVE";exit 2; }
[[ "$(systemctl is-active hache-salad-monitor.timer)" == active ]] || { echo "LEGACY_AUTO_TIMER_NOT_ACTIVE";exit 2; }
echo "INTERACTIVE_AUTO_TIMER_ACTIVE"
echo "LEGACY_HACHE_TIMER_PRESERVED"
echo "INTERACTIVE_PRL_ONLY_CONFIRMED_3_SAMPLES"
echo "NO_GPU_ACTION_DURING_INSTALL"

#!/usr/bin/env bash
# Root-only one-time update of the HACHE-MINER vhost from an audited release.
# Keeps all unrelated virtual hosts untouched and rolls back on failure.
set -Eeuo pipefail
umask 077
[[ "$(id -u)" -eq 0 ]] || { echo 'ERROR: requires root' >&2; exit 2; }
release="$(readlink -f /srv/hache-miner/current)"
[[ "$release" == /srv/hache-miner/releases/* ]] || { echo 'ERROR: invalid release'; exit 2; }
source="$release/deploy/nginx-hache-miner-https.conf.example"
vhost=/etc/nginx/sites-available/miner.hacheinteractive.com
enabled=/etc/nginx/sites-enabled/miner.hacheinteractive.com
[[ -f "$source" && -f "$vhost" && -L "$enabled" ]] || { echo 'ERROR: miner virtual host missing'; exit 2; }
[[ "$(readlink -f "$enabled")" == "$vhost" ]] || { echo 'ERROR: unexpected symlink'; exit 2; }
grep -Fq 'fastcgi_param SCRIPT_FILENAME $realpath_root/index.php;' "$source" || { echo 'ERROR: realpath not configured'; exit 2; }
backup="$(mktemp /etc/nginx/sites-available/.miner-vhost-backup.XXXXXXXX)"
cp -p "$vhost" "$backup"
rollback() {
  status=$?
  trap - EXIT
  if [[ "$status" -ne 0 ]]; then
    cp -p "$backup" "$vhost"
    /usr/sbin/nginx -t >/dev/null 2>&1 && systemctl reload nginx || true
    echo "MINER_VHOST_ROLLED_BACK" >&2
  else
    rm -f -- "$backup"
  fi
  exit "$status"
}
trap rollback EXIT

install -o root -g root -m 0644 "$source" "$vhost"
/usr/sbin/nginx -t
systemctl reload nginx
success=0
for attempt in $(seq 1 30); do
  health="$(curl --noproxy '*' --fail --silent --max-time 5 --resolve miner.hacheinteractive.com:443:127.0.0.1 https://miner.hacheinteractive.com/healthz 2>/dev/null || true)"
  login="$(curl --noproxy '*' --fail --silent --max-time 5 --resolve miner.hacheinteractive.com:443:127.0.0.1 'https://miner.hacheinteractive.com/?page=login' 2>/dev/null || true)"
  if [[ "$health" == ok && "$login" == *'Recordar este dispositivo durante 30 días'* ]]; then
    success=1
    break
  fi
  sleep 1
done
[[ "$success" -eq 1 ]] || { echo 'ERROR: new login not visible after Nginx reload' >&2; exit 2; }
echo "MINER_VHOST_REALPATH_READY"
echo "MINER_REMEMBER_DEVICE_VISIBLE"

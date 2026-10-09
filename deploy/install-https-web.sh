#!/usr/bin/env bash
# Installs only the versioned HACHE-MINER vhost and PHP-FPM pool.
# Requires an existing READY private runtime. Does not change natacion vhosts.
set -Eeuo pipefail
umask 022

domain=miner.hacheinteractive.com
release=/srv/hache-miner/current
pool=/etc/php/8.4/fpm/pool.d/hache-miner.conf
vhost=/etc/nginx/sites-available/miner.hacheinteractive.com
enabled=/etc/nginx/sites-enabled/miner.hacheinteractive.com
acme=/var/www/hache-miner-acme
challenge="$acme/.well-known/acme-challenge"
installed_pool=0
installed_site=0
committed=0

rollback() {
  local status=$?
  trap - EXIT
  if [[ "$committed" -ne 1 ]]; then
    echo "INSTALL_NOT_COMPLETED: rolling back new vhost and FPM pool" >&2
    if [[ "$installed_site" -eq 1 ]]; then
      rm -f -- "$challenge/hm-probe"
      rm -f -- "$enabled" "$vhost"
      if /usr/sbin/nginx -t >/dev/null 2>&1; then systemctl reload nginx || true; fi
    fi
    if [[ "$installed_pool" -eq 1 ]]; then
      rm -f -- "$pool"
      if /usr/sbin/php-fpm8.4 -t >/dev/null 2>&1; then systemctl reload php8.4-fpm || true; fi
    fi
  fi
  exit "$status"
}
trap rollback EXIT

[[ "$(id -u)" -eq 0 ]] || { echo "ERROR: root required" >&2; exit 2; }
[[ -f "$release/app/core.php" && -f "$release/public/index.php" ]] || { echo "ERROR: missing GitHub release" >&2; exit 2; }
[[ -f /etc/hache-miner/runtime.php && -f /etc/hache-miner/load-env.php && -f /var/lib/hache-miner/master.key ]] || { echo "ERROR: private runtime not ready" >&2; exit 2; }
[[ ! -e "$vhost" && ! -L "$vhost" && ! -e "$enabled" && ! -L "$enabled" ]] || { echo "ERROR: miner Nginx site already exists; refusing overwrite" >&2; exit 2; }
[[ ! -e "$pool" && ! -L "$pool" ]] || { echo "ERROR: miner FPM pool already exists; refusing overwrite" >&2; exit 2; }
systemctl is-active --quiet nginx
systemctl is-active --quiet php8.4-fpm
systemctl is-active --quiet mariadb
/usr/sbin/nginx -t
/usr/sbin/php-fpm8.4 -t

# Resource preflight: do not take away the last available memory from natacion.
available_kib="$(awk '/^MemAvailable:/ {print $2}' /proc/meminfo)"
[[ "$available_kib" =~ ^[0-9]+$ ]] && (( available_kib >= 131072 )) || {
  echo "ERROR: less than 128 MiB available; server upgrade or memory remediation required" >&2
  exit 2
}

install -o root -g root -m 0644 "$release/deploy/php-fpm-hache-miner.conf.example" "$pool"
installed_pool=1
/usr/sbin/php-fpm8.4 -t
systemctl reload php8.4-fpm
# systemctl reload returns when the reload signal is delivered, not when the
# PHP-FPM master has parsed new pools and bound new listening sockets.
# Give the new pool a bounded warm-up interval; protect existing services
# and trigger the rollback if the socket never appears.
pool_socket=/run/php/php8.4-fpm-hache-miner.sock
pool_ready=0
for attempt in $(seq 1 30); do
  if [[ -S "$pool_socket" ]]; then
    pool_ready=1
    break
  fi
  if ! systemctl is-active --quiet php8.4-fpm; then
    echo "ERROR: shared PHP-FPM service became unavailable during reload" >&2
    exit 2
  fi
  sleep 1
done
if [[ "$pool_ready" -ne 1 ]]; then
  echo "ERROR: miner php-fpm socket missing after 30-second wait" >&2
  exit 2
fi
echo 'MINER_FPM_SOCKET_READY'

install -d -o root -g root -m 0755 "$challenge"
# Temporary ACME-only HTTP site. HTTPS is not enabled before a valid cert exists.
cat > "$vhost" <<'NGINX'
server {
    listen 80;
    listen [::]:80;
    server_name miner.hacheinteractive.com;
    location ^~ /.well-known/acme-challenge/ {
        root /var/www/hache-miner-acme;
        default_type text/plain;
        try_files $uri =404;
    }
    location / { return 503; }
}
NGINX
chmod 0644 "$vhost"
ln -s "$vhost" "$enabled"
installed_site=1
/usr/sbin/nginx -t
systemctl reload nginx

# Nginx reload is asynchronous too: newly enabled server_name may not
# be served by the old workers immediately. Wait for the exact static probe
# (not merely HTTP 200 from a different vhost) before requesting Let's Encrypt.
printf 'HACHE-MINER-ACME-OK\n' > "$challenge/hm-probe"
acme_ready=0
for attempt in $(seq 1 30); do
  response="$(curl --noproxy '*' --fail --silent --max-time 3 \
    --resolve "$domain:80:127.0.0.1" \
    "http://$domain/.well-known/acme-challenge/hm-probe" 2>/dev/null || true)"
  if [[ "$response" == "HACHE-MINER-ACME-OK" ]]; then
    acme_ready=1
    break
  fi
  if ! systemctl is-active --quiet nginx; then
    echo "ERROR: shared Nginx service became unavailable" >&2
    exit 2
  fi
  sleep 1
done
if [[ "$acme_ready" -ne 1 ]]; then
  echo "ERROR: local ACME route never served our exact probe after 30-second wait" >&2
  exit 2
fi
rm -f "$challenge/hm-probe"
echo 'MINER_ACME_ROUTE_READY'

# Use the account Certbot already has configured on this host, if available.
# This can fail when Cloudflare forces HTTPS for HTTP-01; do not weaken TLS.
certbot certonly --non-interactive --agree-tos --webroot \
  --webroot-path "$acme" --cert-name "$domain" -d "$domain"

[[ -s "/etc/letsencrypt/live/$domain/fullchain.pem" &&
   -s "/etc/letsencrypt/live/$domain/privkey.pem" ]] || {
  echo "ERROR: Let's Encrypt did not install a certificate" >&2
  exit 2
}

# Copy the audited, version-controlled vhost; never edit natacion or other sites.
install -o root -g root -m 0644 "$release/deploy/nginx-hache-miner-https.conf.example" "$vhost"
/usr/sbin/nginx -t
systemctl reload nginx
# Wait for HTTPS server_name to become active; do not mistake the old
# unrelated default vhost for our protected miner app.
https_ready=0
for attempt in $(seq 1 30); do
  health="$(curl --noproxy '*' --fail --silent --max-time 4 \
    --resolve "$domain:443:127.0.0.1" "https://$domain/healthz" 2>/dev/null || true)"
  if [[ "$health" == "ok" ]]; then
    login="$(curl --noproxy '*' --fail --silent --max-time 4 \
      --resolve "$domain:443:127.0.0.1" "https://$domain/?page=login" 2>/dev/null || true)"
    if [[ "$login" == *'Iniciar sesión'* ]]; then
      https_ready=1
      break
    fi
  fi
  if ! systemctl is-active --quiet nginx; then
    echo "ERROR: shared Nginx stopped during TLS activation" >&2
    exit 2
  fi
  sleep 1
done
if [[ "$https_ready" -ne 1 ]]; then
  echo "ERROR: miner HTTPS health/login unavailable after 30-second wait" >&2
  exit 2
fi

committed=1
echo "MINER_HTTPS_READY"
echo "MINER_FPM_POOL_ACTIVE"
echo "MINER_HEALTH_OK"
echo "NEXT: back up master key and configure API keys privately."

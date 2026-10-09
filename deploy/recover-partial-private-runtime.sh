#!/usr/bin/env bash
# Recover a partial first-time installation of the private HACHE-MINER runtime.
# Contains no credentials and does not modify other databases or applications.
set -euo pipefail
umask 0077
APP=/srv/hache-miner/current
CONF=/etc/hache-miner
RUNTIME="$CONF/runtime.php"
LOADER="$CONF/load-env.php"
KEY=/var/lib/hache-miner/master.key
TMP=''

cleanup() {
  if [[ -n "$TMP" && -f "$TMP" ]]; then rm -f -- "$TMP"; fi
  unset DBPASS || true
}
trap cleanup EXIT

[[ "$(id -u)" -eq 0 ]] || { echo 'ERROR: root required'; exit 2; }
[[ -f "$APP/database/001_initial.sql" && -f "$APP/bin/bootstrap.php" && -f "$APP/deploy/load-env.php" ]] || { echo 'ERROR: GitHub release missing'; exit 2; }
[[ ! -L "$CONF" && -d "$CONF" && ! -L /var/lib/hache-miner ]] || { echo 'ERROR: private paths invalid'; exit 2; }
[[ "$(stat -c %U "$CONF")" == root ]] || { echo 'ERROR: private directory owner invalid'; exit 2; }
id hache-miner >/dev/null 2>&1 || { echo 'ERROR: service identity missing'; exit 2; }

# An existing runtime may be live: never silently change its SQL password.
if [[ -e "$RUNTIME" || -L "$RUNTIME" ]]; then
  echo 'ALREADY_CONFIGURED: SQL credentials were not changed'
  exit 0
fi

# The user's screenshot verified 12 tables. Do not recreate this database.
TABLES="$(mariadb --batch --skip-column-names -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='hache_miner'")"
[[ "$TABLES" == 12 ]] || { echo "ERROR: expected 12 mining tables, found $TABLES"; exit 2; }

# Only repair the mining-specific SQL user; pass password over stdin, not argv.
DBPASS="$(openssl rand -hex 32)"
mariadb --batch >/dev/null <<SQL
CREATE USER IF NOT EXISTS 'hache_miner'@'localhost' IDENTIFIED BY '$DBPASS';
ALTER USER 'hache_miner'@'localhost' IDENTIFIED BY '$DBPASS';
GRANT SELECT, INSERT, UPDATE, DELETE ON hache_miner.* TO 'hache_miner'@'localhost';
SQL

TMP="$(mktemp "$CONF/.runtime.php.XXXXXX")"
cat >"$TMP" <<PHP
<?php
declare(strict_types=1);
putenv('MINER_DB_DSN=mysql:host=localhost;dbname=hache_miner;charset=utf8mb4');
putenv('MINER_DB_USER=hache_miner');
putenv('MINER_DB_PASSWORD=$DBPASS');
putenv('MINER_MASTER_KEY_FILE=$KEY');
putenv('MINER_POLL_LOCK=/var/lib/hache-miner/poll.lock');
PHP
chown root:hache-miner "$TMP"
chmod 0640 "$TMP"
[[ ! -e "$RUNTIME" && ! -L "$RUNTIME" ]] || { echo 'ERROR: runtime now exists'; exit 2; }
mv -T -- "$TMP" "$RUNTIME"
TMP=''
unset DBPASS

install -o root -g root -m 0644 "$APP/deploy/load-env.php" "$LOADER"
if [[ ! -e "$KEY" && ! -L "$KEY" ]]; then
  runuser -u hache-miner -- env MINER_MASTER_KEY_FILE="$KEY" php "$APP/bin/bootstrap.php" make-key
else
  [[ -f "$KEY" && ! -L "$KEY" && "$(stat -c %U "$KEY")" == hache-miner ]] || { echo 'ERROR: existing key invalid'; exit 2; }
  echo 'EXISTING_KEY_PRESERVED'
fi

runuser -u hache-miner -- php -r \
  'require "/etc/hache-miner/load-env.php"; require "/srv/hache-miner/current/app/core.php"; if ((int)miner_db()->query("SELECT COUNT(*) FROM administrators")->fetchColumn() !== 0) { exit(2); } if (strlen(miner_key()) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) { exit(3); } echo "PRIVATE_DB_AND_ENCRYPTION_OK\n";'
echo 'NEXT: create first admin and back up the master key before saving secrets.'

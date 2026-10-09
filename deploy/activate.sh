#!/usr/bin/env bash
set -euo pipefail
sha="${1:-}"
[[ "$sha" =~ ^[0-9a-f]{40}$ ]] || { echo 'invalid sha' >&2; exit 2; }
root=/srv/hache-miner
release="$root/releases/$sha"
[[ -d "$release/public" && -f "$release/public/index.php" && -f "$release/app/core.php" && -f "$release/database/001_initial.sql" ]] || { echo 'incomplete release' >&2; exit 2; }
test ! -L "$release"
# Releases contain PUBLIC source only. Private credentials belong in /etc or /var/lib.
# Never expose an accidentally staged secret while normalizing read permissions.
if find "$release" -type f \( -name '.env' -o -name '.env.*' -o -name '*.pem' -o -name '*.key' -o -name 'runtime.php' \) -print -quit | grep -q .; then
  echo 'refusing release containing a private credential file' >&2; exit 2
fi
if find "$release" -type l -print -quit | grep -q .; then
  echo 'refusing release containing symlink(s)' >&2; exit 2
fi
# A release cloned with umask 077 can be 0700/0600, preventing PHP-FPM and
# the restricted collector from reading app/core.php. Make only public
# repository content readable; do not change privileged directories.
chmod -R a+rX "$release"
php -l "$release/public/index.php" >/dev/null
php -l "$release/app/core.php" >/dev/null
test -w "$root"
link="$root/.next-$sha"
test ! -e "$link"
ln -s "$release" "$link"
mv -Tf "$link" "$root/current"
printf 'activated %s\n' "$sha"

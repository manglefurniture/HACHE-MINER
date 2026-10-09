#!/usr/bin/env bash
set -euo pipefail
sha="${1:-}"
[[ "$sha" =~ ^[0-9a-f]{40}$ ]] || { echo 'invalid sha' >&2; exit 2; }
root=/srv/hache-miner
release="$root/releases/$sha"
[[ -d "$release/public" && -f "$release/public/index.php" && -f "$release/app/core.php" && -f "$release/database/001_initial.sql" ]] || { echo 'incomplete release' >&2; exit 2; }
test ! -L "$release"
php -l "$release/public/index.php" >/dev/null
php -l "$release/app/core.php" >/dev/null
test -w "$root"
link="$root/.next-$sha"
test ! -e "$link"
ln -s "$release" "$link"
mv -Tf "$link" "$root/current"
printf 'activated %s\n' "$sha"

#!/usr/bin/env bash
set -Eeuo pipefail

source_dir="$(cd "$(dirname "$0")/.." && pwd)"
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT
mkdir -p "$tmp/bin" "$tmp/etc/hache-vast" "$tmp/root" "$tmp/opt" "$tmp/var/log" "$tmp/run/lock"
state="$tmp/miner-running"

cat >"$tmp/bin/pgrep" <<'MOCK'
#!/usr/bin/env bash
[[ -e "$VAST_TEST_STATE" ]]
MOCK
cat >"$tmp/bin/sleep" <<'MOCK'
#!/usr/bin/env bash
for _ in {1..20}; do
  [[ -e "$VAST_TEST_STATE" ]] && exit 0
  /usr/bin/sleep 0.05
done
exit 0
MOCK
cat >"$tmp/bin/stat" <<'MOCK'
#!/usr/bin/env bash
printf '%s %s\n' "${VAST_TEST_OWNER:-0}" "$(/usr/bin/stat -c '%a' "$3")"
MOCK
cat >"$tmp/root/custom-launcher" <<'MOCK'
#!/usr/bin/env bash
: >"$VAST_TEST_STATE"
MOCK
printf '#!/usr/bin/env bash\nexit 0\n' >"$tmp/opt/custom-miner"
chmod 0700 "$tmp/bin/pgrep" "$tmp/bin/sleep" "$tmp/bin/stat" "$tmp/root/custom-launcher" "$tmp/opt/custom-miner"

cat >"$tmp/etc/hache-vast/onstart.conf" <<EOF
VAST_LAUNCHER=$tmp/root/custom-launcher
VAST_MINER=$tmp/opt/custom-miner
VAST_MINER_LOG=$tmp/custom/logs/miner.log
VAST_ONSTART_LOCK=$tmp/custom/locks/onstart.lock
EOF
chmod 0600 "$tmp/etc/hache-vast/onstart.conf"

# Run a private copy with only the documented host paths redirected for CI.
sed "s|/etc/hache-vast/onstart.conf|$tmp/etc/hache-vast/onstart.conf|" \
  "$source_dir/deploy/vast-onstart.sh" >"$tmp/onstart.sh"
chmod 0700 "$tmp/onstart.sh"
export PATH="$tmp/bin:$PATH" VAST_TEST_STATE="$state"

output="$(bash "$tmp/onstart.sh")"
grep -q 'process detected after startup' <<<"$output"
test -e "$state"
test -f "$tmp/custom/logs/miner.log"
test -f "$tmp/custom/locks/onstart.lock"

output="$(bash "$tmp/onstart.sh")"
grep -q 'already running; leaving it untouched' <<<"$output"

# Unknown configuration keys fail closed before launching anything.
printf 'VAST_INSTANCE_ID=123456\n' >>"$tmp/etc/hache-vast/onstart.conf"
if bash "$tmp/onstart.sh" >/dev/null 2>&1; then
  echo 'unsupported configuration key was accepted' >&2
  exit 1
fi

# Existing configuration must not be usable with unsafe permissions or owner.
sed -i '$d' "$tmp/etc/hache-vast/onstart.conf"
chmod 0644 "$tmp/etc/hache-vast/onstart.conf"
if bash "$tmp/onstart.sh" >/dev/null 2>&1; then
  echo 'insecure config permissions were accepted' >&2
  exit 1
fi
chmod 0600 "$tmp/etc/hache-vast/onstart.conf"
if VAST_TEST_OWNER=1001 bash "$tmp/onstart.sh" >/dev/null 2>&1; then
  echo 'non-root config ownership was accepted' >&2
  exit 1
fi

# Neither a symlink nor a relative configured path is allowed.
mv "$tmp/etc/hache-vast/onstart.conf" "$tmp/etc/hache-vast/onstart.saved"
ln -s onstart.saved "$tmp/etc/hache-vast/onstart.conf"
if bash "$tmp/onstart.sh" >/dev/null 2>&1; then
  echo 'symlink config was accepted' >&2
  exit 1
fi
rm "$tmp/etc/hache-vast/onstart.conf"
mv "$tmp/etc/hache-vast/onstart.saved" "$tmp/etc/hache-vast/onstart.conf"
printf 'VAST_MINER_LOG=relative/miner.log\n' >>"$tmp/etc/hache-vast/onstart.conf"
if bash "$tmp/onstart.sh" >/dev/null 2>&1; then
  echo 'relative log path was accepted' >&2
  exit 1
fi

echo 'vast onstart regression ok'

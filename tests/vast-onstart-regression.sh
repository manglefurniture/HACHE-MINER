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
exit 0
MOCK
cat >"$tmp/bin/mkdir" <<'MOCK'
#!/usr/bin/env bash
exit 0
MOCK
cat >"$tmp/bin/stat" <<'MOCK'
#!/usr/bin/env bash
printf '0 600\n'
MOCK
cat >"$tmp/root/custom-launcher" <<'MOCK'
#!/usr/bin/env bash
: >"$VAST_TEST_STATE"
MOCK
printf '#!/usr/bin/env bash\nexit 0\n' >"$tmp/opt/custom-miner"
chmod 0700 "$tmp/bin/pgrep" "$tmp/bin/sleep" "$tmp/bin/mkdir" "$tmp/bin/stat" "$tmp/root/custom-launcher" "$tmp/opt/custom-miner"

cat >"$tmp/etc/hache-vast/onstart.conf" <<EOF
VAST_LAUNCHER=$tmp/root/custom-launcher
VAST_MINER=$tmp/opt/custom-miner
VAST_MINER_LOG=$tmp/var/log/miner.log
VAST_ONSTART_LOCK=$tmp/run/lock/onstart.lock
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

output="$(bash "$tmp/onstart.sh")"
grep -q 'already running; leaving it untouched' <<<"$output"

# Unknown configuration keys fail closed before launching anything.
printf 'VAST_INSTANCE_ID=123456\n' >>"$tmp/etc/hache-vast/onstart.conf"
if bash "$tmp/onstart.sh" >/dev/null 2>&1; then
  echo 'unsupported configuration key was accepted' >&2
  exit 1
fi

echo 'vast onstart regression ok'

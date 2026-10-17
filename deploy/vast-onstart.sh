#!/usr/bin/env bash
# Vast.ai SSH instance startup hook. Install this file as /root/onstart.sh.
# Starts the EXISTING miner; never downloads binaries or changes GPU settings.
# No wallet, pool credentials or API keys are stored in this script.
set -Eeuo pipefail
umask 077

launcher=/root/start-prl-vast-5090x2.sh
miner=/opt/prl-srbminer/SRBMiner-MULTI
miner_log=/var/log/prl-miner.log
lock=/run/lock/hache-prl-vast-onstart.lock
readonly config=/etc/hache-vast/onstart.conf

# Keep host-specific paths out of Git. Parse a small key=value allowlist rather
# than sourcing shell code from a configuration file.
if [[ -e "$config" || -L "$config" ]]; then
  if [[ ! -f "$config" || -L "$config" ]]; then
    printf 'vast-onstart: config must be a regular non-symlink file\n' >&2
    exit 1
  fi
  read -r owner mode < <(stat -c '%u %a' "$config")
  if [[ "$owner" != 0 || ! "$mode" =~ ^[0-7]{3,4}$ ]] || (( (8#$mode & 077) != 0 )); then
    printf 'vast-onstart: config must be root-owned and inaccessible to group/other\n' >&2
    exit 1
  fi
  while IFS='=' read -r key value || [[ -n "${key:-}" ]]; do
    [[ -z "${key//[[:space:]]/}" || "$key" == \#* ]] && continue
    case "$key" in
      VAST_LAUNCHER) launcher=$value ;;
      VAST_MINER) miner=$value ;;
      VAST_MINER_LOG) miner_log=$value ;;
      VAST_ONSTART_LOCK) lock=$value ;;
      *) printf 'vast-onstart: unsupported config key: %s\n' "$key" >&2; exit 1 ;;
    esac
  done < "$config"
fi

for path in "$launcher" "$miner" "$miner_log" "$lock"; do
  if [[ "$path" != /* || "$path" == *$'\n'* ]]; then
    printf 'vast-onstart: configured paths must be absolute single-line paths\n' >&2
    exit 1
  fi
done
readonly launcher miner miner_log lock
readonly miner_name=${miner##*/}

say() {
  printf '[%s] vast-onstart: %s\n' "$(date -u +'%Y-%m-%dT%H:%M:%SZ')" "$*"
}

if [[ ! -x "$launcher" || ! -x "$miner" ]]; then
  say 'Existing SRBMiner or launcher is missing/not executable; refusing to start.'
  exit 1
fi
if ! command -v flock >/dev/null; then
  say 'flock unavailable; refusing an unguarded second miner.'
  exit 1
fi

mkdir -p -- "$(dirname -- "$lock")" "$(dirname -- "$miner_log")"
exec 9>"$lock"
if ! flock -n 9; then
  say 'A startup attempt is already in progress.'
  exit 0
fi
if pgrep -x "$miner_name" >/dev/null; then
  say "$miner_name already running; leaving it untouched."
  exit 0
fi

say 'Starting the existing SRBMiner launcher.'
# The launcher provides the pseudo-terminal required by SRBMiner.
# Append to log, detach from Vast onstart and close the lock FD.
nohup "$launcher" >>"$miner_log" 2>&1 </dev/null 9>&- &
launcher_pid=$!
sleep 8

if pgrep -x "$miner_name" >/dev/null; then
  say "$miner_name process detected after startup."
elif kill -0 "$launcher_pid" 2>/dev/null; then
  say 'Launcher is still initializing; inspect the miner log for progress.'
else
  say 'Launcher exited before SRBMiner appeared; inspect the miner log.'
  exit 1
fi

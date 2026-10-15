#!/usr/bin/env bash
# Vast.ai SSH instance startup hook. Install this file as /root/onstart.sh.
# Starts the EXISTING miner; never downloads binaries or changes GPU settings.
# No wallet, pool credentials or API keys are stored in this script.
set -Eeuo pipefail
umask 077

readonly launcher=/root/start-prl-vast-5090x2.sh
readonly miner=/opt/prl-srbminer/SRBMiner-MULTI
readonly miner_log=/var/log/prl-miner.log
readonly lock=/run/lock/hache-prl-vast-onstart.lock

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

mkdir -p /run/lock /var/log
exec 9>"$lock"
if ! flock -n 9; then
  say 'A startup attempt is already in progress.'
  exit 0
fi
if pgrep -x SRBMiner-MULTI >/dev/null; then
  say 'SRBMiner already running; leaving it untouched.'
  exit 0
fi

say 'Starting the existing SRBMiner launcher.'
# The launcher provides the pseudo-terminal required by SRBMiner.
# Append to log, detach from Vast onstart and close the lock FD.
nohup "$launcher" >>"$miner_log" 2>&1 </dev/null 9>&- &
launcher_pid=$!
sleep 8

if pgrep -x SRBMiner-MULTI >/dev/null; then
  say 'SRBMiner process detected after startup.'
elif kill -0 "$launcher_pid" 2>/dev/null; then
  say 'Launcher is still initializing; inspect the miner log for progress.'
else
  say 'Launcher exited before SRBMiner appeared; inspect the miner log.'
  exit 1
fi

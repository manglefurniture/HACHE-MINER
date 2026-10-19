#!/usr/bin/env python3
"""Read-only, fail-closed SRBMiner -> signed Vast telemetry (one invocation).

The miner must already be running. This collector never starts/stops/tunes it.
Install as a periodic task on the Vast instance, not on the Hache VPS.
"""
import argparse
import hashlib
import hmac
import json
import os
import re
import stat
import subprocess
import time
import uuid
import urllib.request

ANSI = re.compile(r'\x1b\[[0-?]*[ -/]*[@-~]')
# An indexed physical GPU table row, not a pool speed or average.
ROW = re.compile(
    r'^\s*(?:\[[^\]\r\n]{1,90}\]\s*)?(?:GPU\s*#?\d{1,2}\s+)?#(?P<index>\d{1,2})\s+'
    r'(?P<model>[A-Za-z][^\r\n]{2,119}?)\s+'
    r'(?P<hash>\d+(?:\.\d+)?)\s+TH/s\s+'
    r'(?P<watts>\d+(?:\.\d+)?)W.*?\s+'
    r'(?P<temp>\d+)C\s+\d+\s+\d+\s+'
    r'(?P<accepted>\d+)\s+(?P<rejected>\d+)\s+(?P<hw>\d+)\b',
    re.IGNORECASE,
)


def parse_gpu_table(text, expected_count):
    """Parse only the LAST complete SRBMiner GPU table; never mix intervals."""
    if not 1 <= expected_count <= 16:
        raise ValueError("GPU count must be between 1 and 16")
    lines = text.splitlines()
    found = []
    for line_number, line in enumerate(lines):
        match = ROW.search(ANSI.sub('', line))
        if not match:
            continue
        data = match.groupdict()
        model = data['model'].strip()
        if re.search(r'\b(?:pool|total|average|worker|shares?)\b', model, re.I):
            continue
        if float(data['hash']) > 20000 or float(data['watts']) > 1500 or int(data['temp']) > 150:
            continue
        found.append({
            'line': line_number,
            'index': int(data['index']),
            'model': model,
            'hashrate_ths': float(data['hash']),
            'watts': float(data['watts']),
            'temp_c': int(data['temp']),
            'accepted': int(data['accepted']),
            'rejected': int(data['rejected']),
        })
    starts = [index for index, row in enumerate(found) if row['index'] == 0]
    if not starts:
        raise ValueError("no indexed SRBMiner GPU statistics")
    latest = found[starts[-1]:]
    # A partial/repeated table is NOT a complete sample, even if older GPU
    # rows in the same file could be used to fill missing entries.
    if [row['index'] for row in latest] != list(range(expected_count)):
        raise ValueError("latest SRBMiner GPU table incomplete or inconsistent")
    if len(lines) - latest[-1]['line'] > 41:
        raise ValueError("latest SRBMiner GPU table is too far from log tail")
    gpu = [{
        'index': row['index'],
        'model': row['model'],
        'hashrate_ths': row['hashrate_ths'],
        'average_ths': None,
        'temp_c': row['temp_c'],
        'watts': row['watts'],
        'state': 'mining' if row['hashrate_ths'] > 0 else 'inactive',
    } for row in latest]
    return {
        'total_ths': sum(row['hashrate_ths'] for row in latest),
        'accepted_shares': sum(row['accepted'] for row in latest),
        'rejected_shares': sum(row['rejected'] for row in latest),
        'gpus': gpu,
    }


def read_recent_log(path, now=None):
    """A frozen log cannot produce new mining readings."""
    now = time.time() if now is None else now
    age = now - os.stat(path).st_mtime
    if age < -30 or age > 120:
        raise ValueError("SRBMiner log is not recent")
    return subprocess.check_output(
        ['tail', '-n', '200', path], text=True, stderr=subprocess.DEVNULL
    )


def read_recent_tmux(session, now=None):
    """Capture existing pane without disturbing the miner; never refresh stale data."""
    if not re.fullmatch(r'[A-Za-z0-9_-]{1,64}', session):
        raise ValueError("invalid tmux session")
    now = time.time() if now is None else now
    output = subprocess.check_output(
        ['tmux', 'capture-pane', '-p', '-J', '-S', '-300', '-t', session],
        text=True, stderr=subprocess.DEVNULL, timeout=8,
    )
    # SRBMiner prints timestamped statistics. A share alone does not refresh a
    # table; validate the timestamp of the LAST full table in the captured pane.
    lines = output.splitlines()
    starts = [i for i, line in enumerate(lines)
              if re.search(r'\\[\\d{4}-\\d\\d-\\d\\d \\d\\d:\\d\\d:\\d\\d\\]\\s+#0\\s+RTX', line)]
    if not starts:
        raise ValueError("no timestamped tmux GPU statistics")
    from datetime import datetime, timezone
    stamp = re.search(r'\\[(\\d{4}-\\d\\d-\\d\\d \\d\\d:\\d\\d:\\d\\d)\\]', lines[starts[-1]])
    if stamp is None:
        raise ValueError("missing GPU timestamp")
    observed = datetime.strptime(stamp.group(1), '%Y-%m-%d %H:%M:%S').replace(tzinfo=timezone.utc).timestamp()
    if observed > now + 30 or now - observed > 120:
        raise ValueError("stale tmux GPU statistics")
    return output


def gpu_count_from_host():
    # Visible physical GPUs; do not assume two or infer the count from logs.
    output = subprocess.check_output(
        ['nvidia-smi', '-L'], text=True, stderr=subprocess.DEVNULL, timeout=8
    )
    return sum(bool(re.match(r'^GPU \d+:', line)) for line in output.splitlines())


def _read_private_file(path):
    if not isinstance(path, str) or not path.startswith('/') or '\n' in path:
        raise ValueError("private file path must be absolute")
    info = os.lstat(path)
    if not stat.S_ISREG(info.st_mode) or info.st_uid != 0 or info.st_mode & 0o077:
        raise ValueError("private file must be regular, root-owned, and mode 0600")
    with open(path, 'rb') as handle:
        return handle.read(8193)


def private_collector_settings(path):
    """Fail closed for new per-instance configuration; legacy env mode remains."""
    body = _read_private_file(path)
    if len(body) > 8192:
        raise ValueError("collector configuration too large")
    values = json.loads(body.decode('utf-8'))
    expected = {'instance_id', 'gpu_count', 'miner_log', 'ingest_url', 'ingest_key_file'}
    if not isinstance(values, dict) or not expected <= set(values) or set(values) - expected - {'tmux_session'}:
        raise ValueError("invalid collector configuration keys")
    if not isinstance(values['instance_id'], str) or not re.fullmatch(r'[1-9][0-9]*', values['instance_id']):
        raise ValueError("invalid Vast instance id")
    if type(values['gpu_count']) is not int or not 1 <= values['gpu_count'] <= 16:
        raise ValueError("invalid GPU count")
    if values['ingest_url'] != 'https://miner.hacheinteractive.com/vast-ingest':
        raise ValueError("unapproved ingest endpoint")
    if 'tmux_session' in values and (not isinstance(values['tmux_session'], str) or
        not re.fullmatch(r'[A-Za-z0-9_-]{1,64}', values['tmux_session'])):
        raise ValueError('invalid tmux session')
    log = values['miner_log']
    if not isinstance(log, str) or not log.startswith('/') or '\n' in log:
        raise ValueError("invalid miner log path")
    key = _read_private_file(values['ingest_key_file'])
    if not 1 <= len(key) <= 4096 or b'\n' in key or b'\r' in key:
        raise ValueError("invalid private HMAC key")
    if gpu_count_from_host() != values['gpu_count']:
        raise ValueError("configured GPU count differs from physical GPUs")
    return values, key


def main():
    parser = argparse.ArgumentParser(description='Signed per-GPU telemetry for Vast')
    parser.add_argument('--config', help='root-owned 0600 per-instance collector JSON')
    parser.add_argument('--tmux-session', help='existing tmux session, read-only; legacy mode only')
    args = parser.parse_args()
    if args.config:
        settings, key = private_collector_settings(args.config)
        url = settings['ingest_url']
        instance = settings['instance_id']
        log = settings['miner_log']
        count = settings['gpu_count']
    else:
        # Backward compatibility for already-configured collectors.
        url = os.environ['HACHE_VAST_INGEST_URL']
        if not url.startswith('https://'):
            raise ValueError("Vast ingest must use HTTPS")
        key = os.environ['HACHE_VAST_INGEST_KEY'].encode()
        instance = os.environ['HACHE_VAST_INSTANCE_ID']
        log = os.environ.get('HACHE_SRBMINER_LOG', '/var/log/prl-miner.log')
        configured_count = os.environ.get('HACHE_VAST_GPU_COUNT')
        count = int(configured_count) if configured_count else gpu_count_from_host()
    if args.tmux_session and args.config:
        raise ValueError('tmux session must be specified in private config')
    session = settings.get('tmux_session') if args.config else args.tmux_session
    payload = parse_gpu_table(read_recent_tmux(session) if session else read_recent_log(log), count)
    payload.update({
        'instance_id': instance,
        'sample_id': str(uuid.uuid4()),
        'observed_at': time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime()),
    })
    raw = json.dumps(payload, separators=(',', ':')).encode()
    timestamp = str(int(time.time()))
    signature = hmac.new(key, timestamp.encode() + b'.' + raw, hashlib.sha256).hexdigest()
    request = urllib.request.Request(
        url, data=raw, method='POST',
        headers={
            'Content-Type': 'application/json',
            'User-Agent': 'Hache-Vast-Collector/1.1',
            'X-Hache-Timestamp': timestamp,
            'X-Hache-Signature': signature,
        },
    )
    with urllib.request.urlopen(request, timeout=12) as response:
        if response.status != 202:
            raise RuntimeError("Vast telemetry was not accepted")


if __name__ == '__main__':
    main()

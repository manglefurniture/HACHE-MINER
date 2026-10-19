#!/usr/bin/env python3
"""Regression tests for real per-GPU Vast statistics, with no external services."""
import importlib.util
import os
import pathlib
import tempfile
import time

source = pathlib.Path(__file__).resolve().parents[1] / 'deploy' / 'vast-collector.py'
spec = importlib.util.spec_from_file_location('vast_collector', source)
collector = importlib.util.module_from_spec(spec)
spec.loader.exec_module(collector)


def line(index, speed=379.67, accepted=142, rejected=2, unit='TH/s'):
    return (
        f'#' + str(index) + f' RTX 5090 {speed:.2f} {unit} '
        f'497.4W 0.763 100% 68C 2272 13801 {accepted} {rejected} 0'
    )


def rejects(text, count):
    try:
        collector.parse_gpu_table(text, count)
    except ValueError:
        return
    raise AssertionError("invalid table must not produce valid telemetry")


# ANSI escape codes and two simultaneous GPUs.
two = '\x1b[0m' + line(0) + '\n' + line(1, 381.26, 180, 0) + '\n'
data = collector.parse_gpu_table(two, 2)
assert len(data['gpus']) == 2
assert abs(data['total_ths'] - (379.67 + 381.26)) < 1e-6
assert data['accepted_shares'] == 322 and data['rejected_shares'] == 2
assert data['gpus'][1]['index'] == 1

# One GPU and four GPUs must work. No hard-coded rows[-2:] truncation.
assert len(collector.parse_gpu_table(line(0), 1)['gpus']) == 1
four = '\n'.join(line(i, 120 + i) for i in range(4))
assert len(collector.parse_gpu_table(four, 4)['gpus']) == 4

# The LAST table wins, not a mixture with an earlier interval.
combined = four + '\n' + line(0) + '\n' + line(1)
rejects(combined, 4)
rejects(line(0) + '\n' + line(0), 2)
rejects('Pool speed 780 TH/s\nAccepted 999 shares', 2)
rejects(line(0) + '\n' + line(1) + '\n' + ('noise\n' * 50), 2)

zero = collector.parse_gpu_table(line(0, 0), 1)
assert zero['total_ths'] == 0
assert zero['gpus'][0]['state'] == 'inactive'

# Eight physical GPUs remain present when SRBMiner switches units at low speed.
from math import isclose
units = [('H/s', 1e-12), ('KH/s', 1e-9), ('MH/s', 1e-6),
         ('GH/s', 1e-3), ('TH/s', 1)]
for unit, scale in units:
    eight = '\n'.join(line(i, 123.45, unit=unit) for i in range(8))
    converted = collector.parse_gpu_table(eight, 8)
    assert isclose(converted['total_ths'], 8 * 123.45 * scale)
    assert all(isclose(g['hashrate_ths'], 123.45 * scale) for g in converted['gpus'])

mixed = '\n'.join(line(i, 100, unit=units[i % 5][0]) for i in range(8))
assert isclose(collector.parse_gpu_table(mixed, 8)['total_ths'],
               sum(100 * units[i % 5][1] for i in range(8)))
degraded = '\n'.join(line(i, 0, unit='H/s') if i % 2 == 0 else
                     line(i, 383.25) for i in range(8))
degraded_sample = collector.parse_gpu_table(degraded, 8)
assert degraded_sample['total_ths'] == 1533
assert [g['index'] for g in degraded_sample['gpus']] == list(range(8))
assert [g['state'] for g in degraded_sample['gpus']] == ['inactive', 'mining'] * 4
assert all(g['hashrate_ths'] == 0 for g in degraded_sample['gpus'][::2])
all_zero = '\n'.join(line(i, 0, unit='H/s') for i in range(8))
assert collector.parse_gpu_table(all_zero, 8)['total_ths'] == 0
normal = '\n'.join(line(i, 412) for i in range(8))
assert collector.parse_gpu_table(normal + '\n' + degraded, 8)['total_ths'] == 1533
rejects(normal + '\n' + '\n'.join(degraded.splitlines()[:-1]), 8)
rejects('\n'.join(degraded.splitlines()[::-1]), 8)
rejects(degraded + '\n' + line(7, 0, unit='H/s'), 8)
rejects(degraded.replace('H/s', 'PH/s'), 8)
# Bounds apply after conversion, including large raw H/s values.
assert collector.parse_gpu_table(line(0, 412e12, unit='H/s'), 1)['total_ths'] == 412
rejects(line(0, 20001e12, unit='H/s'), 1)

# A frozen local miner log must NOT masquerade as fresh mining.
with tempfile.TemporaryDirectory() as tmp:
    path = os.path.join(tmp, 'miner.log')
    with open(path, 'w') as handle:
        handle.write(two)
    assert '#0' in collector.read_recent_log(path)
    os.utime(path, (time.time() - 200, time.time() - 200))
    try:
        collector.read_recent_log(path)
        raise AssertionError("stale miner log accepted")
    except ValueError:
        pass


# New managed mode reads a root-only JSON file and a separate HMAC key file.
from unittest import mock
import json
import stat
from types import SimpleNamespace

with tempfile.TemporaryDirectory() as tmp:
    conf = os.path.join(tmp, 'collector.json')
    secret = os.path.join(tmp, 'ingest.key')
    with open(secret, 'wb') as fd:
        fd.write(b'fake-unit-test-hmac-only')
    with open(conf, 'w') as fd:
        json.dump({
            'instance_id': '12345',
            'gpu_count': 2,
            'miner_log': os.path.join(tmp, 'miner.log'),
            'ingest_url': 'https://miner.hacheinteractive.com/vast-ingest',
            'ingest_key_file': secret,
        }, fd)
    with mock.patch.object(collector.os, 'lstat', return_value=SimpleNamespace(
            st_mode=stat.S_IFREG | 0o600, st_uid=0)), \
         mock.patch.object(collector, 'gpu_count_from_host', return_value=2):
        settings, key = collector.private_collector_settings(conf)
        assert settings['instance_id'] == '12345'
        assert key == b'fake-unit-test-hmac-only'
        # A key written with echo's trailing newline must be rejected.
        with open(secret, 'wb') as fd:
            fd.write(b'fake-unit-test-hmac-only\n')
        try:
            collector.private_collector_settings(conf)
            raise AssertionError('LF terminated HMAC key accepted')
        except ValueError:
            pass
        with open(secret, 'wb') as fd:
            fd.write(b'fake-unit-test-hmac-only\r')
        try:
            collector.private_collector_settings(conf)
            raise AssertionError('CR terminated HMAC key accepted')
        except ValueError:
            pass
        with open(secret, 'wb') as fd:
            fd.write(b'fake-unit-test-hmac-only')
        with mock.patch.object(collector, 'gpu_count_from_host', return_value=1):
            try:
                collector.private_collector_settings(conf)
                raise AssertionError('physical GPU mismatch accepted')
            except ValueError:
                pass
        with open(conf, 'r+') as fd:
            value = json.load(fd)
            value['unknown'] = 1
            fd.seek(0)
            json.dump(value, fd)
            fd.truncate()
        try:
            collector.private_collector_settings(conf)
            raise AssertionError('unknown private config key accepted')
        except ValueError:
            pass
    with mock.patch.object(collector.os, 'lstat', return_value=SimpleNamespace(
            st_mode=stat.S_IFREG | 0o644, st_uid=0)):
        try:
            collector.private_collector_settings(conf)
            raise AssertionError('insecure private config mode accepted')
        except ValueError:
            pass

# tmux pane must not be mistaken for current mining when the table freezes.
from unittest import mock as _mock
from datetime import datetime, timezone
now = datetime(2026, 10, 19, 5, 10, 0, tzinfo=timezone.utc).timestamp()
stamp = '[2026-10-19 05:09:30] '
pane = '\n'.join(stamp + line(i, 410+i, 100+i, 0) for i in range(8))
with _mock.patch.object(collector.subprocess, 'check_output', return_value=pane):
    assert collector.tmux_observed_at(pane) == now - 30
    sample = collector.parse_gpu_table(collector.read_recent_tmux('ssh_tmux', now), 8)
    assert sample['total_ths'] > 3200
    try:
        collector.read_recent_tmux('ssh_tmux', now + 180)
        raise AssertionError('stale tmux table accepted')
    except ValueError:
        pass
    try:
        collector.read_recent_tmux('not valid', now)
        raise AssertionError('invalid tmux session accepted')
    except ValueError:
        pass

print('vast collector regression ok')

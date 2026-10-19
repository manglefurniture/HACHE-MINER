#!/usr/bin/env python3
"""Offline regressions for the forced-command receiver; no GPU/network."""
import hashlib
import importlib.util
import io
import json
import os
from pathlib import Path
import tarfile
import tempfile
import unittest
from unittest.mock import patch
import time

SPEC=importlib.util.spec_from_file_location('receiver',Path(__file__).resolve().parents[1]/
                                              'deploy/vast-secure-receiver.py')
r=importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(r)


def fake_policy():
    return {'instance_id':'56667162','machine_id':151300,'host_id':467312,
            'gpu_count':8,'ssh_host':'ssh8.vast.ai','ssh_port':10566,
            'release_commit':'a'*40,'release_sha256':'f'*64,
            'files':{name:'b'*40 for name in r.FILES}}


def make_tar(path):
    raw={}
    with tarfile.open(path,'w:gz') as a:
        for name in sorted(r.FILES):
            body=(name+' approved').encode()
            item=tarfile.TarInfo(name);item.size=len(body)
            a.addfile(item,io.BytesIO(body))
            raw[name]=body
    return raw


def recipe():
    return {'instance_id':'56667162','machine_id':'151300','host_id':'467312',
            'gpu_count':8,'hourly_usd':'4.290370',
            'wallet':'prl1'+'z'*60,
            'pool_host':'prl.kryptex.network','pool_port':8048,
            'miner_version':'3.7.6','release_url':'https://github.com/doktor83/SRBMiner-Multi/releases/download/3.7.6/SRBMiner-Multi-3-7-6-Linux.tar.gz',
            'archive_sha256':'c'*64,'miner_sha256':'d'*64}


class ReceiverTests(unittest.TestCase):
    def test_policy_validates_identity_and_root_mode(self):
        with tempfile.TemporaryDirectory() as directory:
            fn=Path(directory)/'policy.json'
            policy=fake_policy()
            fn.write_text(json.dumps(policy));fn.chmod(0o600)
            with patch.object(r.os,'geteuid',return_value=0):
                got=r.read_policy(fn,owner=os.getuid())
                self.assertEqual(got,policy)
            for bad in [dict(policy,instance_id='../etc/passwd'),dict(policy,gpu_count=0),
                        dict(policy,release_commit='main'),dict(policy,ssh_port=0)]:
                fn.write_text(json.dumps(bad))
                with self.assertRaises(r.Rejected):
                    r.read_policy(fn,owner=os.getuid())
            fn.chmod(0o666)
            with self.assertRaises(r.Rejected):
                r.read_policy(fn,owner=os.getuid())

    def test_approved_stage_is_atomic_and_never_runs_miner(self):
        with tempfile.TemporaryDirectory() as directory:
            root=Path(directory)
            tar=root/'release.tar.gz'
            files=make_tar(tar)
            p=fake_policy()
            p['release_sha256']=hashlib.sha256(tar.read_bytes()).hexdigest()
            p['files']={name:r.blob_sha(v) for name,v in files.items()}
            with patch.object(r.os,'geteuid',return_value=os.getuid()):
                status=r.stage(p,tar.read_bytes(),base=root/'releases',owner_uid=os.getuid())
                self.assertEqual(status,'staged')
                self.assertEqual(r.stage(p,tar.read_bytes(),base=root/'releases',owner_uid=os.getuid()),'already_staged')
                self.assertEqual(list((root/'releases').glob('.stage-*')),[])
                self.assertEqual({f.name:f.read_bytes() for f in (root/'releases'/('a'*40)).iterdir()},files)
                (root/'releases'/('a'*40)/'vast-onstart.sh').write_text('tampered')
                with self.assertRaises(r.Rejected):
                    r.stage(p,tar.read_bytes(),base=root/'releases')
            p['release_sha256']='0'*64
            with self.assertRaises(r.Rejected):
                r.unpack_approved(tar.read_bytes(),p)

    def test_config_secret_private_and_identity_bound(self):
        with tempfile.TemporaryDirectory() as directory:
            root=Path(directory)
            p=fake_policy()
            raw=json.dumps({'recipe':recipe(),'ingest_key':'SafeExamplePrivateBase64UrlValue8888'}).encode()
            with patch.object(r.os,'geteuid',return_value=os.getuid()):
                self.assertEqual(r.configure(p,raw,root/'private',root/'releases',owner_uid=os.getuid()),'configured')
                private=root/'private'
                self.assertEqual({x.name for x in private.iterdir()},
                                 {'approved-recipe.json','ingest.key','host-binding.json'})
                self.assertEqual((private/'ingest.key').stat().st_mode & 0o777,0o600)
                self.assertEqual((private/'ingest.key').read_bytes(),b'SafeExamplePrivateBase64UrlValue8888')
                self.assertEqual(r.configure(p,raw,private,root/'releases',owner_uid=os.getuid()),'configured')
                different=json.dumps({'recipe':recipe(),'ingest_key':'ChangedDifferentPrivateBase64Url88'}).encode()
                with self.assertRaises(r.Rejected):
                    r.configure(p,different,private,root/'releases',owner_uid=os.getuid())
                self.assertEqual((private/'ingest.key').read_bytes(),b'SafeExamplePrivateBase64UrlValue8888')
                numeric=recipe();numeric['machine_id']=151300
                with self.assertRaisesRegex(r.Rejected,'wrong_target_identity'):
                    r.configure(p,json.dumps({'recipe':numeric,'ingest_key':'S'*25}).encode(),private,root/'releases',owner_uid=os.getuid())
                wrong=recipe();wrong['instance_id']='999999'
                with self.assertRaisesRegex(r.Rejected,'wrong_target_identity'):
                    r.configure(p,json.dumps({'recipe':wrong,'ingest_key':'S'*25}).encode(),private,root/'releases',owner_uid=os.getuid())
                with self.assertRaises(r.Rejected):
                    r.configure(p,b'{incomplete',private,root/'releases',owner_uid=os.getuid())

    def test_forced_command_allows_only_three_exact_actions(self):
        p=fake_policy()
        for cmd in ['date','bash -i','stage ../evil '+('a'*40),
                    'stage 56667162 main','stage 56667162 '+('b'*40),
                    'stage 56667162 '+('a'*40)+';bash','configure 1 '+('a'*40)]:
            with self.subTest(cmd=cmd),self.assertRaises(r.Rejected):
                r.dispatch(cmd,io.BytesIO(b''),p)

    def test_stage_requires_correct_sha_and_disallows_links(self):
        with tempfile.TemporaryDirectory() as tmp:
            path=Path(tmp)/'symlink.tar.gz'
            with tarfile.open(path,'w:gz') as tar:
                for name in r.FILES:
                    info=tarfile.TarInfo(name)
                    info.type=tarfile.SYMTYPE
                    info.linkname='/etc/passwd'
                    tar.addfile(info)
            p=fake_policy()
            p['release_sha256']=hashlib.sha256(path.read_bytes()).hexdigest()
            with self.assertRaisesRegex(r.Rejected,'archive_type_or_size_wrong'):
                r.unpack_approved(path.read_bytes(),p)

    def test_verified_scripts_larger_than_private_config_can_be_hashed(self):
        with tempfile.TemporaryDirectory() as tmp:
            p=Path(tmp)/'approved.py'
            p.write_bytes(b'# safe expected script\\n'+b'x'*16000)
            p.chmod(0o700)
            with self.assertRaisesRegex(r.Rejected,'private_file_oversize'):
                r.private_file(p,owner=os.getuid())
            data=r.private_file(p,owner=os.getuid(),max_bytes=300000)
            self.assertEqual(r.blob_sha(data),r.blob_sha(p.read_bytes()))

    def test_apply_refuses_unstaged_release(self):
        with tempfile.TemporaryDirectory() as tmp:
            with self.assertRaisesRegex(r.Rejected,'release_not_staged'):
                r.apply(fake_policy(),base=Path(tmp)/'missing',private=Path(tmp))


if __name__=='__main__':
    unittest.main()

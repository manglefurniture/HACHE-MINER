#!/usr/bin/env python3
"""VAST-004 forced-command SSH receiver; *never* starts, rents or stops a GPU.

Install on the GPU as root-owned code BEFORE attaching a narrowly scoped SSH
key. Authorize only:
command="/usr/bin/python3 /opt/hache-vast/bin/vast-secure-receiver.py",restrict,no-pty ssh-ed25519 ...
Do not retain an unrestricted root SSH key for the same automation identity.
The permitted SSH_ORIGINAL_COMMAND grammar is exact and has no shell input.
"""
import hashlib
import io
import json
import os
from pathlib import Path
import re
import shutil
import stat
import subprocess
import sys
import tarfile
import tempfile
import fcntl

BASE = Path('/root/hache-vast/releases')
PRIVATE = Path('/etc/hache-vast')
POLICY = Path('/etc/hache-vast/receiver-policy.json')
FILES = frozenset(('vast-auto-prepare.py','vast-instance-prepare.py',
                   'vast-onstart.sh','vast-collector.py'))
HEX40 = re.compile(r'[0-9a-f]{40}\Z')
HEX64 = re.compile(r'[0-9a-f]{64}\Z')
INSTANCE = re.compile(r'[1-9][0-9]{3,14}\Z')
LIMIT = 2*1024*1024
RECIPE_FIELDS = frozenset(('instance_id','machine_id','host_id','gpu_count',
                           'hourly_usd','wallet','pool_host','pool_port',
                           'miner_version','release_url','archive_sha256',
                           'miner_sha256'))
BINDING_FIELDS = frozenset(('instance_id','machine_id','host_id','gpu_count',
                            'ssh_host','ssh_port'))


class Rejected(Exception):
    pass


def private_file(path, owner=0, max_bytes=8192):
    file=Path(path)
    fd=os.open(file,os.O_RDONLY|getattr(os,'O_NOFOLLOW',0))
    try:
        info=os.fstat(fd)
        if (not stat.S_ISREG(info.st_mode) or info.st_uid!=owner or
                info.st_nlink!=1 or info.st_mode & 0o077):
            raise Rejected('private_file_unsafe')
        with os.fdopen(fd,'rb',closefd=False) as stream:
            blob=stream.read(max_bytes+1)
        if len(blob)>max_bytes:
            raise Rejected('private_file_oversize')
        return blob
    finally:
        os.close(fd)


def read_policy(filename=POLICY, owner=0):
    try:
        value=json.loads(private_file(filename, owner))
        expect={'instance_id','machine_id','host_id','gpu_count',
                'ssh_host','ssh_port','release_commit','release_sha256','files'}
        if not isinstance(value,dict) or set(value)!=expect:
            raise Rejected('invalid_receiver_policy')
        if (type(value['instance_id']) is not str or
                not INSTANCE.fullmatch(value['instance_id']) or
                type(value['machine_id']) is not int or value['machine_id']<1 or
                type(value['host_id']) is not int or value['host_id']<1 or
                type(value['gpu_count']) is not int or not 1<=value['gpu_count']<=16 or
                type(value['ssh_host']) is not str or
                not re.fullmatch('[a-zA-Z0-9.-]{2,253}',value['ssh_host']) or
                type(value['ssh_port']) is not int or not 1<=value['ssh_port']<=65535 or
                type(value['release_commit']) is not str or not HEX40.fullmatch(value['release_commit']) or
                type(value['release_sha256']) is not str or not HEX64.fullmatch(value['release_sha256']) or
                not isinstance(value['files'],dict) or set(value['files'])!=FILES or
                any(type(v) is not str or not HEX40.fullmatch(v) for v in value['files'].values())):
            raise Rejected('invalid_receiver_policy')
        return value
    except (OSError,ValueError,TypeError,KeyError):
        raise Rejected('policy_unavailable') from None


def blob_sha(data):
    return hashlib.sha1(b'blob '+str(len(data)).encode()+bytes([0])+data).hexdigest()


def read_payload(src,limit=LIMIT):
    payload=src.read(limit+1)
    if not payload or len(payload)>limit:
        raise Rejected('invalid_payload_length')
    return payload


def secure_dir(path, *, create=False, owner_uid=0):
    path=Path(path)
    if create and not path.exists() and not path.is_symlink():
        path.mkdir(parents=True,mode=0o700)
    s=path.lstat()
    if not stat.S_ISDIR(s.st_mode) or s.st_uid!=owner_uid or s.st_mode & 0o077:
        raise Rejected('unsafe_private_directory')
    return path


def lock_release(base=BASE,*,owner_uid=0):
    base=secure_dir(base,create=True,owner_uid=owner_uid)
    fd=os.open(base/'.provision.lock',os.O_CREAT|os.O_RDWR|
               getattr(os,'O_NOFOLLOW',0),0o600)
    info=os.fstat(fd)
    if not stat.S_ISREG(info.st_mode) or info.st_uid!=owner_uid or info.st_mode & 0o077:
        os.close(fd)
        raise Rejected('unsafe_lock')
    fcntl.flock(fd,fcntl.LOCK_EX)
    return fd


def unpack_approved(data,policy):
    if hashlib.sha256(data).hexdigest()!=policy['release_sha256']:
        raise Rejected('archive_digest_wrong')
    with tarfile.open(fileobj=io.BytesIO(data),mode='r:gz') as tar:
        members=tar.getmembers()
        if len(members)!=len(FILES) or {m.name for m in members}!=FILES:
            raise Rejected('archive_members_wrong')
        files={}
        for m in members:
            if not m.isfile() or m.size<1 or m.size>300000:
                raise Rejected('archive_type_or_size_wrong')
            inp=tar.extractfile(m)
            if inp is None:
                raise Rejected('archive_unreadable')
            body=inp.read(m.size+1)
            if len(body)!=m.size or blob_sha(body)!=policy['files'][m.name]:
                raise Rejected('git_blob_mismatch')
            files[m.name]=body
        return files


def verify_release(folder, expected, *, owner_uid=0):
    if folder.is_symlink() or not folder.is_dir():
        raise Rejected('release_missing_or_replaced')
    if {p.name for p in folder.iterdir()}!=set(expected):
        raise Rejected('release_extra_or_missing_files')
    for name,data in expected.items():
        item=folder/name
        info=item.lstat()
        if (not stat.S_ISREG(info.st_mode) or info.st_uid!=owner_uid or
                info.st_mode & 0o022 or item.read_bytes()!=data):
            raise Rejected('staged_release_changed')


def stage(policy,payload,base=BASE,*,owner_uid=0):
    expected=unpack_approved(payload,policy)
    dest=Path(base)/policy['release_commit']
    base=secure_dir(base,create=True,owner_uid=owner_uid)
    fd=lock_release(base,owner_uid=owner_uid)
    try:
        if dest.exists() or dest.is_symlink():
            verify_release(dest,expected,owner_uid=owner_uid)
            return 'already_staged'
        tmp=Path(tempfile.mkdtemp(prefix='.stage-',dir=base))
        try:
            for name,data in expected.items():
                item=tmp/name
                fdout=os.open(item,os.O_WRONLY|os.O_CREAT|os.O_EXCL|
                              getattr(os,'O_NOFOLLOW',0),0o700)
                with os.fdopen(fdout,'wb') as out:
                    out.write(data)
                    out.flush()
                    os.fsync(out.fileno())
            verify_release(tmp,expected,owner_uid=owner_uid)
            os.rename(tmp,dest)
        finally:
            if tmp.exists():
                shutil.rmtree(tmp)
        return 'staged'
    finally:
        os.close(fd)


def validate_private_config(policy,raw):
    if len(raw)>16384:
        raise Rejected('configuration_oversize')
    try:
        doc=json.loads(raw)
        if not isinstance(doc,dict) or set(doc)!= {'recipe','ingest_key'}:
            raise Rejected('invalid_config_envelope')
        r=doc['recipe']; secret=doc['ingest_key']
        if not isinstance(r,dict) or set(r)!=RECIPE_FIELDS:
            raise Rejected('invalid_miner_recipe')
        if (r['instance_id']!=policy['instance_id'] or
                str(r['machine_id'])!=str(policy['machine_id']) or
                str(r['host_id'])!=str(policy['host_id']) or
                type(r['gpu_count']) is not int or
                r['gpu_count']!=policy['gpu_count']):
            raise Rejected('wrong_target_identity')
        if (type(secret) is not str or not re.fullmatch('[A-Za-z0-9_-]{24,256}',secret)):
            raise Rejected('invalid_hmac_secret')
        # The GPU installer validates the approved wallet, source URL, TLS pool,
        # hashes and version again before preparing SRBMiner.
        if type(r['wallet']) is not str or not re.fullmatch('prl1[a-z0-9]{20,120}',r['wallet']):
            raise Rejected('invalid_wallet')
        if r['pool_host']!='prl.kryptex.network' and not (
                type(r['pool_host']) is str and r['pool_host'].endswith('.kryptex.network')):
            raise Rejected('unapproved_pool')
        if type(r['pool_port']) is not int or not 1<=r['pool_port']<=65535:
            raise Rejected('invalid_pool_port')
        return (json.dumps(r,sort_keys=True,separators=(',',':'))+'\n').encode(),secret.encode()
    except (TypeError,KeyError,ValueError):
        raise Rejected('configuration_rejected') from None


def install_private(directory, files, *, owner_uid=0):
    """Atomic create of each immutable private file, fail before any replacements."""
    directory=secure_dir(directory,create=True,owner_uid=owner_uid)
    for name,data in files.items():
        path=directory/name
        if path.exists() or path.is_symlink():
            if path.is_symlink() or private_file(path,owner=owner_uid)!=data:
                raise Rejected('existing_private_config_conflict')
    for name,data in files.items():
        dest=directory/name
        if dest.exists():
            continue
        fd=os.open(dest,os.O_CREAT|os.O_EXCL|os.O_WRONLY|
                   getattr(os,'O_NOFOLLOW',0),0o600)
        with os.fdopen(fd,'wb') as out:
            out.write(data);out.flush();os.fsync(out.fileno())


def configure(policy,payload,private=PRIVATE,base=BASE,*,owner_uid=0):
    recipe,key=validate_private_config(policy,payload)
    binding={name:policy[name] if name not in ('machine_id','host_id') else str(policy[name])
             for name in BINDING_FIELDS}
    files={'approved-recipe.json':recipe,'ingest.key':key,
           'host-binding.json':(json.dumps(binding,sort_keys=True,separators=(',',':'))+'\n').encode()}
    fd=lock_release(base,owner_uid=owner_uid)
    try:
        install_private(private,files,owner_uid=owner_uid)
    finally:
        os.close(fd)
    return 'configured'


def apply(policy,base=BASE,private=PRIVATE,runner=subprocess.run):
    path=Path(base)/policy['release_commit']
    if not path.is_dir() or path.is_symlink():
        raise Rejected('release_not_staged')
    for name,sha in policy['files'].items():
        file=path/name
        if file.is_symlink() or blob_sha(private_file(file))!=sha:
            raise Rejected('release_tampered')
    private_file(Path(private)/'ingest.key')
    private_file(Path(private)/'approved-recipe.json')
    private_file(Path(private)/'host-binding.json')
    if Path('/root/onstart.sh').exists() or Path('/root/onstart.sh').is_symlink():
        raise Rejected('existing_auto_start_hook')
    result=runner(['/usr/bin/python3',str(path/'vast-auto-prepare.py'),
                   '--apply','--confirm-instance',policy['instance_id']],
                  stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,
                  timeout=160,check=False)
    if result.returncode:
        raise Rejected('installer_failed')
    return 'miner_prepared_not_started'


def dispatch(original,src,policy,*,base=BASE,private=PRIVATE):
    if not isinstance(original,str):
        raise Rejected('missing_forced_command')
    parts=original.split(' ')
    if len(parts)!=3 or '' in parts:
        raise Rejected('unsupported_ssh_command')
    action,instance,commit=parts
    if (instance!=policy['instance_id'] or commit!=policy['release_commit'] or
            action not in ('stage','configure','apply')):
        raise Rejected('not_approved_for_target')
    if action=='stage':
        return stage(policy,read_payload(src),base=base)
    if action=='configure':
        return configure(policy,read_payload(src,16384),private=private,base=base)
    if src.read(1):
        raise Rejected('unexpected_stdin')
    return apply(policy,base=base,private=private)


def main():
    try:
        if os.geteuid()!=0:
            raise Rejected('root_required')
        policy=read_policy()
        action=os.environ.get('SSH_ORIGINAL_COMMAND')
        status=dispatch(action,sys.stdin.buffer,policy)
        print(json.dumps({'ok':True,'status':status,'mining_started':False}))
    except (Rejected,OSError,tarfile.TarError,subprocess.TimeoutExpired):
        # Neither secrets nor arbitrary remote paths may appear in SSH output.
        print(json.dumps({'ok':False,'error':'restricted_receiver_not_ready'}))
        sys.exit(2)


if __name__=='__main__':
    main()

#!/usr/bin/env python3
"""Offline, no-GPU/no-network regression for safe Vast instance staging."""
import hashlib
import importlib.util
import json
from pathlib import Path
import stat
import tempfile
from unittest import mock

source = Path(__file__).resolve().parents[1] / "deploy" / "vast-instance-prepare.py"
spec = importlib.util.spec_from_file_location("vast_instance_prepare", source)
mod = importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)


def expect_reject(fn, reason):
    try:
        fn()
    except ValueError:
        return
    raise AssertionError(reason)


with tempfile.TemporaryDirectory() as folder:
    root = Path(folder)
    launcher = root / "launch.sh"
    miner = root / "SRBMiner-MULTI"
    key = root / "ingest.key"
    artifact = root / "miner-download"
    wallet = "prl1" + "a" * 60 + ".vast-12345"
    launcher.write_text("#!/bin/bash\n# pearlhash --disable-cpu\n" + wallet + "\n")
    launcher.chmod(0o700)
    key.write_text("fake-unit-test-secret")
    key.chmod(0o600)
    artifact.write_bytes(b"synthetic-miner-test-only")
    artifact.chmod(0o700)

    manifest = {
        "instance_id": "12345",
        "machine_id": "m-10",
        "host_id": "h-20",
        "gpu_count": 2,
        "hourly_usd": "0.19",
        "miner_path": str(miner),
        "miner_source": str(artifact),
        "miner_sha256": hashlib.sha256(artifact.read_bytes()).hexdigest(),
        "launcher_path": str(launcher),
        "wallet_worker": wallet,
        "miner_log": str(root / "miner.log"),
        "ingest_key_file": str(key),
    }
    manifest_path = root / "manifest.json"
    manifest_path.write_text(json.dumps(manifest))
    manifest_path.chmod(0o600)

    with mock.patch.object(mod, "private_file", side_effect=lambda p: Path(p)):
        assert mod.load_manifest(str(manifest_path)) == manifest
        wrong = dict(manifest, gpu_count=0)
        manifest_path.write_text(json.dumps(wrong))
        expect_reject(lambda: mod.load_manifest(str(manifest_path)), "zero GPUs accepted")
        wrong = dict(manifest, wallet_worker=wallet.replace("12345", "99999"))
        manifest_path.write_text(json.dumps(wrong))
        expect_reject(lambda: mod.load_manifest(str(manifest_path)), "wrong worker accepted")
        wrong = dict(manifest, surprise="x")
        manifest_path.write_text(json.dumps(wrong))
        expect_reject(lambda: mod.load_manifest(str(manifest_path)), "unknown field accepted")

    with mock.patch.object(mod.os, "geteuid", return_value=0), \
         mock.patch.object(mod, "private_file", side_effect=lambda p: Path(p)), \
         mock.patch.object(mod.shutil, "which", return_value="/usr/bin/tool"), \
         mock.patch.object(mod.subprocess, "check_output", return_value="GPU 0: Mock\nGPU 1: Mock\n"):
        assert mod.checks(manifest, source.parent) == artifact
        with mock.patch.object(mod.subprocess, "check_output", return_value="GPU 0: Mock\n"):
            expect_reject(lambda: mod.checks(manifest, source.parent), "GPU count mismatch accepted")
        with mock.patch.dict(manifest, miner_sha256="0" * 64):
            expect_reject(lambda: mod.checks(manifest, source.parent), "bad SHA accepted")
        with mock.patch.dict(manifest, wallet_worker="prl1" + "b" * 60 + ".vast-12345"):
            expect_reject(lambda: mod.checks(manifest, source.parent), "launcher wallet mismatch accepted")

    # File installation never overwrites differing content or follows symlinks.
    dest = root / "configured" / "private.json"
    mod.stage_file(dest, b"config-v1", 0o600)
    assert dest.read_bytes() == b"config-v1"
    assert stat.S_IMODE(dest.stat().st_mode) == 0o600
    mod.stage_file(dest, b"config-v1", 0o600)
    expect_reject(lambda: mod.stage_file(dest, b"config-v2", 0o600), "overwrite allowed")
    linked = root / "linked"
    linked.symlink_to(dest)
    expect_reject(lambda: mod.stage_file(linked, b"config-v1", 0o600), "symlink allowed")

    prepared = mod.prepared_files(manifest, source.parent)
    assert b"12345" in prepared[1][1]
    assert b"ingest.key" in prepared[1][1]
    assert wallet.encode() not in b"".join(data for _, data, _ in prepared)
    assert b"flock -n" in prepared[-1][1]

print("vast instance preparation regression ok")

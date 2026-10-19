#!/usr/bin/env python3
"""Prepare one already-authorized Vast host without starting mining or renting GPUs.

Default --check is read-only. --apply only stages files on that host; it does
not start/restart a miner, register a VPS record, or call the Vast API.
"""
import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import stat
import subprocess
import sys
import tempfile
from decimal import Decimal, InvalidOperation

INGEST = "https://miner.hacheinteractive.com/vast-ingest"
FIELDS = {
    "instance_id", "machine_id", "host_id", "gpu_count", "hourly_usd",
    "miner_path", "miner_sha256", "launcher_path", "wallet_worker",
    "miner_log", "ingest_key_file",
}
OPTIONAL = {"miner_source"}


def private_file(path):
    if not isinstance(path, str) or not path.startswith("/") or "\n" in path:
        raise ValueError("expected an absolute private file path")
    meta = os.lstat(path)
    if not stat.S_ISREG(meta.st_mode) or meta.st_uid != 0 or meta.st_mode & 0o077:
        raise ValueError("private file must be regular, root-owned and not group/world accessible")
    return Path(path)


def load_manifest(path):
    data = private_file(path).read_bytes()
    if len(data) > 8192:
        raise ValueError("manifest too large")
    m = json.loads(data)
    if not isinstance(m, dict) or not FIELDS <= set(m) or set(m) - FIELDS - OPTIONAL:
        raise ValueError("unexpected or missing manifest fields")
    for name in ("instance_id", "machine_id", "host_id"):
        if not isinstance(m[name], str) or not re.fullmatch(r"[a-zA-Z0-9_-]{1,64}", m[name]):
            raise ValueError(f"invalid {name}")
    if not re.fullmatch(r"[1-9][0-9]*", m["instance_id"]):
        raise ValueError("Vast instance id must be numeric")
    if type(m["gpu_count"]) is not int or not 1 <= m["gpu_count"] <= 16:
        raise ValueError("GPU count must be 1-16")
    try:
        rate = Decimal(str(m["hourly_usd"]))
        if not rate.is_finite() or not Decimal("0") < rate < Decimal("1000"):
            raise ValueError("invalid hourly USD rate")
    except (InvalidOperation, TypeError):
        raise ValueError("invalid hourly USD rate") from None
    if not isinstance(m["miner_sha256"], str) or not re.fullmatch(r"[0-9a-fA-F]{64}", m["miner_sha256"]):
        raise ValueError("miner SHA-256 is required")
    for field in ("miner_path", "launcher_path", "miner_log", "ingest_key_file"):
        value = m[field]
        if not isinstance(value, str) or not value.startswith("/") or "\n" in value:
            raise ValueError(f"{field} must be an absolute path")
    if "miner_source" in m:
        value = m["miner_source"]
        if not isinstance(value, str) or not value.startswith("/") or "\n" in value:
            raise ValueError("miner_source must be an absolute path")
    miner_name = Path(m["miner_path"]).name
    if not re.fullmatch(r"[a-zA-Z0-9_-]{1,15}", miner_name):
        raise ValueError("miner basename cannot be matched safely with pgrep")
    worker = m["wallet_worker"]
    if (not isinstance(worker, str) or not re.fullmatch(
            r"prl1[a-z0-9]{20,120}\.vast-" + re.escape(m["instance_id"]), worker)):
        raise ValueError("wallet_worker must contain a PRL address and unique vast-instance_id")
    return m


def sha256_file(path):
    h = hashlib.sha256()
    with open(path, "rb") as fd:
        for block in iter(lambda: fd.read(1024 * 1024), b""):
            h.update(block)
    return h.hexdigest()


def checks(m, bundle):
    if os.geteuid() != 0:
        raise ValueError("run this tool as root on the authorized Vast host")
    if not shutil.which("nvidia-smi") or not shutil.which("flock"):
        raise ValueError("nvidia-smi and flock must be available")
    gpu_lines = subprocess.check_output(
        ["nvidia-smi", "-L"], text=True, timeout=10
    ).splitlines()
    actual = sum(bool(re.match(r"^GPU \d+:", line)) for line in gpu_lines)
    if actual != m["gpu_count"]:
        raise ValueError("GPU count mismatch; stopping before installation")

    launcher = private_file(m["launcher_path"])
    if not os.access(launcher, os.X_OK):
        raise ValueError("launcher must be executable")
    # This is a useful consistency guard, not proof of the actual payout address.
    launcher_text = launcher.read_text(encoding="utf-8")
    if m["wallet_worker"] not in launcher_text:
        raise ValueError("approved wallet.worker not found in private launcher")
    if "pearlhash" not in launcher_text or "--disable-cpu" not in launcher_text:
        raise ValueError("launcher must configure pearlhash and disable CPU mining")

    key = private_file(m["ingest_key_file"]).read_bytes()
    if not 1 <= len(key) <= 4096 or b"\n" in key or b"\r" in key:
        raise ValueError("invalid private HMAC key")

    target = Path(m["miner_path"])
    source = target if target.is_file() else Path(m.get("miner_source", ""))
    if not source.is_file() or source.is_symlink():
        raise ValueError("verified miner binary or miner_source is missing")
    if sha256_file(source).lower() != m["miner_sha256"].lower():
        raise ValueError("miner checksum mismatch")
    if target.is_file() and not os.access(target, os.X_OK):
        raise ValueError("miner is not executable")
    for name in ("vast-onstart.sh", "vast-collector.py"):
        item = bundle / name
        if not item.is_file() or item.is_symlink():
            raise ValueError("incomplete version-pinned bundle")
    if not Path("/usr/bin/python3").is_file() or not Path("/usr/bin/flock").is_file():
        raise ValueError("expected /usr/bin/python3 and /usr/bin/flock for cron")
    return source


def prepared_files(m, bundle, *, install_onstart=True):
    onstart_conf = "\n".join([
        f"VAST_LAUNCHER={m['launcher_path']}",
        f"VAST_MINER={m['miner_path']}",
        f"VAST_MINER_LOG={m['miner_log']}",
        "VAST_ONSTART_LOCK=/run/lock/hache-prl-vast-onstart.lock", "",
    ])
    collector_conf = json.dumps({
        "instance_id": m["instance_id"],
        "gpu_count": m["gpu_count"],
        "miner_log": m["miner_log"],
        "ingest_url": INGEST,
        "ingest_key_file": m["ingest_key_file"],
    }, sort_keys=True) + "\n"
    cron = (
        "* * * * * root /usr/bin/flock -n /run/lock/hache-vast-collector.lock "
        "/usr/bin/python3 /opt/hache-vast/vast-collector.py "
        "--config /etc/hache-vast/collector.json "
        ">> /var/log/hache-vast-collector.log 2>&1\n"
    )
    result = [
        (Path("/etc/hache-vast/onstart.conf"), onstart_conf.encode(), 0o600),
        (Path("/etc/hache-vast/collector.json"), collector_conf.encode(), 0o600),
        (Path("/opt/hache-vast/vast-collector.py"), (bundle / "vast-collector.py").read_bytes(), 0o700),
        (Path("/etc/cron.d/hache-vast-collector"), cron.encode(), 0o644),
    ]
    if install_onstart:
        result.insert(3, (Path("/root/onstart.sh"),
                          (bundle / "vast-onstart.sh").read_bytes(), 0o700))
    return tuple(result)


def stage_file(path, data, mode):
    """Never silently overwrite a preexisting custom configuration."""
    if path.is_symlink() or any(parent.is_symlink() for parent in path.parents):
        raise ValueError(f"refusing symlink destination or parent: {path}")
    if path.exists():
        info = path.lstat()
        if (not stat.S_ISREG(info.st_mode) or stat.S_IMODE(info.st_mode) != mode
                or info.st_uid != os.geteuid() or path.read_bytes() != data):
            raise ValueError(f"refusing unsafe or different existing file: {path}")
        return
    path.parent.mkdir(parents=True, exist_ok=True)
    fd, pending = tempfile.mkstemp(prefix=".hache-vast-", dir=str(path.parent))
    try:
        with os.fdopen(fd, "wb") as handle:
            handle.write(data)
            handle.flush()
            os.fsync(handle.fileno())
        os.chmod(pending, mode)
        # No other setup process should install simultaneously.
        if path.exists() or path.is_symlink():
            raise ValueError(f"destination appeared during install: {path}")
        os.link(pending, path)
    finally:
        os.unlink(pending)


def apply(m, source, bundle, *, install_onstart=True):
    # Refuse to modify files on an instance with the same miner already running.
    proc = subprocess.run(["pgrep", "-x", Path(m["miner_path"]).name],
                          stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
                          check=False)
    if proc.returncode != 1:
        raise ValueError("miner already active or process check unavailable; no changes made")
    target = Path(m["miner_path"])
    if not install_onstart and (Path('/root/onstart.sh').exists() or Path('/root/onstart.sh').is_symlink()):
        raise ValueError('existing start hook requires separate approval')
    files = list(prepared_files(m, bundle, install_onstart=install_onstart))
    # Preflight all destinations before creating anything.
    for dest, data, mode in files:
        if dest.is_symlink() or any(parent.is_symlink() for parent in dest.parents):
            raise ValueError(f"symlink destination/parent: {dest}; manual review required")
        if dest.exists():
            info = dest.lstat()
            if (not stat.S_ISREG(info.st_mode) or stat.S_IMODE(info.st_mode) != mode
                    or info.st_uid != os.geteuid() or dest.read_bytes() != data):
                raise ValueError(f"existing file is unsafe or differs: {dest}; manual backup/review required")
    if source != target:
        if target.exists() or target.is_symlink():
            raise ValueError("miner target already exists")
        stage_file(target, source.read_bytes(), 0o700)
        if sha256_file(target).lower() != m["miner_sha256"].lower():
            raise ValueError("installed binary checksum mismatch")
    # Cron is deliberately last; no miner is launched by this command.
    for dest, data, mode in files:
        stage_file(dest, data, mode)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--manifest", required=True, help="private root-owned 0600 host manifest")
    parser.add_argument("--apply", action="store_true", help="stage files, without starting mining")
    parser.add_argument("--confirm-instance", help="required only with --apply")
    args = parser.parse_args()
    m = load_manifest(args.manifest)
    if args.apply and args.confirm_instance != m["instance_id"]:
        parser.error("--apply requires --confirm-instance matching the manifest")
    source = checks(m, Path(__file__).resolve().parent)
    if args.apply:
        apply(m, source, Path(__file__).resolve().parent)
        print("VAST-001 STAGED; miner not started; VPS registration and watchdog verification pending")
    else:
        print("VAST-001 PREFLIGHT OK; no files changed; actual wallet/pool, price and provider IDs require operator verification")


if __name__ == "__main__":
    try:
        main()
    except (OSError, ValueError, subprocess.SubprocessError) as exc:
        print(f"VAST-001 NOT READY: {exc}", file=sys.stderr)
        sys.exit(1)

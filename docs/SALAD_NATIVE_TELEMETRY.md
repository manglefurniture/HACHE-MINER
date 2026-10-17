# Direct Salad miner telemetry

The direct collector is an additive path alongside Salad's existing container logs and the Vast.ai collector. It stores one row per provider-authenticated Salad instance and per GPU, with immutable sample IDs and provider provenance. The new table is never read by current autorreallocation rules.

## Identity and request validation

- The image obtains a short-lived JWT from Salad IMDS for each send. HACHE-MINER validates its ES256 signature using Salad's published workload JWKS, checks `exp`, `nbf`, and `iat`, and derives machine identity from signed claims.
- The JWT's signed workload and machine identity must map to exactly one active instance in the latest 10-minute snapshot returned by the Salad API poller. The client does not choose `instance_id`, organization, project, or group in its payload.
- The compact JSON body is HMAC-bound to the exact JWT and a 90-second request timestamp. HTTPS is required. The receiver rejects unknown fields, stale samples, duplicate IDs, out-of-order samples, incomplete GPU index sets, implausible values, and inconsistent totals.
- Rolling averages are independently recalculated from previously accepted per-GPU samples in MariaDB. Raw miner output, wallet values, pool passwords, and API keys are not sent or included in receiver errors.
- Telemetry inventory mapping is refreshed only after a complete authenticated Salad API instance response. It is separate from `miner_observations` and the existing reallocation rules.

## Preparation and activation gates

1. Review the image and HACHE-MINER pull requests and require all existing quality checks plus the new security regression to pass.
2. The new container image must first be built and published under its immutable version/digest by the container repository's reviewed release workflow. Keep the existing 3.7.5 and `latest` digests for rollback.
3. Deploy the HACHE-MINER release through the existing release mechanism. On an existing host, install the release's explicit `/salad-ingest.php` FastCGI route by running `deploy/upgrade-miner-vhost.sh` as root; it validates Nginx and rolls back a failed reload. New hosts receive the route from `deploy/install-https-web.sh`.
4. Apply the additive SQL migration separately using `deploy/apply-salad-telemetry-migration.sh`; it requires the pinned release, root, and a successful root-only MariaDB backup.
5. Set `HACHE_SALAD_INGEST_URL=https://miner.hacheinteractive.com/salad-ingest.php` on the intended Salad container configuration and point a controlled test instance at the immutable image digest. Do not use production groups for the first check.
6. Verify accepted and rejected telemetry, per-GPU trends, mobile layout, and unchanged automatic reallocation checks. Keep direct readings in simulation until separately authorized for integration into allocation rules.

The receiver and UI are prepared in source only. This branch does not deploy HACHE-MINER, run the SQL migration, set Salad environment variables, create a Salad group, publish an image, or execute a GPU trial.

# Operations runbook

## Backup and restore

- Take logical backups before schema migration or key rotation.
- Restore drills should verify: confirmation consume table, idempotency lease rows,
  and event append sequences match documented invariants.
- Keep backup retention aligned with token spent-row retention policy.

## PostgreSQL deployment

- Use separate database roles for migration admin vs application runtime where possible.
- Connection pools must not share fence tokens across processes incorrectly; each worker
  acquires its own lease.
- For concurrency proofs, use disposable namespaces; never run chaos tests against production data.

## Token and key revocation

1. Identify `tenant_id`, `purpose`, and active `key_id` from operator config.
2. Insert replacement key material via `TokenKeyProvider` configuration (reference slice demonstrates tenant provider).
3. Mark prior `key_id` revoked in authoritative key state; reject new proposals signed with revoked keys.
4. Allow in-flight tokens to expire naturally unless emergency revoke requires shortening TTL at authorizer.

## Monitoring

- Treat observability projections (epochs, stale executor markers) as hygiene signals (`G-OBS-001`), not substitutes for security gates.
- Alert on idempotency lease takeover rates and event collision refusals.

## MCP companion

- Deploy MCP companion only alongside a matching `dart-core` subject digest.
- Path dependency topology is private; hosted replacement requires separate owner approval.

## Hosted attestation

GitHub artifact attestation is **not** executed on the current plan tier. Local
provenance statements are verified in `P9-supply-chain-evidence.json` only.

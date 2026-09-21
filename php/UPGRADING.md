# PHP SDK upgrading

Pre-1.0 (`0.0.1`) releases may change candidate API until stable entries are frozen
in `api/package-surface-v1.json`.

## Spec revision 2026-09

- Align `composer.json` lockstep pins with the `2026-09` semantic manifest.
- Run `composer test` and conformance layers after updating fixtures.

## Confirmation and tokens

- Wire `ConfirmationService` with trusted authorizer and durable `ConfirmationTokenStore` for cross-process replay protection.
- Migrate wire format per `token-replay-v1` contract; do not silently change at1 fields.

## Idempotency

- Replace process-local deduplication with `PdoPostgresIdempotencyStore` (or equivalent) before horizontal scale-out.
- Respect lease TTL bounds on acquire.

## Event store

- Move journal append to `PdoPostgresEventStore` when collision detection across writers is required.

## SQL

Follow [docs/migrations.md](../docs/migrations.md): backup, rehearse, forward-only rollout.

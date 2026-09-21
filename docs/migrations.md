# Migrations and schema rollout

SQL artifacts for durable confirmation tokens, idempotency leases, and event storage
ship with the PHP and Dart packages (shared SSOT under `php/reference/migrations/`).
This guide is **forward-safe**; it does not promise a universal production down-migration.

## Principles

1. **Backup** the database before applying any migration class.
2. **Rehearse** on a disposable namespace or clone; record commands and exit codes.
3. Apply migrations in order; do not skip version markers recorded by your migrator.
4. Plan **forward recovery** (restore backup + replay) rather than assuming downgrade scripts exist.

## Token and key lifecycle (P3)

- Deploy `confirmation_token` store schema before enabling durable consume.
- Rotate signing keys with overlap: publish new `key_id`, accept both during overlap window, revoke old keys per runbook.
- Cleanup jobs must respect spent-token retention (`RL-019`); premature deletion can reopen replay windows.

## Idempotency and leases (P4)

- Unique arbiter constraints are required for PostgreSQL fencing proofs.
- After worker crash, stale leases may be taken over; zombies must not complete effects without a valid fence token.

## Event store (P5)

- Use storage collision detection (`event_id`, batch hash) as documented in the event-store contract.
- Pagination defaults apply (`RL-012`–`RL-013`); unbounded reads are not a supported production pattern.

## Rollback stance

If a migration must be reversed, restore from backup taken immediately before rollout.
Do not rely on undocumented `DOWN` scripts unless your operator packet explicitly
includes tested reversal steps for your deployment.

## Inventory

See `plans/2026.09.19-№1-AGENTOOLS-HARDENING/findings/P1-migration-inventory.md` for
class-to-owner mapping (operator-facing summary in package `UPGRADING.md` files).

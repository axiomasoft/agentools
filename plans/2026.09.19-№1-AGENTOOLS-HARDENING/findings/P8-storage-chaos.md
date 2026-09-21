# P8.4 PostgreSQL multi-process storage chaos

**Date:** 2026-09-20  
**Receipt:** `artifacts/P8-storage-chaos.json` (`p8-storage-chaos/v1`)  
**Target:** `127.0.0.1:55432` / `agentools_test` / PostgreSQL 16.14  
**Seed:** `1`

Nineteen language-neutral schedules ran in separate PHP and Dart worker
processes against a run-qualified `p8r_*` schema. Ordering used controller
barriers plus observed `pg_stat_activity` / `pg_blocking_pids` edges. Sleep is
not a schedule step. Cleanup dropped only the run schema.

## Evidence

| Check | Result |
|:--|:--|
| PHP/Dart invariant digests | identical (19/19) |
| Repeat PHP trace | byte-identical per schedule |
| Observed blocked edges | 8 (`Lock`) |
| `client_crash` | `tkn-crash`, `idc-crash` |
| `query_cancel` | `tkn-cancel` |
| `backend_terminate` | `tkn-term`, `evt-wait` |
| Mutant `unfenced` | red on `idc-stale` |
| Mutant `non-atomic` | red on `evt-roll` |

Replay commit leaves one digest-bound spent row; rollback/crash/terminate of
the holder lets the waiter consume. One lease owner wins; expiry/takeover and
stale CAS follow the P4 clock contract. Event batches are all-or-nothing;
replay, collision, partial-overlap and keyset pages stay exact.

## Residual owners

- **P9:** CI service/container wiring for the isolated PostgreSQL job.
- Dart production still has no live driver; the P8.4 worker uses `postgres`
  `^3.5.8` as a **test-only** dependency so the Dart actor has one real
  connection. That does not weaken P3–P5 store contracts.

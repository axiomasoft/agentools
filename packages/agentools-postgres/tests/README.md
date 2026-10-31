The schema suite needs PHP >=8.3 with pdo_pgsql, Composer, Python 3 and psql.
Run `composer install` in this package first. `composer test` provisions and
removes its own labelled, ephemeral PostgreSQL 17 Docker container when
`AGENTOOLS_TEST_PG_DSN` is absent. No CI bootstrap script is invoked.

To use an already isolated database, set `AGENTOOLS_TEST_PG_DSN`,
`AGENTOOLS_TEST_PG_USER` and `AGENTOOLS_TEST_PG_PASSWORD`. The DSN must point to
loopback and a database named `agentools_test_<suffix>`. Its owner must be the
connecting role, with no superuser/createdb/createrole privileges, and the database
comment must be `agentools:isolation:v1:<database>:<owner>`. Provision this marker
only on a disposable database. The runner and PHP lifecycle verify it before
creating a schema; PHP repeats the check before cleanup. An explicit invalid DSN
fails instead of falling back to Docker. Only random `p3_<24 hex>` schemas can
be created/dropped by the lifecycle. All owned containers and schemas are removed
even on test failure. No application/dev/production DSN is inferred.

Use `python3 tests/run.py --filter testName` for a focused method and
`python3 tests/no_dev.py` for a clean production autoload check. The gate owns
the exact full `pg-schema` case set and rejects missing, skipped or extra cases.
Tests use the real database clock and fractional timestamptz values; the unresolved
Testing ManualClock truncation finding is outside this package.

`Migrator(PDO, schema)->migrate()` requires an explicitly provisioned application
schema. It serializes migrations with a transaction advisory lock, verifies the
contiguous checksum history, and atomically commits DDL plus migration records.
It refuses caller transactions, unknown history and changed applied files. There
is no destructive down command: rollback rehearsal means a failed DDL transaction
is undone and the previous reader still works after an additive upgrade.

The first slice stores immutable code configuration/registry refs on instances
and bundle pins on sessions. It defers profile publication, scheduler, knowledge
and evaluation tables. Action refs bind to the existing dated SDK EventStore;
there is no parallel Action journal/reducer. Invocation/result, proposal/effect,
response/delivery, usage, payload and inbox/outbox tables hold technical refs.
History, money and canonical usage ownership stay external. Hash/identity changes
raise a constraint conflict; repositories must map SQLSTATE 23505 to the typed
integrity conflict and compare saved hashes for replay. IDs/FKs do not authorize
reads. Repositories must supply tenant predicates, current guards, expected
version and claim fences. Schema triggers enforce version increments but do not
implement a claim repository or transaction boundary with the SDK EventStore.
Those runtime behaviors belong to the following persistence slices.

Retention guards prevent deleting pending/unknown or retained outbox work and
purging payload refs needed by unresolved outbox work. Purge keeps a hash/purpose
tombstone; the external encrypted-byte deletion still belongs to its storage
owner. Results, proposal mappings, usage versions and delivery receipts are
append-only. A database owner can change DDL; production role grants and RLS are
separate deployment decisions, not claims of this isolated schema tier.

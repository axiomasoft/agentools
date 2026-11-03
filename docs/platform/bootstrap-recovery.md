# Target bootstrap and recovery rehearsal

`python3 tools/platform_gate.py e2e --scenario bootstrap-recovery` runs against
a verified isolated PostgreSQL database. It combines the current scripted read
journey and the complete write crash/recovery matrix with a new clean-install
backup/restore test. It requires real PostgreSQL, PHP, `pg_dump`, `pg_restore`,
and the dependencies of both journey suites; a missing case or skip fails.

The restore test installs the target schema and accounting tables into a new
random test schema, runs the actual confirmed write and delivery, stores a
preliminary usage fact and a superseding final correction, then takes a custom
format PostgreSQL backup. After rechecking the database owner and isolation
marker, it removes only its generated test schema, restores the backup and
compares hashes of the durable rows. It checks the correction fold, rebuilds the
Action projection from its journal against the original digest, repeats the
source and delivery, and checks revocation before delivery. The read and write
partitions separately exercise current access, immutable pins, the 19 crash
boundaries, unknown effects, duplicate sources, fencing and compatible reader.

The rehearsal uses scripted provider and host ports. It does not establish live
provider support, external metering, cluster-level disaster recovery or
production readiness. It imports no Botkit data. The schema-only archive is
created in a private temporary directory and removed by the owning test.

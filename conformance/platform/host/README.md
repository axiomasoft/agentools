# P10.1 host boundary proof

Exact required cases are registered as `host` (`edufy-host` alias), partition test,
contract-scripted tier, host_contract_harness evidence. run.py hashes actual inputs
and combines identity.php, boundary.php, report.php, ownership.php and boundary_test.py.
Missing/extra/duplicate/failed/skipped cases and mismatched tiers are rejected by the gate.

The synthetic SchoolApi owner exercises report read, confirmation lookup, current ACL
inside mutation, expected revision, native key/hash, lost ACK and same-key lookup.
Negative architecture fixtures assert that Host production adapters cannot import owner
models, database APIs or a container. Existing global architecture checks remain required.

No real Edufy tables, owner repository, token gateway, durable PG intake/outbox, model
provider or production framework request is exercised. EDUFY-AGENTOOLS-HOST-001 revision 1
is pending externally. Existing Actions service owns orchestration/confirmation/recovery;
this partition proves the host integration contract only.

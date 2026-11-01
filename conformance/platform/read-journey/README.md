# Read journey proof

`run.py --filter METHOD` runs only the selected PHPUnit methods with verified real
isolated PostgreSQL. The owning full partition is registered as
`e2e --scenario read-journey`; acceptance is coordinator-owned.

The exact 14 named cases are in `tools/platform_gate.py` and map to
`packages/agentools-testing/tests/Journeys/HeadlessReadJourneyTest.php`.
External owners and provider are scripted. The report uses canonical
local-host-e2e tier and retains the PostgreSQL owner/name/allow-marker proof.

Crash coverage uses actual SIGKILL after an observed barrier at intake, signal,
provider reply, payload checkpoint, proposal dispatch, Action acceptance, read
result, read completion, response/delivery commit, send intent and destination
write. Restart uses fresh PHP processes/PDO connections, the same source and saved
IDs, with two provider exchanges, one Action/read/result and one destination message.
The test also asserts that every identity recorded before kill remains afterward.
Lease takeover uses real PostgreSQL microsecond comparisons; ManualClock is unused.

The destination ack-loss test retains unknown separately from delivered, checks
status under current visibility, and appends bound receipt evidence. Negative cases
exercise tool allowlist, owner ACL at disclosure, budget outage/unknown, emergency,
cancellation, tenant isolation, immutable source/delivery conflicts and encrypted
payload TTL. Focused core compiler tests cover overflow, pruning reasons, invalid
counts, policy/input preservation, trust/classification and current permissions.

Model-adapter generated dependencies are excluded only by its package-local
`/vendor/` ignore. Installed dependency bytes and the exact adapter lock are retained.
No prior review/correction is labelled independently reviewed by this proof.
P3.5 F2 audit/reset uncertainty and P2.4 ManualClock F1 remain unresolved.

Correction regressions READ-F1-NOT-SENT and READ-F2-PRE-SEND reproduce the reviewed
pre-send windows. They assert typed policy denial without a provider call or unknown
usage fact, same-Invocation recovery after rights return, request-hash validation
of not_sent evidence, and recovery after three denied or killed delivery preparations.
The actual-send bound is separately tested at exhaustion; no guard is disabled.

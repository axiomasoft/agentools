# Private write recovery rehearsal

This harness composes the actual Intake, Outbox, AdvanceRun, InvocationRepository,
Actions, bound Confirmation, Effects, DeliveryRepository and audited Operations
implementations. Provider, admission, document owner and destination are scripted
external ports backed by separate PostgreSQL connections. It exercises one
effect-capable path: Actions dispatches the owner effect. It uses synthetic data.
It does not qualify a provider or a production owner.

The coordinator runs the registered acceptance entry:

```sh
python3 tools/platform_gate.py e2e --scenario write-recovery
```

The exact durable set is 52 cases: 12 journey cases plus all 12 confirmation,
15 effect recovery and 13 cancellation/operator cases. All run within the existing
verified isolated PostgreSQL lifecycle. It checks the database name, restricted
owner and explicit database allow marker before creating any schema. Without an
explicit isolated test DSN it provisions and removes its own labelled container.
Development and production databases are rejected.

`write-matrix.json` names 19 real SIGKILL checkpoints. A separate matrix injects
six deferred SQL commit failures. The F2 test injects the original failure of
`effect_confirmed` after the actual confirmed-write example owner commits,
then forbids further writes and recovers through same-key lookup. The zombie
test keeps the old process alive while PostgreSQL time expires its lease,
advances both fences, and rejects its late Run and effect commits. It uses no
ManualClock. Interrupted admission/provider intent remains unknown; no absent
receipt is treated as permission to resend.

The receipt retains actual per-boundary outcomes, worker exit, known/unknown
outcomes, one owner write, stable identities and the accepted epoch. Missing
observations, required cases or isolation proof fail the gate. `run.py --filter
METHOD` runs a focused journey test for development; it does not replace the
52-case acceptance entry.

The same entry also runs the separate 117-case portable partition: 39 fixtures
each against an independent subset reference and the actual current PHP and Dart
SDKs. These include applicable existing confirmation/effect/cancellation fixtures
and new epoch, CAS, unknown event, UI/voice, expiry, consume-on-denial and takeover
cases. Comparison checks actual results against expectations and all three
consumers; case omission, duplication and disagreement fail closed. The existing
full protocol semantic-parity command remains a separate regression gate.

The tenant/instance switch preserves source event identity within its tenant and
instance, rejects a changed source hash, and keeps other tenant identities apart.
Drain records Run cancellation separately, reconciles the accepted effect, and
does not generate an extra response or automatic compensation.

The rollback rehearsal starts at migrations 0001–0010, completes a write,
applies additive 0011 once, reads the unchanged journal with the dated 2026-09
SDK reducer, and rebuilds the projection with its verified digest. This proves
the compatible reader route on the additive schema. It is not a deployment,
backup restore or destructive down migration rehearsal.

Install the private path packages with their locked Composer dependencies and
the locked Dart package configuration in this checkout. Use `composer install
--no-dev` in an isolated consuming application with explicit private repositories
and stability pins for packaging checks; the earlier package receipts contain
those probes. No package publication, production credentials or network provider
calls are part of this harness. Live provider qualification, external release CI,
production owner integration and the owner release decision remain separate gates.
Classical mutation testing is excluded by owner decision D8.

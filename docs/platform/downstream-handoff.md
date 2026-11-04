# Private downstream contract handoff

This is a local handoff for owners, not a claim of remote compatibility or a
request sent to another repository. The private `downstream-contracts.zip` bundle
contains this handoff and its referenced contract inputs in repository-relative
paths. Regenerate it with `python3 docs/platform/export_contract.py` and verify
with `python3 docs/platform/export_contract.py --check`. It is a contract bundle,
not a runnable application or a substitute for the full test checkout. All external pins and
owner implementations below remain unverified until each owner supplies and tests
them against the current candidate.

| Owner | Contract and required next evidence |
| --- | --- |
| Canon | Supply the authoritative canonical JSON/hash implementation and version; verify tenant-scoped identity and immutable hash conflicts against `packages/agentools-core/src/Common/CanonicalJson.php` and `CanonicalHasher.php`. |
| Edufy | Implement authenticated host resolution, current consent, authorization and minimal context per `spec/platform/v1/host/README.md` and `examples/platform/edufy-host/contract.json`; qualify `EDUFY-AGENTOOLS-HOST-001` revision 1 and real owner E2E. |
| Chatom | Implement the native stable-key, immutable hash and receipt lookup contract in `extensions/chatom/contract.md`; qualify `CHATOM-BRIDGE-PIN-001` revision 1 with real destination ACK-loss behavior. |
| Loqum | Agree ingress and delivery versions and prove identity, consent, disclosure and unknown receipt recovery against `extensions/loqum/src/Ingress/README.md` and `extensions/loqum/src/Delivery/README.md`; no real voice provider is qualified. |
| ControLLM | Supply a versioned metering interface and evidence for immutable usage versions, nullable/unknown values and idempotent export; compare `packages/agentools-postgres/src/Usage/README.md` before remote qualification. |
| Tariffler | Supply a versioned admission/reservation interface with stable tenant-scoped keys, current policy and unknown/expiry handling; qualify against the ports in `packages/agentools-core/src/Budget/` before enabling remote admission. |

The [host handoff](integrations/README.md) records local scripted-host and isolated
PostgreSQL evidence and its restrictions. The Laravel composition's
`packages/agentools-laravel/tests/Composition/reuse-map.json` records attribution:
P6.1 composition is new target code, with no copied donor source. Botkit is a donor
of ideas, not an active deployment or data migration source. Further reuse must
carry source, revision, license and behavioral evidence. No public publication,
live provider qualification, sibling change or production readiness is implied.

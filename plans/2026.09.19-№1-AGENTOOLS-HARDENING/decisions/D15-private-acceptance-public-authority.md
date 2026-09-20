---
id: D15
date: 2026-09-20
status: accepted
item: P10
items: [P10, P10.1, P10.2, P10.3, P10.4, P10.5]
supersedes: []
superseded_by: null
---
# D15 — Separate private release acceptance from public release authority

**Actor:** plan-designer/gpt-5.6-sol/high
**Evidence:** RAG:— repository/plan recon in `research/P10-design.md`; RAG:✅ official channel and versioning sources in `research/P10-design-rag.md`

## Решение

P10 uses one exact claim/evidence matrix and two non-collapsing verdict axes. Private readiness may
be GREEN only when one exact P9 candidate, its docs, clean consumers, migrations and all required
P1–P9 evidence are current with zero unresolved private blocker. Public eligibility is evaluated
separately and is never authorization.

P10 accepts only the local/private candidate and emits a digest-bound G-RELEASE decision request.
The request names exact proposed legal/package identities, channels, version/tag, subject digests,
actions, credential/capability prerequisites, consequences and invalidation triggers. It contains no
owner grant and performs no tag, GitHub release, registry upload, credential use or repository-setting
mutation. Any future public action is separate owner-authorized scope and must bind the exact packet
digest through `[OWNER-GATE:G-RELEASE]`.

D5 remains owner-owned and unsuperseded. While D5, public identities/channels, `publish_to: none`,
the Dart MCP path dependency or required platform capability remain unresolved, public eligibility
is false without making a technically valid private acceptance red.

## Почему

D4 explicitly requires a production-grade private RC but says absence of G-RELEASE blocks only
public actions. Registry versions/tags create durable consumer state, and current repository
metadata is intentionally proprietary/private. Making publication a mandatory P10 item would either
prevent private completion or turn technical evidence into implicit legal authority.

## Consequences

P10 has five sequential items: acceptance contract, documentation truth, exact-subject consumers,
final dossier and private acceptance/request packet. The phase can close GREEN with
`public_authorized=false`. A later public launch must be separately requested and cannot widen the
packet, change subject bytes or silently resolve D5/MCP topology.

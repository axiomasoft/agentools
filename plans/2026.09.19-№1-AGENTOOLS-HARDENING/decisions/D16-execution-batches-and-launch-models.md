---
id: D16
date: 2026-09-20
status: accepted
item: P0.1
items: [P0.1, P1, P2, P3, P4, P5, P6, P7, P8, P9, P10]
supersedes: []
superseded_by: null
---
# D16 — Group execution into 19 single-model batches

**Actor:** plan-designer/gpt-5.6-sol/xhigh
**Evidence:** RAG:— owner instruction on 2026-09-20; item dependencies and shared setup in `plan.md` §3 and `research/P1-design.md`–`research/P10-design.md`

## Решение

Future product execution uses 19 adjacent `task:plan-run` batches rather than 23 separate
sessions. P2.1–P2.4 become `confirmation`, P3.1–P3.4 `token-replay`, P4.1–P4.4
`lease-fencing`, and P5.1–P5.4 `event-store`. These four phases are linear contract-to-gate
contours whose Required Reads, source graph and validation setup remain useful through the final
item. Every item retains its own lifecycle, validation and closure inside the group.

One Cursor launch model is fixed for every batch. `Composer 2.5/high` owns synthesis-heavy API,
semantic, packaging, CI and release batches in P1/P2/P6/P7/P9/P10. `Grok 4.6/high` owns
crypto/replay, fencing, event persistence and adversarial verification batches in P3/P4/P5/P8.
No batch changes model mid-session.

P7.4, P8.4, P8.5, P9.5 and P10.5 remain `solo`: respectively clean artifact isolation,
destructive test-only PostgreSQL safety, post-evidence calibration, independent aggregate re-hash,
and final authority separation are stronger boundaries than the saved cold start.

## Почему

Merging P2–P5 removes four cold starts without crossing a phase, changing Exec/Review, weakening
an item acceptance contract or mixing models. Larger merges in P1/P6/P7/P8/P9/P10 would combine
distinct evidence or safety contours and make stale-context failures more likely than the saved
session is worth.

## Consequences

`plan.md` remains the canonical provider-neutral Routing source; `roadmap.md` renders exact batch
commands and the concrete Cursor launch choice. Dependency-safe prefixes of `recommended:` groups
remain admissible when a later member is not ready.

The installed Task route-catalog currently verifies Cursor `composer-2.5` as the
`implementation` selector but does not yet register an exact Grok 4.6 selector. The plan therefore
does not invent a native Grok command. Before the first Grok batch, the Task package owner must
verify and register the exact selector or this decision must route that whole batch to
`Composer 2.5`; `auto` is not a substitute.

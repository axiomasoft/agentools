# P4 design RAG — lease, fencing and idempotency foundations

**Verified:** 2026-09-19  
**Boundary:** External sources constrain distributed-lock and idempotency **properties**.
Agentools class names, SQL identifiers, error codes, capability enum and phase ownership
are repository design decisions (`RAG:—`), not claims sourced from AWS, IETF or Kleppmann.

Perplexity was queried twice through the configured `plugin-core-perplexity` MCP: one
web+academic synthesis of AWS Powertools idempotency (INPROGRESS lock, expiry, payload
validation, absence of fencing tokens) and one web synthesis of PostgreSQL CAS UPDATE,
Kleppmann fencing, and the HTTP Idempotency-Key draft. Every load-bearing claim below
was then rechecked against the linked primary source. Native WebFetch succeeded for
AWS Powertools Python idempotency, Kleppmann 2016-02-08, and PostgreSQL `UPDATE`.
The IETF draft was retrieved via WebSearch into a full-text snapshot of
`draft-ietf-httpapi-idempotency-key-header-07` (datatracker HTML, published 2025-10-15).

| Evidence | Verified claim | P4 consequence |
|:--|:--|:--|
| RAG:✅ [Powertools Python idempotency](https://docs.aws.amazon.com/powertools/python/latest/utilities/idempotency/), accessed 2026-09-19 | Record status is `INPROGRESS` / `COMPLETE` (plus internal `EXPIRED`). `expires_after_seconds` defaults to 3600 and is the **record** window. Concurrent first invocations are locked: later callers get `IdempotencyAlreadyInProgressError` and may retry. `in_progress_expiry_timestamp` lets a later invocation treat a timed-out INPROGRESS record as expired and run again. Payload validation hash can reject same-key different payload. | Keep a distinct in-progress lease vs record retention. Immediate in-flight matches IDE-14. Payload hash matches IDE-16. Time-based retry after in-progress expiry is **not** sufficient as the Agentools complete primitive. |
| RAG:✅ same Powertools page, “Handling concurrent executions”, accessed 2026-09-19 | Concurrent execution is blocked because the first result is unknown; this is described as a locking mechanism for correctness, not as fencing of a stale writer after expiry. | P4 store may admit takeover after lease expiry; complete/fail still require the current token so the paused first writer cannot publish. |
| RAG:✅ [Kleppmann, “How to do distributed locking”, 2016-02-08](https://martin.kleppmann.com/2016/02/08/how-to-do-distributed-locking.html), accessed 2026-09-19 | A lease timeout does not mean the holder has stopped. GC, paging, scheduling, or delayed packets can let the old holder apply a write after expiry. The fix is a **monotonic fencing token** that the **storage/resource checks**, rejecting a backwards token. A random lock value without monotonicity does not fence. | `attempt` is monotonic; `lease_token` is the CAS handle for this row. Complete/fail/heartbeat are rejected unless the token matches. Downstream exactly-once still requires the **tool** to check a fence or native key — the local row CAS only fences the **idempotency record**. |
| RAG:✅ [PostgreSQL `UPDATE`](https://www.postgresql.org/docs/current/sql-update.html), accessed 2026-09-19 | `WHERE` restricts which rows change. `RETURNING` returns values from rows **actually updated**. Command tag `UPDATE count` reports updated rows; `count = 0` is success with no row changed, not an error. | complete/fail/heartbeat/takeover use `UPDATE … WHERE state='in_progress' AND lease_token=:token` (takeover: `lease_until <= :now`) `RETURNING`. Empty RETURNING is lost-lease / lost-takeover. Do not SELECT-after-empty to publish. |
| RAG:✅ [PostgreSQL `INSERT` / `ON CONFLICT`](https://www.postgresql.org/docs/current/sql-insert.html), already verified for PLAN1 P3, still current 2026-09-19 | `DO NOTHING` skips insert on arbiter conflict; `RETURNING` includes only inserted/updated rows. | First acquire is `INSERT … ON CONFLICT DO NOTHING RETURNING`, same unique `(tenant_id, tool_name, idempotency_key)` as IDE-5. Do not `DO UPDATE` onto a completed row. |
| RAG:✅ [draft-ietf-httpapi-idempotency-key-header-07](https://datatracker.ietf.org/doc/html/draft-ietf-httpapi-idempotency-key-header-07) (15 Oct 2025; expires 18 Apr 2026), accessed 2026-09-19 | Client generates the key; it MUST NOT be reused with a different payload. First unseen key+fingerprint is processed; completed retry SHOULD replay stored success **or error**; concurrent retry SHOULD be HTTP 409 “outstanding”. Draft is work-in-progress, not an RFC. | Aligns with IDE-1/12/14/16. Agentools keeps string tool errors, not HTTP 409. The draft does not specify lease fencing or exactly-once side effects. |

## Facts intentionally not promoted

- Powertools is an admission/replay utility for Lambda. It does not require Agentools to use DynamoDB, `expires_after_seconds=3600`, or MD5 payload hashes.
- Powertools deleting the record on unhandled handler exception is **incompatible** with IDE-13 (stored failure is replayed; same key must not start a new run). P4 stores domain failures.
- Kleppmann’s fencing example is a storage server that checks tokens on **every write**. Local CAS on `agentools_idempotency` fences the **record**, not an arbitrary payment API. Capability `native_key` is the documented cooperation hook.
- PostgreSQL `UPDATE count` includes rows matched whose values did not change. P4 predicates must change lease/result columns so `count=1` is meaningful; tests assert `RETURNING` not merely “no exception”.
- The Idempotency-Key draft is not a license to wait/block on the in-flight caller (IDE-15 already forbids that; 409 is immediate).
- Audit SQL `state IN ('in_progress','completed','failed')` is a sketch (F14), not the schema.

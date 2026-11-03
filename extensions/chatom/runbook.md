# Delivery and purge runbook

1. Resolve the authenticated current tenant/session/purpose through host APIs. Bind one
   committed source relay and one outgoing bridge; do not activate two consumers for the
   same logical message. Queue an opaque handle or technical key, never cached grants.
2. Source ACK follows native fenced inbox/Turn/Run/wake commit. Queue transport is a signal;
   recover missed dispatch with the existing native outbox scanner. Do not recreate a Run.
3. Publisher writes the approved immutable export into the existing encrypted payload store,
   with numeric retention/key/classification/purpose policy. Commit response and delivery
   intent before any destination IO. Use saved native key/hash/ref/version/destination.
4. Scanner calls pending with a bounded limit; relay claims one intent using the existing
   lease/fence. No external IO occurs under runtime transaction locks. Recheck current
   membership, visibility, emergency and moderation at the destination mutation boundary.
5. On timeout/crash/lost ACK retain unknown (a live sending control is also unresolved).
   After lease expiry, lookup the exact destination/key/hash first. Persist recovered
   receipt; native-key dedup permits the same immutable send only when that driver contract
   is qualified. Never regenerate text, rekey, invoke a model/Action, or infer absence.
6. Expired/exhausted/cancelled/source-closed intents need owner-authorized reconciliation.
   pending omitting an exhausted intent is not proof of delivery. Retain unknown until a
   destination receipt or audited resolution exists. Cancellation cannot erase a known
   delivery or prove that an earlier remote send had no effect. Persist cancellation audit
   through the explicitly injected host callback; audit failure does not undo cancellation.
7. For edit/delete/purge, keep the committed owner event unacknowledged until Invalidation.apply
   closes source work and invalidates the summary/candidate/index/context-cache graph. Retry
   the same event key/hash after crash. Changed event payload conflicts. Reordered closure
   cannot reopen the source. Do not reconstruct deleted history from diagnostics.
8. Schedule existing DerivedPayloadRepository/PayloadRepository cleanup for registered bytes
   under actual hold/retention/key policy. Obtain each owner's cleanup receipt, including
   provider/context cache and materialized copies. Source fence or graph tombstone alone is
   not an erasure receipt. Unknown dependencies continue to block unsafe purge. Native
   payload purge records its receipt/tombstone and must leave Chatom's transcript intact.
9. Renew real driver/version/client-key, membership, retention and release qualification
   separately; local harness success does not close Chatom/Edufy/Loqum/Foundation gates.

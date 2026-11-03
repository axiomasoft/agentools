# Unknown reservation runbook

The reservation and operation tables are technical projections. The external owner
remains authoritative; there is no balance, business debit or local fallback quota.

1. Stop new billable sends when admission is denied/unavailable/unknown, entitlement
   is revoked, emergency deployment guard fails, or the export backlog is bounded out.
   A profile pin never freezes current permissions. Fallback is a separate invocation
   and reservation with the same host business-operation reference.
2. After losing admission ACK, call `status` with the original AdmissionRequest and
   operation key/hash. Only an owner-confirmed `not_found` permits same-key admit.
   A changed request under that key quarantines; do not substitute a fresh key.
3. After losing provider reply, retain unknown exposure and nullable units. A durable
   outbound intent is not proof of no spend. Worker expiry, cancellation, reservation
   expiry and TTL never authorize release or regeneration of that invocation.
4. Retry settle/release/renew with exactly its previous arguments. The adapter reads
   its immutable local operation, then calls the optional host `StatusPort` using the
   original admission operation key and operation hash. `applied` requires a matching
   owner proof; `not_applied` must prove terminal non-application and safe replay of
   that operation. An ambiguous or unsupported lookup stays unknown. No blind retry.
5. Settle only persisted final normalized facts bound to that invocation and business
   operation. Same fact is acknowledged locally once. A final correction references
   the prior confirmed immutable fact; the owner maps replacement semantics and owns
   any financial adjustment. Do not issue another business debit.
6. Release requires trusted positive not_sent evidence and absence of an outbound
   intent, followed by owner-confirmed release. A lost release ACK stays unknown.
   Renewal requires a future explicit deadline; lost ACK requires status proof and
   a bound fresh admission before returning active.
7. Exporter lookup uses the identical tenant/event/hash. A committed owner export
   with lost ACK is recovered by status, not a new fact. Attempts/leases are fenced;
   exhaustion stops retries as manual_resolution_required and retains facts.
8. Escalate unsupported owner lookup, persistent outage, quarantine or exhausted
   attempts to the host operator with technical keys/hashes only. Reconcile through
   the authoritative owner; do not delete facts, zero quantities or auto-release to
   clear the queue. Reset worker tenant/session state on success and failure.

Production use requires the host's explicit backlog policy, current guard binding,
real metering adapter and authoritative operation lookup mapping. Scripted fixtures
prove these boundaries locally and do not qualify any external money/provider API.

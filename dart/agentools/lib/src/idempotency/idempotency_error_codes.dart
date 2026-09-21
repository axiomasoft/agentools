/// Idempotency-specific tool error codes from §E1 and P4.
abstract final class IdempotencyErrorCodes {
  static const keyMissing = 'agentools.validation.idempotency_key_missing';
  static const inFlight = 'agentools.conflict.idempotency_in_flight';
  static const keyReuse = 'agentools.validation.idempotency_key_reuse';
  static const lostLease = 'agentools.conflict.idempotency_lost_lease';
  static const ambiguousEffect =
      'agentools.precondition.idempotency_ambiguous_effect';
  static const capabilityUnknown =
      'agentools.precondition.idempotency_capability_unknown';
}

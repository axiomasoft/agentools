/// Idempotency-specific tool error codes from §E1.
abstract final class IdempotencyErrorCodes {
  static const keyMissing = 'agentools.validation.idempotency_key_missing';
  static const inFlight = 'agentools.conflict.idempotency_in_flight';
  static const keyReuse = 'agentools.validation.idempotency_key_reuse';
}

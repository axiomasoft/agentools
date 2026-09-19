/// ALC-25: an unresolved request must be reconciled, never blindly retried.
final class EffectReconciliationRequired implements Exception {
  EffectReconciliationRequired([this.message = 'The requested effect has no recorded outcome.']);

  final String message;

  @override
  String toString() => 'EffectReconciliationRequired: $message';
}

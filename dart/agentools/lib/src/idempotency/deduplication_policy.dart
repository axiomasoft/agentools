/// Independent retention, confirmation, and client-retry durations.
final class DeduplicationPolicy {
  DeduplicationPolicy({
    required this.retention,
    required this.confirmationLifetime,
    required this.clientRetryWindow,
  }) {
    final minimum = confirmationLifetime + clientRetryWindow;
    if (retention < minimum) {
      throw ArgumentError(
        'Deduplication retention must cover confirmation lifetime plus the client retry window.',
      );
    }
  }

  final Duration retention;
  final Duration confirmationLifetime;
  final Duration clientRetryWindow;
}

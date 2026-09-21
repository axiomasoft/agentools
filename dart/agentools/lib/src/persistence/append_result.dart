final class AppendResult {
  static const appended = 'appended';
  static const idempotentReplay = 'idempotent_replay';

  AppendResult({
    required this.firstSeq,
    required this.lastSeq,
    required this.lastGlobalPosition,
    required this.outcome,
  }) {
    if (outcome != appended && outcome != idempotentReplay) {
      throw ArgumentError('Unknown append outcome.');
    }
    if (firstSeq < 1 || lastSeq < firstSeq || lastGlobalPosition < 1) {
      throw ArgumentError(
        'Append result sequences must be positive and ordered.',
      );
    }
  }

  final int firstSeq;
  final int lastSeq;
  final int lastGlobalPosition;
  final String outcome;
}

import 'arguments_fingerprint.dart';
import 'idempotency_scope.dart';

final class IdempotencyRequest {
  IdempotencyRequest({
    required this.scope,
    required this.argumentsFingerprint,
    required this.actionId,
  }) {
    if (actionId.isEmpty) {
      throw ArgumentError('Action id must not be empty.');
    }
  }

  final IdempotencyScope scope;
  final ArgumentsFingerprint argumentsFingerprint;
  final String actionId;
}

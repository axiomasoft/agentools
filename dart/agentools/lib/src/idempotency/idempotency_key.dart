import '../tool/tool_execution_error.dart';
import 'idempotency_error_codes.dart';
import 'idempotency_failure.dart';

/// A caller-generated `at1` key. The SDK never derives it from call arguments.
final class IdempotencyKey {
  IdempotencyKey(this.value) {
    if (!RegExp(r'^at1:[A-Za-z0-9_-]{8,128}$').hasMatch(value)) {
      throw ArgumentError(
        r'Idempotency key must match ^at1:[A-Za-z0-9_-]{8,128}$.',
      );
    }
  }

  final String value;

  /// Use at the idempotent-commit tool boundary after reading
  /// `_meta["dev.agentools/action"]["idempotency_key"]`.
  static IdempotencyKey required(String? value) {
    if (value == null || value.isEmpty) {
      throw IdempotencyFailure(
        ToolExecutionError(
          code: IdempotencyErrorCodes.keyMissing,
          category: 'validation',
          retryable: false,
          title: 'An idempotency key is required for this commit.',
        ),
      );
    }

    return IdempotencyKey(value);
  }
}

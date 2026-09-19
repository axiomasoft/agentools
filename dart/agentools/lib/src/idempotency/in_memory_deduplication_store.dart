import '../contracts/idempotency_store.dart';
import '../tool/tool_execution_error.dart';
import 'arguments_fingerprint.dart';
import 'deduplication_resolution.dart';
import 'deduplication_policy.dart';
import 'deduplication_state.dart';
import 'idempotency_error_codes.dart';
import 'idempotency_failure.dart';
import 'idempotency_request.dart';
import 'stored_result_digest.dart';
import 'stored_tool_result.dart';

/// Process-local reference implementation.
final class InMemoryDeduplicationStore implements IdempotencyStore {
  InMemoryDeduplicationStore(this.policy);

  final DeduplicationPolicy policy;
  final Map<String, _Record> _records = {};

  @override
  DeduplicationResolution resolve(IdempotencyRequest request, DateTime now) {
    final key = request.scope.storageKey();
    var record = _records[key];
    if (record != null && !record.expiresAt.isAfter(now)) {
      _records.remove(key);
      record = null;
    }
    if (record == null) {
      _records[key] = _Record(
        fingerprint: request.argumentsFingerprint,
        expiresAt: now.add(policy.retention),
      );
      return DeduplicationResolution(DeduplicationState.newIntent);
    }
    if (record.fingerprint.value != request.argumentsFingerprint.value) {
      throw IdempotencyFailure(
        ToolExecutionError(
          code: IdempotencyErrorCodes.keyReuse,
          category: 'validation',
          retryable: false,
          title: 'The idempotency key was reused with different arguments.',
          context: {'idempotency_key': request.scope.key.value},
        ),
      );
    }
    if (record.result == null) {
      return DeduplicationResolution(
        DeduplicationState.concurrentInFlight,
        error: ToolExecutionError(
          code: IdempotencyErrorCodes.inFlight,
          category: 'conflict',
          retryable: true,
          title: 'Work for this idempotency key is already in flight.',
          context: {'idempotency_key': request.scope.key.value},
        ),
      );
    }
    return DeduplicationResolution(
      DeduplicationState.duplicateCompleted,
      storedResult: record.result,
      storedResultDigest: record.digest,
    );
  }

  @override
  void complete(
    IdempotencyRequest request,
    StoredToolResult result,
    StoredResultDigest digest,
  ) {
    final key = request.scope.storageKey();
    final record = _records[key];
    if (record == null ||
        record.fingerprint.value != request.argumentsFingerprint.value) {
      throw StateError(
        'Only the original in-flight request can complete a deduplication record.',
      );
    }
    if (record.result != null) {
      throw StateError('A deduplication record can only be completed once.');
    }
    record.result = result;
    record.digest = digest;
  }
}

final class _Record {
  _Record({required this.fingerprint, required this.expiresAt});

  final ArgumentsFingerprint fingerprint;
  final DateTime expiresAt;
  StoredToolResult? result;
  StoredResultDigest? digest;
}

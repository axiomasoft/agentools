import '../tool/tool_execution_error.dart';
import 'deduplication_state.dart';
import 'idempotency_lease.dart';
import 'stored_result_digest.dart';
import 'stored_tool_result.dart';

final class IdempotencyAcquisition {
  IdempotencyAcquisition(
    this.state, {
    this.lease,
    this.takeover = false,
    this.storedResult,
    this.storedResultDigest,
    this.error,
  }) {
    if ((state == DeduplicationState.newIntent) != (lease != null)) {
      throw ArgumentError('A new acquisition must carry a lease.');
    }
    if (takeover && state != DeduplicationState.newIntent) {
      throw ArgumentError('Only a new acquisition can be a takeover.');
    }
    if (state == DeduplicationState.duplicateCompleted &&
        (storedResult == null || storedResultDigest == null)) {
      throw ArgumentError(
        'A completed duplicate must carry its stored result and digest.',
      );
    }
    if (state != DeduplicationState.duplicateCompleted &&
        (storedResult != null || storedResultDigest != null)) {
      throw ArgumentError('Only a completed duplicate carries a result.');
    }
    if ((state == DeduplicationState.concurrentInFlight) != (error != null)) {
      throw ArgumentError(
        'Only an in-flight resolution carries its immediate tool error.',
      );
    }
  }

  final DeduplicationState state;
  final IdempotencyLease? lease;
  final bool takeover;
  final StoredToolResult? storedResult;
  final StoredResultDigest? storedResultDigest;
  final ToolExecutionError? error;
}

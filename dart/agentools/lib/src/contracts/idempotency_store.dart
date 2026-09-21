import '../idempotency/idempotency_acquisition.dart';
import '../idempotency/idempotency_lease.dart';
import '../idempotency/idempotency_request.dart';
import '../idempotency/stored_result_digest.dart';
import '../idempotency/stored_tool_result.dart';

/// Fenced deduplication persistence. Production stores must CAS on lease_token.
abstract interface class IdempotencyStore {
  IdempotencyAcquisition acquire(
    IdempotencyRequest request,
    DateTime now,
    Duration leaseDuration,
  );

  IdempotencyLease heartbeat(
    IdempotencyLease lease,
    DateTime now,
    Duration leaseDuration,
  );

  void complete(
    IdempotencyLease lease,
    StoredToolResult result,
    StoredResultDigest digest,
    DateTime now,
  );

  void fail(
    IdempotencyLease lease,
    StoredToolResult result,
    StoredResultDigest digest,
    DateTime now,
  );

  int cleanup(DateTime now);
}

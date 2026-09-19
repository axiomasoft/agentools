import '../idempotency/deduplication_resolution.dart';
import '../idempotency/idempotency_request.dart';
import '../idempotency/stored_result_digest.dart';
import '../idempotency/stored_tool_result.dart';

/// Deduplication record persistence. Production stores must make resolve's
/// unique-key insert atomic.
abstract interface class IdempotencyStore {
  DeduplicationResolution resolve(IdempotencyRequest request, DateTime now);

  void complete(
    IdempotencyRequest request,
    StoredToolResult result,
    StoredResultDigest digest,
  );
}

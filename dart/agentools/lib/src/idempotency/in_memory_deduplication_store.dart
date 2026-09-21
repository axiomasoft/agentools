import '../contracts/idempotency_store.dart';
import 'arguments_fingerprint.dart';
import 'deduplication_policy.dart';
import 'deduplication_state.dart';
import 'idempotency_acquisition.dart';
import 'idempotency_failure.dart';
import 'idempotency_lease.dart';
import 'idempotency_request.dart';
import 'lease_duration.dart';
import 'stored_result_digest.dart';
import 'stored_tool_result.dart';

/// Process-local reference implementation. Testkit only.
final class InMemoryDeduplicationStore implements IdempotencyStore {
  InMemoryDeduplicationStore(this.policy);

  final DeduplicationPolicy policy;
  final Map<String, _Record> _records = {};

  @override
  IdempotencyAcquisition acquire(
    IdempotencyRequest request,
    DateTime now,
    Duration leaseDuration,
  ) {
    final seconds = LeaseDuration.assertValid(leaseDuration);
    final key = request.scope.storageKey();
    var record = _records[key];
    if (record != null &&
        record.state == 'completed' &&
        !record.expiresAt.isAfter(now)) {
      _records.remove(key);
      record = null;
    }
    if (record == null) {
      final lease = IdempotencyLease.issue(
        request,
        now.add(Duration(seconds: seconds)),
      );
      _records[key] = _Record.inProgress(
        fingerprint: request.argumentsFingerprint,
        lease: lease,
        expiresAt: now.add(policy.retention),
      );
      return IdempotencyAcquisition(DeduplicationState.newIntent, lease: lease);
    }
    if (record.fingerprint.value != request.argumentsFingerprint.value) {
      throw IdempotencyFailure.keyReuse(request.scope.key.value);
    }
    if (record.state == 'completed') {
      return IdempotencyAcquisition(
        DeduplicationState.duplicateCompleted,
        storedResult: record.result,
        storedResultDigest: record.digest,
      );
    }
    final existing = record.lease;
    if (existing != null && existing.leaseUntil.isAfter(now)) {
      final failure = IdempotencyFailure.inFlight(request.scope.key.value);
      return IdempotencyAcquisition(
        DeduplicationState.concurrentInFlight,
        error: failure.error,
      );
    }
    final attempt = (existing?.attempt ?? 1) + 1;
    final lease = IdempotencyLease.issue(
      request,
      now.add(Duration(seconds: seconds)),
      attempt: attempt,
    );
    record.lease = lease;
    return IdempotencyAcquisition(
      DeduplicationState.newIntent,
      lease: lease,
      takeover: true,
    );
  }

  @override
  IdempotencyLease heartbeat(
    IdempotencyLease lease,
    DateTime now,
    Duration leaseDuration,
  ) {
    final seconds = LeaseDuration.assertValid(leaseDuration);
    _requireLease(lease);
    final extended = IdempotencyLease(
      request: lease.request,
      token: lease.token,
      attempt: lease.attempt,
      leaseUntil: now.add(Duration(seconds: seconds)),
    );
    _records[lease.request.scope.storageKey()]!.lease = extended;
    return extended;
  }

  @override
  void complete(
    IdempotencyLease lease,
    StoredToolResult result,
    StoredResultDigest digest,
    DateTime now,
  ) {
    _finish(lease, result, digest);
  }

  @override
  void fail(
    IdempotencyLease lease,
    StoredToolResult result,
    StoredResultDigest digest,
    DateTime now,
  ) {
    _finish(lease, result, digest);
  }

  @override
  int cleanup(DateTime now) {
    final keys = _records.entries
        .where(
          (entry) =>
              entry.value.state == 'completed' &&
              entry.value.expiresAt.isBefore(now),
        )
        .map((entry) => entry.key)
        .toList();
    for (final key in keys) {
      _records.remove(key);
    }
    return keys.length;
  }

  _Record _requireLease(IdempotencyLease lease) {
    final record = _records[lease.request.scope.storageKey()];
    if (record == null ||
        record.state != 'in_progress' ||
        record.lease == null ||
        record.lease!.token != lease.token) {
      throw IdempotencyFailure.lostLease();
    }
    return record;
  }

  void _finish(
    IdempotencyLease lease,
    StoredToolResult result,
    StoredResultDigest digest,
  ) {
    final record = _requireLease(lease);
    record
      ..state = 'completed'
      ..lease = null
      ..result = result
      ..digest = digest;
  }
}

final class _Record {
  _Record.inProgress({
    required this.fingerprint,
    required this.lease,
    required this.expiresAt,
  }) : state = 'in_progress';

  final ArgumentsFingerprint fingerprint;
  String state;
  IdempotencyLease? lease;
  final DateTime expiresAt;
  StoredToolResult? result;
  StoredResultDigest? digest;
}

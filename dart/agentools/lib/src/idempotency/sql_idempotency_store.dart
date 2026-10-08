import 'dart:convert';

import '../confirmation/sql_confirmation_token_store.dart';
import '../contracts/idempotency_store.dart';
import '../tool/tool_execution_error.dart';
import 'deduplication_policy.dart';
import 'deduplication_state.dart';
import 'idempotency_acquisition.dart';
import 'idempotency_failure.dart';
import 'idempotency_lease.dart';
import 'idempotency_request.dart';
import 'lease_duration.dart';
import 'stored_result_digest.dart';
import 'stored_tool_result.dart';

final class SqlIdempotencyStore implements IdempotencyStore {
  SqlIdempotencyStore(this._executor, this._policy);

  final SqlExecutor _executor;
  final DeduplicationPolicy _policy;

  static const insertSql = '''
INSERT INTO agentools_idempotency_leases (
  tenant_id, tool_name, idempotency_key, fingerprint, state,
  lease_token, lease_until, attempt, result_json, result_digest, expires_at
) VALUES (
  @tenant_id, @tool_name, @idempotency_key, @fingerprint, 'in_progress',
  @lease_token, @lease_until, 1, NULL, NULL, @expires_at
)
ON CONFLICT (tenant_id, tool_name, idempotency_key) DO NOTHING
RETURNING tenant_id, attempt, lease_token, lease_until
''';

  static const selectSql = '''
SELECT fingerprint, state, lease_token, lease_until, attempt, result_json, result_digest, expires_at
FROM agentools_idempotency_leases
WHERE tenant_id = @tenant_id AND tool_name = @tool_name AND idempotency_key = @idempotency_key
''';

  static const takeoverSql = '''
UPDATE agentools_idempotency_leases
SET lease_token = @lease_token, attempt = attempt + 1, lease_until = @lease_until
WHERE tenant_id = @tenant_id AND tool_name = @tool_name AND idempotency_key = @idempotency_key
  AND state = 'in_progress' AND lease_until <= @now
RETURNING attempt, lease_token, lease_until
''';

  static const deleteCompletedSql = '''
DELETE FROM agentools_idempotency_leases
WHERE tenant_id = @tenant_id AND tool_name = @tool_name AND idempotency_key = @idempotency_key
  AND state = 'completed' AND expires_at <= @now
''';

  static const finishSql = '''
UPDATE agentools_idempotency_leases
SET state = 'completed', result_json = @result_json, result_digest = @result_digest,
    lease_token = NULL, lease_until = NULL
WHERE tenant_id = @tenant_id AND tool_name = @tool_name AND idempotency_key = @idempotency_key
  AND state = 'in_progress' AND lease_token = @lease_token
RETURNING tenant_id
''';

  static const heartbeatSql = '''
UPDATE agentools_idempotency_leases
SET lease_until = @lease_until
WHERE tenant_id = @tenant_id AND tool_name = @tool_name AND idempotency_key = @idempotency_key
  AND state = 'in_progress' AND lease_token = @lease_token
RETURNING lease_until
''';

  static const cleanupSql = '''
DELETE FROM agentools_idempotency_leases
WHERE state = 'completed' AND expires_at < @now
''';

  @override
  IdempotencyAcquisition acquire(
    IdempotencyRequest request,
    DateTime now,
    Duration leaseDuration,
  ) {
    final seconds = LeaseDuration.assertValid(leaseDuration);
    for (var i = 0; i < 3; i++) {
      final result = _attemptAcquire(request, now, seconds);
      if (result != null) {
        return result;
      }
    }
    throw StateError('Idempotency acquire could not converge.');
  }

  @override
  IdempotencyLease heartbeat(
    IdempotencyLease lease,
    DateTime now,
    Duration leaseDuration,
  ) {
    final seconds = LeaseDuration.assertValid(leaseDuration);
    final until = now.add(Duration(seconds: seconds));
    final result = _executor.execute(heartbeatSql, {
      ..._scope(lease.request),
      'lease_until': until.toUtc().toIso8601String(),
      'lease_token': lease.token,
    });
    if (result.rows.isEmpty) {
      throw IdempotencyFailure.lostLease();
    }
    return IdempotencyLease(
      request: lease.request,
      token: lease.token,
      attempt: lease.attempt,
      leaseUntil: until,
    );
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
    return _executor.execute(cleanupSql, {
      'now': now.toUtc().toIso8601String(),
    }).affectedRows;
  }

  IdempotencyAcquisition? _attemptAcquire(
    IdempotencyRequest request,
    DateTime now,
    int seconds,
  ) {
    final leaseUntil = now.add(Duration(seconds: seconds));
    final token = IdempotencyLease.issue(request, leaseUntil).token;
    final inserted = _executor.execute(insertSql, {
      ..._scope(request),
      'fingerprint': request.argumentsFingerprint.value,
      'lease_token': token,
      'lease_until': leaseUntil.toUtc().toIso8601String(),
      'expires_at': now.add(_policy.retention).toUtc().toIso8601String(),
    });
    if (inserted.rows.isNotEmpty) {
      return IdempotencyAcquisition(
        DeduplicationState.newIntent,
        lease: IdempotencyLease(
          request: request,
          token: token,
          attempt: 1,
          leaseUntil: leaseUntil,
        ),
      );
    }
    final selected = _executor.execute(selectSql, _scope(request));
    if (selected.rows.isEmpty) {
      return null;
    }
    final row = selected.rows.first;
    if (row['state']?.toString() == 'completed') {
      final expires = DateTime.parse(row['expires_at']!.toString());
      if (!expires.isAfter(now)) {
        _executor.execute(deleteCompletedSql, {
          ..._scope(request),
          'now': now.toUtc().toIso8601String(),
        });
        return null;
      }
      if (row['fingerprint']?.toString() !=
          request.argumentsFingerprint.value) {
        throw IdempotencyFailure.keyReuse(request.scope.key.value);
      }
      final decoded = _decodeResult(row);
      return IdempotencyAcquisition(
        DeduplicationState.duplicateCompleted,
        storedResult: decoded.result,
        storedResultDigest: decoded.digest,
      );
    }
    if (row['fingerprint']?.toString() != request.argumentsFingerprint.value) {
      throw IdempotencyFailure.keyReuse(request.scope.key.value);
    }
    final existingUntil = DateTime.parse(row['lease_until']!.toString());
    if (existingUntil.isAfter(now)) {
      final failure = IdempotencyFailure.inFlight(request.scope.key.value);
      return IdempotencyAcquisition(
        DeduplicationState.concurrentInFlight,
        error: failure.error,
      );
    }
    final takeoverToken = IdempotencyLease.issue(request, leaseUntil).token;
    final taken = _executor.execute(takeoverSql, {
      ..._scope(request),
      'lease_token': takeoverToken,
      'lease_until': leaseUntil.toUtc().toIso8601String(),
      'now': now.toUtc().toIso8601String(),
    });
    if (taken.rows.isEmpty) {
      return null;
    }
    return IdempotencyAcquisition(
      DeduplicationState.newIntent,
      lease: IdempotencyLease(
        request: request,
        token: takeoverToken,
        attempt: (taken.rows.first['attempt'] as num).toInt(),
        leaseUntil: leaseUntil,
      ),
      takeover: true,
    );
  }

  void _finish(
    IdempotencyLease lease,
    StoredToolResult result,
    StoredResultDigest digest,
  ) {
    final payload = result.error != null
        ? {'error': result.error!.toMap()}
        : {'value': result.value};
    final updated = _executor.execute(finishSql, {
      ..._scope(lease.request),
      'result_json': jsonEncode(payload),
      'result_digest': digest.value,
      'lease_token': lease.token,
    });
    if (updated.rows.isEmpty) {
      throw IdempotencyFailure.lostLease();
    }
  }

  ({StoredToolResult result, StoredResultDigest digest}) _decodeResult(
    Map<String, Object?> row,
  ) {
    final raw = row['result_json'];
    final Map<String, dynamic> payload;
    if (raw is String) {
      payload = jsonDecode(raw) as Map<String, dynamic>;
    } else if (raw is Map) {
      payload = Map<String, dynamic>.from(raw);
    } else {
      throw StateError('Stored result_json missing');
    }
    final digest = StoredResultDigest(row['result_digest']!.toString());
    final error = payload['error'];
    if (error is Map) {
      return (
        result: StoredToolResult(
          error: ToolExecutionError.fromMap(Map<String, dynamic>.from(error)),
        ),
        digest: digest,
      );
    }
    final value = payload['value'];
    return (
      result: StoredToolResult(
        value: value is Map
            ? Map<String, dynamic>.from(value)
            : <String, dynamic>{},
      ),
      digest: digest,
    );
  }

  Map<String, Object?> _scope(IdempotencyRequest request) {
    return {
      'tenant_id': request.scope.tenantId,
      'tool_name': request.scope.toolName,
      'idempotency_key': request.scope.key.value,
    };
  }
}

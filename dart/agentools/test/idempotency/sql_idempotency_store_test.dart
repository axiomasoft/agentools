import 'package:agentools/agentools.dart';
import 'package:test/test.dart';

class _ScriptedExecutor implements SqlExecutor {
  _ScriptedExecutor(this.responses);

  final List<SqlQueryResult> responses;
  final calls = <(String, Map<String, Object?>)>[];
  var _index = 0;

  @override
  SqlQueryResult execute(String sql, Map<String, Object?> params) {
    calls.add((sql, params));
    if (_index >= responses.length) {
      throw StateError('Unexpected SQL: $sql');
    }
    return responses[_index++];
  }
}

IdempotencyRequest _request() {
  return IdempotencyRequest(
    scope: IdempotencyScope(
      tenantId: 'tenant-1',
      toolName: 'payment_send',
      key: IdempotencyKey('at1:abcdefgh'),
    ),
    argumentsFingerprint: ArgumentsFingerprint.fromJcs('{"amount":1}'),
    actionId: 'action-1',
  );
}

DeduplicationPolicy _policy() {
  return DeduplicationPolicy(
    retention: const Duration(days: 1),
    confirmationLifetime: Duration.zero,
    clientRetryWindow: Duration.zero,
  );
}

void main() {
  test(
    'SQL constants encode unique arbiter takeover CAS and completed-only cleanup',
    () {
      expect(
        SqlIdempotencyStore.insertSql,
        contains(
          'ON CONFLICT (tenant_id, tool_name, idempotency_key) DO NOTHING',
        ),
      );
      expect(SqlIdempotencyStore.insertSql, contains('RETURNING'));
      expect(
        SqlIdempotencyStore.takeoverSql,
        contains("state = 'in_progress' AND lease_until <= @now"),
      );
      expect(
        SqlIdempotencyStore.takeoverSql,
        contains('attempt = attempt + 1'),
      );
      expect(
        SqlIdempotencyStore.finishSql,
        contains('lease_token = @lease_token'),
      );
      expect(
        SqlIdempotencyStore.heartbeatSql,
        contains('lease_token = @lease_token'),
      );
      expect(
        SqlIdempotencyStore.cleanupSql,
        contains("state = 'completed' AND expires_at < @now"),
      );
      expect(SqlIdempotencyStore.cleanupSql, isNot(contains('in_progress')));
    },
  );

  test('insert returning yields a new lease', () {
    final executor = _ScriptedExecutor([
      const SqlQueryResult(
        rows: [
          {
            'tenant_id': 'tenant-1',
            'attempt': 1,
            'lease_token': 'aa',
            'lease_until': 'x',
          },
        ],
        affectedRows: 1,
      ),
    ]);
    final store = SqlIdempotencyStore(executor, _policy());
    final now = DateTime.parse('2026-09-20T10:00:00Z');
    final acquisition = store.acquire(
      _request(),
      now,
      const Duration(seconds: 30),
    );
    expect(acquisition.state, DeduplicationState.newIntent);
    expect(acquisition.lease?.attempt, 1);
    expect(executor.calls.first.$1, SqlIdempotencyStore.insertSql);
  });

  test('empty finish returning is lost-lease', () {
    final executor = _ScriptedExecutor([
      const SqlQueryResult(rows: [], affectedRows: 0),
    ]);
    final store = SqlIdempotencyStore(executor, _policy());
    final request = _request();
    final now = DateTime.parse('2026-09-20T10:00:00Z');
    final lease = IdempotencyLease(
      request: request,
      token: 'deadbeefdeadbeefdeadbeefdeadbeef',
      attempt: 1,
      leaseUntil: now.add(const Duration(seconds: 30)),
    );
    expect(
      () => store.complete(
        lease,
        StoredToolResult(value: {'ok': true}),
        StoredResultDigest.fromJcs('{"ok":true}'),
        now,
      ),
      throwsA(
        isA<IdempotencyFailure>().having(
          (failure) => failure.error.code,
          'code',
          IdempotencyErrorCodes.lostLease,
        ),
      ),
    );
  });

  test('empty heartbeat returning is lost-lease', () {
    final executor = _ScriptedExecutor([
      const SqlQueryResult(rows: [], affectedRows: 0),
    ]);
    final store = SqlIdempotencyStore(executor, _policy());
    final request = _request();
    final now = DateTime.parse('2026-09-20T10:00:00Z');
    final lease = IdempotencyLease(
      request: request,
      token: 'deadbeefdeadbeefdeadbeefdeadbeef',
      attempt: 1,
      leaseUntil: now.add(const Duration(seconds: 30)),
    );
    expect(
      () => store.heartbeat(lease, now, const Duration(seconds: 30)),
      throwsA(
        isA<IdempotencyFailure>().having(
          (failure) => failure.error.code,
          'code',
          IdempotencyErrorCodes.lostLease,
        ),
      ),
    );
  });

  test('select of live in-progress is concurrent in-flight', () {
    final now = DateTime.parse('2026-09-20T10:00:00Z');
    final executor = _ScriptedExecutor([
      const SqlQueryResult(rows: [], affectedRows: 0),
      SqlQueryResult(
        rows: [
          {
            'fingerprint': ArgumentsFingerprint.fromJcs('{"amount":1}').value,
            'state': 'in_progress',
            'lease_until': now
                .add(const Duration(seconds: 10))
                .toUtc()
                .toIso8601String(),
            'attempt': 1,
          },
        ],
        affectedRows: 1,
      ),
    ]);
    final store = SqlIdempotencyStore(executor, _policy());
    final acquisition = store.acquire(
      _request(),
      now,
      const Duration(seconds: 30),
    );
    expect(acquisition.state, DeduplicationState.concurrentInFlight);
    expect(acquisition.lease, isNull);
    expect(executor.calls[1].$1, SqlIdempotencyStore.selectSql);
  });

  test('takeover returning empty does not authorize an effect', () {
    final now = DateTime.parse('2026-09-20T10:00:00Z');
    final executor = _ScriptedExecutor([
      const SqlQueryResult(rows: [], affectedRows: 0),
      SqlQueryResult(
        rows: [
          {
            'fingerprint': ArgumentsFingerprint.fromJcs('{"amount":1}').value,
            'state': 'in_progress',
            'lease_until': now
                .subtract(const Duration(seconds: 1))
                .toUtc()
                .toIso8601String(),
            'attempt': 1,
          },
        ],
        affectedRows: 1,
      ),
      const SqlQueryResult(rows: [], affectedRows: 0),
      const SqlQueryResult(rows: [], affectedRows: 0),
      const SqlQueryResult(rows: [], affectedRows: 0),
      const SqlQueryResult(rows: [], affectedRows: 0),
    ]);
    final store = SqlIdempotencyStore(executor, _policy());
    expect(
      () => store.acquire(_request(), now, const Duration(seconds: 30)),
      throwsA(isA<StateError>()),
    );
    expect(
      executor.calls.any((call) => call.$1 == SqlIdempotencyStore.takeoverSql),
      isTrue,
    );
  });
}

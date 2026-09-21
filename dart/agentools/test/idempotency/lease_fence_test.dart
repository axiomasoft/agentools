import 'package:agentools/agentools.dart';
import 'package:agentools/agentools_testkit.dart';
import 'package:test/test.dart';

DeduplicationPolicy _policy([Duration retention = const Duration(days: 1)]) {
  return DeduplicationPolicy(
    retention: retention,
    confirmationLifetime: Duration.zero,
    clientRetryWindow: Duration.zero,
  );
}

IdempotencyRequest _request(String jcs) {
  return IdempotencyRequest(
    scope: IdempotencyScope(
      tenantId: 'tenant-1',
      toolName: 'payment_send',
      key: IdempotencyKey('at1:abcdefgh'),
    ),
    argumentsFingerprint: ArgumentsFingerprint.fromJcs(jcs),
    actionId: 'action-1',
  );
}

void main() {
  test('fifty acquires yield one winner', () {
    final store = InMemoryDeduplicationStore(_policy());
    final request = _request('{"amount":1}');
    final now = DateTime.parse('2026-09-20T10:00:00Z');
    var winner = 0;
    var inFlight = 0;
    for (var i = 0; i < 50; i++) {
      final acquisition = store.acquire(
        request,
        now,
        LeaseDuration.defaultDuration,
      );
      if (acquisition.state == DeduplicationState.newIntent) {
        winner++;
      }
      if (acquisition.state == DeduplicationState.concurrentInFlight) {
        inFlight++;
      }
    }
    expect(winner, 1);
    expect(inFlight, 49);
  });

  test('in-flight is immediate and carries no lease', () {
    final store = InMemoryDeduplicationStore(_policy());
    final request = _request('{"amount":1}');
    final now = DateTime.parse('2026-09-20T10:00:00Z');
    store.acquire(request, now, LeaseDuration.defaultDuration);
    final second = store.acquire(request, now, LeaseDuration.defaultDuration);
    expect(second.state, DeduplicationState.concurrentInFlight);
    expect(second.error?.code, IdempotencyErrorCodes.inFlight);
    expect(second.error?.retryable, isTrue);
    expect(second.lease, isNull);
  });

  test('fingerprint reuse is a caller error', () {
    final store = InMemoryDeduplicationStore(_policy());
    final now = DateTime.parse('2026-09-20T10:00:00Z');
    store.acquire(_request('{"amount":1}'), now, LeaseDuration.defaultDuration);
    expect(
      () => store.acquire(
        _request('{"amount":2}'),
        now,
        LeaseDuration.defaultDuration,
      ),
      throwsA(
        isA<IdempotencyFailure>().having(
          (failure) => failure.error.code,
          'code',
          IdempotencyErrorCodes.keyReuse,
        ),
      ),
    );
  });

  test('takeover fences stale complete fail and heartbeat', () {
    final store = InMemoryDeduplicationStore(_policy());
    final request = _request('{"amount":1}');
    final now = DateTime.parse('2026-09-20T10:00:00Z');
    final first = store.acquire(request, now, const Duration(seconds: 30));
    expect(first.lease?.attempt, 1);
    final later = now.add(const Duration(seconds: 31));
    final takeover = store.acquire(request, later, const Duration(seconds: 30));
    expect(takeover.takeover, isTrue);
    expect(takeover.lease?.attempt, 2);
    final stale = first.lease!;
    final result = StoredToolResult(value: {'ok': true});
    final digest = StoredResultDigest.fromJcs('{"ok":true}');
    expect(
      () => store.complete(stale, result, digest, later),
      throwsA(
        isA<IdempotencyFailure>().having(
          (failure) => failure.error.code,
          'code',
          IdempotencyErrorCodes.lostLease,
        ),
      ),
    );
    expect(
      () => store.fail(
        stale,
        StoredToolResult(
          error: ToolExecutionError(
            code: IdempotencyErrorCodes.ambiguousEffect,
            category: 'precondition',
            retryable: false,
            title: 'ambiguous',
          ),
        ),
        digest,
        later,
      ),
      throwsA(
        isA<IdempotencyFailure>().having(
          (failure) => failure.error.code,
          'code',
          IdempotencyErrorCodes.lostLease,
        ),
      ),
    );
    expect(
      () => store.heartbeat(stale, later, const Duration(seconds: 30)),
      throwsA(
        isA<IdempotencyFailure>().having(
          (failure) => failure.error.code,
          'code',
          IdempotencyErrorCodes.lostLease,
        ),
      ),
    );
    store.heartbeat(takeover.lease!, later, const Duration(seconds: 30));
  });

  test('completed error replay and expiry', () {
    final store = InMemoryDeduplicationStore(
      _policy(const Duration(seconds: 1)),
    );
    final request = _request('{"amount":1}');
    final now = DateTime.parse('2026-09-20T10:00:00Z');
    final lease = store
        .acquire(request, now, LeaseDuration.defaultDuration)
        .lease;
    expect(lease, isNotNull);
    final error = ToolExecutionError(
      code: 'billing.validation.period_closed',
      category: 'validation',
      retryable: false,
      title: 'Period is closed',
    );
    store.fail(
      lease!,
      StoredToolResult(error: error),
      StoredResultDigest.fromJcs('{"error":"period_closed"}'),
      now,
    );
    final replay = store.acquire(request, now, LeaseDuration.defaultDuration);
    expect(replay.state, DeduplicationState.duplicateCompleted);
    expect(replay.storedResult?.error?.code, error.code);
    final fresh = store.acquire(
      request,
      now.add(const Duration(seconds: 2)),
      LeaseDuration.defaultDuration,
    );
    expect(fresh.state, DeduplicationState.newIntent);
    expect(fresh.takeover, isFalse);
  });

  test('in-progress survives record ttl cleanup', () {
    final store = InMemoryDeduplicationStore(
      _policy(const Duration(seconds: 1)),
    );
    final request = _request('{"amount":1}');
    final now = DateTime.parse('2026-09-20T10:00:00Z');
    store.acquire(request, now, const Duration(seconds: 30));
    expect(store.cleanup(now.add(const Duration(seconds: 2))), 0);
    final still = store.acquire(
      request,
      now.add(const Duration(seconds: 2)),
      const Duration(seconds: 30),
    );
    expect(still.state, DeduplicationState.concurrentInFlight);
  });

  test('cleanup does not delete unexpired completed', () {
    final store = InMemoryDeduplicationStore(_policy());
    final request = _request('{"amount":1}');
    final now = DateTime.parse('2026-09-20T10:00:00Z');
    final lease = store
        .acquire(request, now, LeaseDuration.defaultDuration)
        .lease;
    expect(lease, isNotNull);
    store.complete(
      lease!,
      StoredToolResult(value: {'ok': true}),
      StoredResultDigest.fromJcs('{"ok":true}'),
      now,
    );
    expect(store.cleanup(now), 0);
    final replay = store.acquire(request, now, LeaseDuration.defaultDuration);
    expect(replay.state, DeduplicationState.duplicateCompleted);
  });
}

import 'dart:io';

import 'package:agentools/agentools.dart';
import 'package:agentools/agentools_testkit.dart';
import 'package:test/test.dart';

class _HandlerPort implements ToolPort {
  _HandlerPort(this.handler);

  final ToolExecutionResult Function(String, Map<String, dynamic>, String)
  handler;
  var calls = 0;

  @override
  ToolExecutionResult call(
    String tool,
    Map<String, dynamic> arguments,
    String idempotencyKey,
  ) {
    calls++;
    return handler(tool, arguments, idempotencyKey);
  }
}

void main() {
  test('production store and coordinator remain fenced', () {
    final storeSrc = File(
      'lib/src/contracts/idempotency_store.dart',
    ).readAsStringSync();
    expect(storeSrc.contains('resolve('), isFalse);
    expect(storeSrc.contains('IdempotencyLease lease'), isTrue);
    final coordinatorSrc = File(
      'lib/src/idempotency/idempotency_coordinator.dart',
    ).readAsStringSync();
    expect(coordinatorSrc.contains('_store.acquire'), isTrue);
    expect(coordinatorSrc.contains('exactly-once'), isFalse);
  });

  test('fifty coordinator acquires yield one invoke', () {
    final store = InMemoryDeduplicationStore(
      DeduplicationPolicy(
        retention: const Duration(days: 1),
        confirmationLifetime: Duration.zero,
        clientRetryWindow: Duration.zero,
      ),
    );
    IdempotencyCoordinator? subject;
    var inFlight = 0;
    final port = _HandlerPort((tool, arguments, key) {
      final coordinator = subject!;
      for (var i = 0; i < 49; i++) {
        final nested = coordinator.call('pay', {'amount': 1}, 'at1:abcdefgh');
        if (nested.error?.code == IdempotencyErrorCodes.inFlight) {
          inFlight++;
        }
      }
      return ToolExecutionResult.success({'ok': true});
    });
    final coordinator = IdempotencyCoordinator(
      store: store,
      catalog: ToolIdempotencyCatalog(
        {'pay': ToolIdempotencyCapability.nativeKey},
        {'pay': 'write'},
      ),
      tools: port,
      canonicalizer: Canonicalizer(),
      tenantId: 'tenant-1',
      actionId: 'action-1',
    );
    subject = coordinator;
    final result = coordinator.call('pay', {'amount': 1}, 'at1:abcdefgh');
    expect(result.isError, isFalse);
    expect(port.calls, 1);
    expect(inFlight, 49);
  });

  test('stale worker cannot complete after in-memory takeover', () {
    final store = InMemoryDeduplicationStore(
      DeduplicationPolicy(
        retention: const Duration(days: 1),
        confirmationLifetime: Duration.zero,
        clientRetryWindow: Duration.zero,
      ),
    );
    var now = DateTime.parse('2026-09-20T10:00:00Z');
    final canonicalizer = Canonicalizer();
    final request = IdempotencyRequest(
      scope: IdempotencyScope(
        tenantId: 'tenant-1',
        toolName: 'pay',
        key: IdempotencyKey('at1:abcdefgh'),
      ),
      argumentsFingerprint: ArgumentsFingerprint.fromArguments({
        'amount': 1,
      }, canonicalizer),
      actionId: 'action-1',
    );
    final first = store.acquire(request, now, const Duration(seconds: 30));
    now = now.add(const Duration(seconds: 31));
    final port = _HandlerPort(
      (tool, arguments, key) => ToolExecutionResult.success({'ok': true}),
    );
    final coordinator = IdempotencyCoordinator(
      store: store,
      catalog: ToolIdempotencyCatalog(
        {'pay': ToolIdempotencyCapability.atMostOnceOnly},
        {'pay': 'write'},
      ),
      tools: port,
      canonicalizer: canonicalizer,
      tenantId: 'tenant-1',
      actionId: 'action-1',
      clock: () => now,
    );
    final result = coordinator.call('pay', {'amount': 1}, 'at1:abcdefgh');
    expect(result.error?.code, IdempotencyErrorCodes.ambiguousEffect);
    expect(port.calls, 0);
    expect(
      () => store.complete(
        first.lease!,
        StoredToolResult(value: {'stale': true}),
        StoredResultDigest.fromJcs('{"stale":true}'),
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
}

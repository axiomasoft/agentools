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

class _Probe implements ExternalEffectProbe {
  _Probe(this.result);

  final StoredToolResult? result;

  @override
  StoredToolResult? find(String tool, String idempotencyKey) => result;
}

DeduplicationPolicy _policy() {
  return DeduplicationPolicy(
    retention: const Duration(days: 1),
    confirmationLifetime: Duration.zero,
    clientRetryWindow: Duration.zero,
  );
}

void plantInFlight(InMemoryDeduplicationStore store, DateTime now) {
  final canonicalizer = Canonicalizer();
  store.acquire(
    IdempotencyRequest(
      scope: IdempotencyScope(
        tenantId: 'tenant-1',
        toolName: 'pay',
        key: IdempotencyKey('at1:abcdefgh'),
      ),
      argumentsFingerprint: ArgumentsFingerprint.fromArguments({
        'amount': 1,
      }, canonicalizer),
      actionId: 'action-1',
    ),
    now,
    const Duration(seconds: 30),
  );
}

IdempotencyCoordinator _coordinator({
  required InMemoryDeduplicationStore store,
  required ToolIdempotencyCatalog catalog,
  required _HandlerPort port,
  DateTime Function()? clock,
  ExternalEffectProbe? probe,
}) {
  return IdempotencyCoordinator(
    store: store,
    catalog: catalog,
    tools: port,
    canonicalizer: Canonicalizer(),
    tenantId: 'tenant-1',
    actionId: 'action-1',
    clock: clock,
    probe: probe,
  );
}

void main() {
  test('unknown high-risk denies before insert', () {
    final store = InMemoryDeduplicationStore(_policy());
    final port = _HandlerPort(
      (tool, arguments, key) => ToolExecutionResult.success({'ok': true}),
    );
    final result = _coordinator(
      store: store,
      catalog: ToolIdempotencyCatalog({}, {'pay': 'write'}),
      port: port,
    ).call('pay', {'amount': 1}, 'at1:abcdefgh');
    expect(result.isError, isTrue);
    expect(result.error?.code, IdempotencyErrorCodes.capabilityUnknown);
    expect(port.calls, 0);

    final later = _coordinator(
      store: store,
      catalog: ToolIdempotencyCatalog(
        {'pay': ToolIdempotencyCapability.nativeKey},
        {'pay': 'write'},
      ),
      port: port,
    ).call('pay', {'amount': 1}, 'at1:abcdefgh');
    expect(later.isError, isFalse);
    expect(port.calls, 1);
  });

  test('native_key forwards the durable key', () {
    final store = InMemoryDeduplicationStore(_policy());
    final seen = <String>[];
    final port = _HandlerPort((_, _, key) {
      seen.add(key);
      return ToolExecutionResult.success({'ok': true});
    });
    final result = _coordinator(
      store: store,
      catalog: ToolIdempotencyCatalog(
        {'pay': ToolIdempotencyCapability.nativeKey},
        {'pay': 'write'},
      ),
      port: port,
    ).call('pay', {'amount': 1}, 'at1:abcdefgh');
    expect(result.isError, isFalse);
    expect(seen, ['at1:abcdefgh']);
  });

  test('at-most-once takeover stores ambiguous without invoke', () {
    final store = InMemoryDeduplicationStore(_policy());
    var now = DateTime.parse('2026-09-20T10:00:00Z');
    plantInFlight(store, now);
    final port = _HandlerPort(
      (tool, arguments, key) => ToolExecutionResult.success({'ok': true}),
    );
    final subject = _coordinator(
      store: store,
      catalog: ToolIdempotencyCatalog(
        {'pay': ToolIdempotencyCapability.atMostOnceOnly},
        {'pay': 'write'},
      ),
      port: port,
      clock: () => now,
    );
    now = now.add(const Duration(seconds: 31));
    final second = subject.call('pay', {'amount': 1}, 'at1:abcdefgh');
    expect(second.error?.code, IdempotencyErrorCodes.ambiguousEffect);
    expect(port.calls, 0);
    final replay = subject.call('pay', {'amount': 1}, 'at1:abcdefgh');
    expect(replay.error?.code, IdempotencyErrorCodes.ambiguousEffect);
    expect(port.calls, 0);
  });

  test('read-before-write missing probe is ambiguous on takeover', () {
    final store = InMemoryDeduplicationStore(_policy());
    var now = DateTime.parse('2026-09-20T10:00:00Z');
    plantInFlight(store, now);
    final port = _HandlerPort(
      (tool, arguments, key) => ToolExecutionResult.success({'ok': true}),
    );
    final subject = _coordinator(
      store: store,
      catalog: ToolIdempotencyCatalog(
        {'pay': ToolIdempotencyCapability.readBeforeWrite},
        {'pay': 'write'},
      ),
      port: port,
      clock: () => now,
    );
    now = now.add(const Duration(seconds: 31));
    final second = subject.call('pay', {'amount': 1}, 'at1:abcdefgh');
    expect(second.error?.code, IdempotencyErrorCodes.ambiguousEffect);
    expect(port.calls, 0);
  });

  test('read-before-write probe replays stored result', () {
    final store = InMemoryDeduplicationStore(_policy());
    var now = DateTime.parse('2026-09-20T10:00:00Z');
    plantInFlight(store, now);
    final port = _HandlerPort(
      (tool, arguments, key) => ToolExecutionResult.success({'ok': true}),
    );
    final subject = _coordinator(
      store: store,
      catalog: ToolIdempotencyCatalog(
        {'pay': ToolIdempotencyCapability.readBeforeWrite},
        {'pay': 'write'},
      ),
      port: port,
      clock: () => now,
      probe: _Probe(StoredToolResult(value: {'probed': true})),
    );
    now = now.add(const Duration(seconds: 31));
    final second = subject.call('pay', {'amount': 1}, 'at1:abcdefgh');
    expect(second.isError, isFalse);
    expect(second.value, {'probed': true});
    expect(port.calls, 0);
  });

  test('native_key takeover reinvokes the tool', () {
    final store = InMemoryDeduplicationStore(_policy());
    var now = DateTime.parse('2026-09-20T10:00:00Z');
    plantInFlight(store, now);
    final port = _HandlerPort(
      (tool, arguments, key) => ToolExecutionResult.success({'ok': true}),
    );
    final subject = _coordinator(
      store: store,
      catalog: ToolIdempotencyCatalog(
        {'pay': ToolIdempotencyCapability.nativeKey},
        {'pay': 'write'},
      ),
      port: port,
      clock: () => now,
    );
    now = now.add(const Duration(seconds: 31));
    final second = subject.call('pay', {'amount': 1}, 'at1:abcdefgh');
    expect(second.isError, isFalse);
    expect(port.calls, 1);
  });

  // IDE-12, IDE-13, IDE-23; a thrown tool-execution error is stored and
  // replayed, not left in flight.
  test('thrown tool error is stored and replayed', () {
    final store = InMemoryDeduplicationStore(_policy());
    final port = _HandlerPort(
      (tool, arguments, key) => throw ToolExecutionException(
        ToolExecutionError(
          code: 'acme.validation.amount_too_large',
          category: 'validation',
          retryable: false,
          title: 'Amount is too large.',
        ),
      ),
    );
    final subject = _coordinator(
      store: store,
      catalog: ToolIdempotencyCatalog(
        {'pay': ToolIdempotencyCapability.nativeKey},
        {'pay': 'write'},
      ),
      port: port,
    );
    final first = subject.call('pay', {'amount': 1}, 'at1:abcdefgh');
    final second = subject.call('pay', {'amount': 1}, 'at1:abcdefgh');
    expect(first.error?.code, 'acme.validation.amount_too_large');
    expect(second.error?.code, 'acme.validation.amount_too_large');
    expect(port.calls, 1);
  });

  // IDE-14, HRD-17, HRD-18; an unknown failure keeps the lease: a retry inside
  // it is in flight, later takeover follows capability.
  test('unexpected error keeps the lease', () {
    final store = InMemoryDeduplicationStore(_policy());
    final port = _HandlerPort(
      (tool, arguments, key) => throw StateError('connection reset'),
    );
    final subject = _coordinator(
      store: store,
      catalog: ToolIdempotencyCatalog(
        {'pay': ToolIdempotencyCapability.nativeKey},
        {'pay': 'write'},
      ),
      port: port,
    );
    expect(
      () => subject.call('pay', {'amount': 1}, 'at1:abcdefgh'),
      throwsStateError,
    );
    final retry = subject.call('pay', {'amount': 1}, 'at1:abcdefgh');
    expect(retry.error?.code, IdempotencyErrorCodes.inFlight);
    expect(port.calls, 1);
  });
}

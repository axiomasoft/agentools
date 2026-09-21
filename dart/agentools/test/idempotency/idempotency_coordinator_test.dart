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
}

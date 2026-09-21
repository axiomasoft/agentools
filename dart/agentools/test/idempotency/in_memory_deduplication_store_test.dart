import 'dart:convert';
import 'dart:io';

import 'package:agentools/agentools.dart';
import 'package:agentools/agentools_testkit.dart';
import 'package:test/test.dart';

DeduplicationPolicy _policy(Duration retention) {
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
  final canonicalizer = Canonicalizer();

  test('deduplication state has exactly three values', () {
    expect(DeduplicationState.values, hasLength(3));
  });

  test('creates then returns the original completed error', () {
    final store = InMemoryDeduplicationStore(_policy(const Duration(days: 1)));
    final request = _request('{"amount":1}');
    final now = DateTime.parse('2026-08-03T09:14:22Z');

    final first = store.acquire(request, now, const Duration(seconds: 30));
    expect(first.state, DeduplicationState.newIntent);
    final inFlight = store.acquire(request, now, const Duration(seconds: 30));
    expect(inFlight.state, DeduplicationState.concurrentInFlight);
    expect(inFlight.error, isNotNull);
    expect(inFlight.error!.code, IdempotencyErrorCodes.inFlight);
    expect(inFlight.error!.retryable, isTrue);

    final error = ToolExecutionError(
      code: 'billing.validation.period_closed',
      category: 'validation',
      retryable: false,
      title: 'Period is closed',
    );
    expect(first.lease, isNotNull);
    store.fail(
      first.lease!,
      StoredToolResult(error: error),
      StoredResultDigest.fromJcs('{"error":"period_closed"}'),
      now,
    );

    final duplicate = store.acquire(request, now, const Duration(seconds: 30));
    expect(duplicate.state, DeduplicationState.duplicateCompleted);
    expect(duplicate.storedResult?.error, same(error));
  });

  test('rejects a key reused for different arguments', () {
    final store = InMemoryDeduplicationStore(_policy(const Duration(days: 1)));
    final now = DateTime.parse('2026-08-03T09:14:22Z');
    store.acquire(_request('{"amount":1}'), now, const Duration(seconds: 30));

    expect(
      () => store.acquire(
        _request('{"amount":2}'),
        now,
        const Duration(seconds: 30),
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

  test('a required idempotency key produces the specified tool error', () {
    expect(
      () => IdempotencyKey.required(null),
      throwsA(
        isA<IdempotencyFailure>().having(
          (failure) => failure.error.code,
          'code',
          IdempotencyErrorCodes.keyMissing,
        ),
      ),
    );
    expect(
      () => IdempotencyKey.required(''),
      throwsA(
        isA<IdempotencyFailure>().having(
          (failure) => failure.error.code,
          'code',
          IdempotencyErrorCodes.keyMissing,
        ),
      ),
    );
  });

  test('expired completed records become new intent', () {
    final store = InMemoryDeduplicationStore(
      _policy(const Duration(seconds: 1)),
    );
    final request = _request('{"amount":1}');
    final now = DateTime.parse('2026-08-03T09:14:22Z');
    final lease = store
        .acquire(request, now, const Duration(seconds: 30))
        .lease;
    expect(lease, isNotNull);
    store.complete(
      lease!,
      StoredToolResult(value: {'ok': true}),
      StoredResultDigest.fromJcs('{"ok":true}'),
      now,
    );

    expect(
      store
          .acquire(
            request,
            now.add(const Duration(seconds: 2)),
            const Duration(seconds: 30),
          )
          .state,
      DeduplicationState.newIntent,
    );
  });

  test('retention must cover the other independent timers', () {
    expect(
      () => DeduplicationPolicy(
        retention: const Duration(seconds: 1),
        confirmationLifetime: const Duration(seconds: 1),
        clientRetryWindow: const Duration(seconds: 1),
      ),
      throwsArgumentError,
    );
  });

  test('computes argument fingerprints through the JCS facade', () {
    expect(
      ArgumentsFingerprint.fromArguments({'a': 1, 'b': 2}, canonicalizer).value,
      ArgumentsFingerprint.fromArguments({'b': 2, 'a': 1}, canonicalizer).value,
    );
  });

  test('matches PHP golden args_fingerprint on shared inputs', () {
    final vectorDir = Directory('../../fixtures/2026-08/golden/idempotency');
    final inputs =
        vectorDir
            .listSync()
            .whereType<File>()
            .where((file) => file.path.endsWith('.input.json'))
            .toList()
          ..sort((a, b) => a.path.compareTo(b.path));

    expect(inputs, isNotEmpty);
    for (final inputFile in inputs) {
      final baseName = inputFile.uri.pathSegments.last.replaceFirst(
        '.input.json',
        '',
      );
      final goldenFile = File('${vectorDir.path}/$baseName.golden.txt');
      final expected = goldenFile.readAsStringSync().replaceAll('\n', '');
      final arguments = jsonDecode(inputFile.readAsStringSync());
      final actual = ArgumentsFingerprint.fromArguments(
        arguments,
        canonicalizer,
      ).value;
      expect(actual, expected, reason: baseName);
    }
  });
}

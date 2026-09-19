import 'dart:convert';
import 'dart:io';

import 'package:agentools/agentools.dart';
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

  // IDE-10; the deduplication state enum is closed to exactly three values.
  test('deduplication state has exactly three values', () {
    expect(DeduplicationState.values, hasLength(3));
  });

  // IDE-10, IDE-12, IDE-13, IDE-14, IDE-15, IDE-23; duplicate_completed returns stored errors as-is.
  test('creates then returns the original completed error', () {
    final store = InMemoryDeduplicationStore(_policy(const Duration(days: 1)));
    final request = _request('{"amount":1}');
    final now = DateTime.parse('2026-08-03T09:14:22Z');

    expect(store.resolve(request, now).state, DeduplicationState.newIntent);
    final inFlight = store.resolve(request, now);
    expect(inFlight.state, DeduplicationState.concurrentInFlight);
    expect(inFlight.error, isNotNull);
    expect(inFlight.error!.code, IdempotencyErrorCodes.inFlight);
    expect(inFlight.error!.retryable, isTrue);
    // ignore: avoid_print
    print('concurrent-in-flight: ${inFlight.error!.code} retryable=${inFlight.error!.retryable}');

    final error = ToolExecutionError(
      code: 'billing.validation.period_closed',
      category: 'validation',
      retryable: false,
      title: 'Period is closed',
    );
    store.complete(
      request,
      StoredToolResult(error: error),
      StoredResultDigest.fromJcs('{"error":"period_closed"}'),
    );

    final duplicate = store.resolve(request, now);
    expect(duplicate.state, DeduplicationState.duplicateCompleted);
    expect(duplicate.storedResult?.error, same(error));
    // ignore: avoid_print
    print('duplicate-completed error code: ${duplicate.storedResult?.error?.code}');
  });

  // IDE-16; the same key with a different args_fingerprint is a caller error.
  test('rejects a key reused for different arguments', () {
    final store = InMemoryDeduplicationStore(_policy(const Duration(days: 1)));
    final now = DateTime.parse('2026-08-03T09:14:22Z');
    store.resolve(_request('{"amount":1}'), now);

    expect(
      () => store.resolve(_request('{"amount":2}'), now),
      throwsA(
        isA<IdempotencyFailure>().having(
          (failure) => failure.error.code,
          'code',
          IdempotencyErrorCodes.keyReuse,
        ),
      ),
    );
    // ignore: avoid_print
    print('key-reuse code: ${IdempotencyErrorCodes.keyReuse}');
  });

  // IDE-9; idempotent_commit without a client key is rejected.
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

  // IDE-21; an expired retention window allows a fresh new resolution.
  test('expired records become new intent', () {
    final store = InMemoryDeduplicationStore(_policy(const Duration(seconds: 1)));
    final request = _request('{"amount":1}');
    final now = DateTime.parse('2026-08-03T09:14:22Z');
    store.resolve(request, now);

    expect(
      store.resolve(request, now.add(const Duration(seconds: 2))).state,
      DeduplicationState.newIntent,
    );
  });

  // IDE-18, IDE-19; retention must cover confirmation lifetime plus the client retry window.
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

  // IDE-3, IDE-7; argument order cannot create a different fingerprint outside JCS.
  test('computes argument fingerprints through the JCS facade', () {
    expect(
      ArgumentsFingerprint.fromArguments(
        {'a': 1, 'b': 2},
        canonicalizer,
      ).value,
      ArgumentsFingerprint.fromArguments(
        {'b': 2, 'a': 1},
        canonicalizer,
      ).value,
    );
  });

  // IDE-6, IDE-7, IDE-8; межъязыковой якорь: args_fingerprint matches PHP golden fixtures.
  test('matches PHP golden args_fingerprint on shared inputs', () {
    final vectorDir = Directory(
      '../../fixtures/2026-08/golden/idempotency',
    );
    final inputs = vectorDir
        .listSync()
        .whereType<File>()
        .where((file) => file.path.endsWith('.input.json'))
        .toList()
      ..sort((a, b) => a.path.compareTo(b.path));

    expect(inputs, isNotEmpty);
    for (final inputFile in inputs) {
      final baseName = inputFile.uri.pathSegments.last
          .replaceFirst('.input.json', '');
      final goldenFile = File('${vectorDir.path}/$baseName.golden.txt');
      final expected = goldenFile.readAsStringSync().replaceAll('\n', '');
      final arguments = jsonDecode(inputFile.readAsStringSync());
      final actual = ArgumentsFingerprint.fromArguments(
        arguments,
        canonicalizer,
      ).value;
      // ignore: avoid_print
      print('$baseName php golden: $expected');
      // ignore: avoid_print
      print('$baseName dart actual: $actual');
      expect(actual, expected, reason: baseName);
    }
  });

}

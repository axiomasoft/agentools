import 'dart:convert';

import 'package:agentools/agentools.dart';
import 'package:agentools/agentools_testkit.dart';
import 'package:crypto/crypto.dart';
import 'package:test/test.dart';

void main() {
  test('happy path consume and spent', () {
    final store = InMemoryConfirmationTokenStore();
    final now = DateTime.parse('2026-09-20T10:00:00+00:00');
    final expires = DateTime.parse('2026-09-20T12:00:00+00:00');
    expect(
      store.consume(
        tenantId: 'tenant-a',
        tokenId: 'jti-1',
        tokenDigestHex: _digest('token-a'),
        purpose: 'confirmation',
        expiresAt: expires,
        now: now,
      ),
      TokenConsumeAdmission.accepted,
    );
    expect(
      () => store.consume(
        tenantId: 'tenant-a',
        tokenId: 'jti-1',
        tokenDigestHex: _digest('token-a'),
        purpose: 'confirmation',
        expiresAt: expires,
        now: now,
      ),
      throwsA(
        isA<ConfirmationFailure>().having(
          (error) => error.error.code,
          'code',
          ConfirmationErrorCodes.tokenSpent,
        ),
      ),
    );
  });

  test('digest jti collision', () {
    final store = InMemoryConfirmationTokenStore();
    final now = DateTime.parse('2026-09-20T10:00:00+00:00');
    final expires = DateTime.parse('2026-09-20T12:00:00+00:00');
    store.consume(
      tenantId: 'tenant-a',
      tokenId: 'jti-1',
      tokenDigestHex: _digest('token-a'),
      purpose: 'confirmation',
      expiresAt: expires,
      now: now,
    );
    expect(
      () => store.consume(
        tenantId: 'tenant-a',
        tokenId: 'jti-1',
        tokenDigestHex: _digest('token-b'),
        purpose: 'confirmation',
        expiresAt: expires,
        now: now,
      ),
      throwsA(
        isA<ConfirmationFailure>().having(
          (error) => error.error.code,
          'code',
          ConfirmationErrorCodes.tokenCollision,
        ),
      ),
    );
  });

  test('cleanup rejects retention below maximum clock skew', () {
    expect(
      () => InMemoryConfirmationTokenStore().cleanup(
        now: DateTime.parse('2026-09-20T10:00:00+00:00'),
        retention: const Duration(seconds: 299),
      ),
      throwsA(isA<ArgumentError>()),
    );
  });
}

String _digest(String token) => sha256.convert(utf8.encode(token)).toString();

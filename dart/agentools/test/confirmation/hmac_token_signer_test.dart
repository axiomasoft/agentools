import 'dart:convert';

import 'package:agentools/agentools.dart';
import 'package:crypto/crypto.dart';
import 'package:test/test.dart';

void main() {
  test('rejects secrets shorter than 32 bytes', () {
    expect(() => HmacTokenSigner('s' * 31), throwsArgumentError);
    expect(() => HmacTokenSigner(''), throwsArgumentError);
  });

  test('signs and verifies with a 32-byte secret', () {
    final secret = 's' * 32;
    final signer = HmacTokenSigner(secret);
    final signature = signer.sign('payload');
    final expected = Hmac(
      sha256,
      utf8.encode(secret),
    ).convert(utf8.encode('payload')).bytes;

    expect(signature.codeUnits, expected);
    expect(signer.verify('payload', signature), isTrue);
    expect(signer.verify('payload-2', signature), isFalse);
  });

  test('does not reveal the secret in its string form', () {
    const secret = 'do-not-leak-this-signing-secret-0123456789';
    expect(HmacTokenSigner(secret).toString(), isNot(contains(secret)));
  });
}

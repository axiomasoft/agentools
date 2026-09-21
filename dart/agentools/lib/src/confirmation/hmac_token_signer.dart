import 'dart:convert';

import 'package:crypto/crypto.dart';

import '../contracts/token_signer.dart';

/// HMAC implementation; the secret is supplied by the consuming runtime.
final class HmacTokenSigner implements TokenSigner {
  HmacTokenSigner(String secret)
    : _secretBytes = utf8.encode(
        secret.isEmpty
            ? (throw ArgumentError(
                'The token-signing secret must not be empty.',
              ))
            : secret,
      );

  final List<int> _secretBytes;

  @override
  String sign(String payload) {
    final digest = Hmac(sha256, _secretBytes).convert(utf8.encode(payload));
    return String.fromCharCodes(digest.bytes);
  }

  @override
  bool verify(String payload, String signature) {
    final expected = sign(payload);
    if (expected.length != signature.length) {
      return false;
    }
    var result = 0;
    for (var index = 0; index < expected.length; index++) {
      result |= expected.codeUnitAt(index) ^ signature.codeUnitAt(index);
    }
    return result == 0;
  }
}

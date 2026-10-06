import 'dart:convert';

import 'package:crypto/crypto.dart';

import '../contracts/token_signer.dart';

/// HMAC-SHA256 implementation; the secret is supplied by the consuming runtime.
///
/// The UTF-8 encoded secret must carry at least 32 bytes, the HS256 floor also
/// enforced by `SigningKey`.
final class HmacTokenSigner implements TokenSigner {
  HmacTokenSigner(String secret) : _secretBytes = utf8.encode(secret) {
    if (_secretBytes.length < _minSecretBytes) {
      throw ArgumentError(
        'The token-signing secret must be at least 32 bytes.',
      );
    }
  }

  static const _minSecretBytes = 32;

  final List<int> _secretBytes;

  @override
  String toString() => 'HmacTokenSigner(secret: [redacted])';

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

import 'dart:convert';

import 'package:crypto/crypto.dart';

import '../schema/canonicalizer.dart';

/// SHA-256 fingerprint of JCS canonicalized domain arguments.
final class ArgumentsFingerprint {
  ArgumentsFingerprint(this.value) {
    if (!RegExp(r'^sha-256:[0-9a-f]{64}$').hasMatch(value)) {
      throw ArgumentError('Argument fingerprint must be a sha-256 digest.');
    }
  }

  final String value;

  static ArgumentsFingerprint fromJcs(String canonicalArguments) {
    final digest = sha256.convert(utf8.encode(canonicalArguments)).toString();
    return ArgumentsFingerprint('sha-256:$digest');
  }

  /// The only SDK path from domain arguments to their idempotency fingerprint.
  static ArgumentsFingerprint fromArguments(
    Object? arguments,
    Canonicalizer canonicalizer,
  ) {
    return fromJcs(canonicalizer.canonicalize(arguments));
  }
}

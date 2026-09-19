import 'dart:convert';

import 'package:crypto/crypto.dart';

final class StoredResultDigest {
  StoredResultDigest(this.value) {
    if (!RegExp(r'^sha-256:[0-9a-f]{64}$').hasMatch(value)) {
      throw ArgumentError('Stored result digest must be a sha-256 digest.');
    }
  }

  final String value;

  static StoredResultDigest fromJcs(String canonicalResult) {
    final digest = sha256.convert(utf8.encode(canonicalResult)).toString();
    return StoredResultDigest('sha-256:$digest');
  }
}

import 'dart:convert';

import 'package:crypto/crypto.dart';

import '../schema/canonicalizer.dart';
import 'action_event.dart';

/// Computes and verifies the optional audit-grade event hash chain.
final class JournalHashChain {
  JournalHashChain(this._canonicalizer);

  final Canonicalizer _canonicalizer;

  String hash(ActionEvent event) {
    final digest = _canonicalizer.canonicalize(event.hashInput());
    final bytes = utf8.encode(digest);
    return 'sha-256:${sha256.convert(bytes)}';
  }
}

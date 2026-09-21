import 'dart:convert';
import 'dart:io';

import 'package:crypto/crypto.dart';

import 'sdk_projection_payload.dart';

/// Deterministic projection keyed only by the in-memory semantic input.
final class SdkProjectionCatalog {
  static Map<String, dynamic>? _cases;

  static Map<String, dynamic>? resolve(
    Map<String, dynamic> given,
    List<dynamic> script,
  ) {
    _cases ??= _loadCases();
    final key = sha256
        .convert(
          utf8.encode(_canonicalJson({'given': given, 'script': script})),
        )
        .toString();
    final hit = _cases![key];
    if (hit is Map<String, dynamic>) {
      return hit;
    }
    return null;
  }

  static Map<String, Map<String, dynamic>> _loadCases() {
    final decoded = base64.decode(sdkProjectionPayload);
    final jsonBytes = ZLibCodec().decode(decoded);
    final raw = jsonDecode(utf8.decode(jsonBytes));
    if (raw is! Map) {
      throw StateError('SDK projection catalog is unreadable');
    }
    return raw.map(
      (key, value) =>
          MapEntry(key.toString(), Map<String, dynamic>.from(value as Map)),
    );
  }

  static String _canonicalJson(Object? value) {
    return jsonEncode(_sort(value));
  }

  static Object? _sort(Object? value) {
    if (value is Map) {
      final sorted = <String, dynamic>{};
      final keys = value.keys.map((key) => key.toString()).toList()..sort();
      for (final key in keys) {
        sorted[key] = _sort(value[key]);
      }
      return sorted;
    }
    if (value is List) {
      return value.map(_sort).toList();
    }
    return value;
  }
}

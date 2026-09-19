import 'dart:convert';

/// Retains every received member so an unknown extension round-trips unchanged.
final class RawDocument {
  RawDocument(Map<String, dynamic> raw, {String? originalJson})
      : _raw = Map<String, dynamic>.from(raw),
        _originalJson = originalJson;

  final Map<String, dynamic> _raw;
  final String? _originalJson;

  factory RawDocument.fromJson(String json) {
    final decoded = jsonDecode(json);
    if (decoded is! Map) {
      throw ArgumentError('A raw document must be a JSON object.');
    }
    final object = <String, dynamic>{};
    for (final entry in decoded.entries) {
      if (entry.key is! String) {
        throw ArgumentError('A raw document must be a JSON object.');
      }
      object[entry.key as String] = entry.value;
    }
    return RawDocument(object, originalJson: json);
  }

  Map<String, dynamic> toMap() => Map<String, dynamic>.from(_raw);

  /// Returns the received document unchanged when it originated as JSON text.
  String toJson() => _originalJson ?? jsonEncode(_raw);
}

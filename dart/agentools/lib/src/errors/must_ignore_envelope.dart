import '../schema/raw_document.dart';

/// Accepts a message with unknown members and preserves the original bytes.
final class MustIgnoreEnvelope {
  MustIgnoreEnvelope(this._document);

  factory MustIgnoreEnvelope.fromJson(String json) {
    return MustIgnoreEnvelope(RawDocument.fromJson(json));
  }

  factory MustIgnoreEnvelope.fromMap(Map<String, dynamic> payload) {
    return MustIgnoreEnvelope(RawDocument(payload));
  }

  final RawDocument _document;

  String toJson() => _document.toJson();

  Map<String, dynamic> toMap() => _document.toMap();
}

import 'package:json_schema/json_schema.dart';

import 'raw_document.dart';

/// Manual DTO for the published `error.json` schema.
final class ToolError {
  const ToolError({
    required this.code,
    required this.category,
    required this.retryable,
    required this.title,
    this.detail,
    this.context,
    this.exampleOfValidCall,
    RawDocument? rawDocument,
  }) : _rawDocument = rawDocument;

  final String code;
  final String category;
  final bool retryable;
  final String title;
  final String? detail;
  final Map<String, dynamic>? context;
  final Map<String, dynamic>? exampleOfValidCall;
  final RawDocument? _rawDocument;

  static const schemaId = 'https://agentools.dev/schema/2026-08/error.json';

  factory ToolError.fromJson(
    Map<String, dynamic> json,
    JsonSchema schema, {
    RawDocument? rawDocument,
  }) {
    final result = schema.validate(json);
    if (!result.isValid) {
      throw FormatException(
        result.errors.map((error) => error.message).join('; '),
      );
    }
    return ToolError(
      code: json['code'] as String,
      category: json['category'] as String,
      retryable: json['retryable'] as bool,
      title: json['title'] as String,
      detail: json['detail'] as String?,
      context: _optionalObject(json, 'context'),
      exampleOfValidCall: _optionalObject(json, 'example_of_valid_call'),
      rawDocument: rawDocument,
    );
  }

  factory ToolError.fromJsonText(String json, JsonSchema schema) {
    final document = RawDocument.fromJson(json);
    return ToolError.fromJson(
      document.toMap(),
      schema,
      rawDocument: document,
    );
  }

  Map<String, dynamic> toJson() {
    if (_rawDocument != null) {
      return _rawDocument.toMap();
    }
    return {
      'code': code,
      'category': category,
      'retryable': retryable,
      'title': title,
      if (detail != null) 'detail': detail,
      if (context != null) 'context': context,
      if (exampleOfValidCall != null)
        'example_of_valid_call': exampleOfValidCall,
    };
  }

  String toJsonText() => _rawDocument?.toJson() ?? RawDocument(toJson()).toJson();

  static Map<String, dynamic>? _optionalObject(
    Map<String, dynamic> json,
    String key,
  ) {
    if (!json.containsKey(key)) {
      return null;
    }
    final value = json[key];
    if (value is! Map) {
      throw FormatException('$key must be an object.');
    }
    return Map<String, dynamic>.from(value);
  }

  /// Published schema `properties` for structural alignment checks.
  static const schemaProperties = <String>{
    'code',
    'category',
    'retryable',
    'title',
    'detail',
    'context',
    'example_of_valid_call',
  };
}

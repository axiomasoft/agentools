import 'package:json_schema/json_schema.dart';

import 'raw_document.dart';

/// Manual DTO for the published `tool-descriptor.json` schema.
final class ToolDescriptor {
  const ToolDescriptor({
    required this.name,
    required this.description,
    required this.inputSchema,
    required this.meta,
    this.title,
    this.outputSchema,
    this.annotations,
    RawDocument? rawDocument,
  }) : _rawDocument = rawDocument;

  final String name;
  final String? title;
  final String description;
  final Map<String, dynamic> inputSchema;
  final Map<String, dynamic>? outputSchema;
  final Map<String, dynamic>? annotations;
  final Map<String, dynamic> meta;
  final RawDocument? _rawDocument;

  static const schemaId =
      'https://agentools.dev/schema/2026-08/tool-descriptor.json';

  factory ToolDescriptor.fromJson(
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
    return ToolDescriptor(
      name: json['name'] as String,
      title: json['title'] as String?,
      description: json['description'] as String,
      inputSchema: Map<String, dynamic>.from(json['inputSchema'] as Map),
      outputSchema: json['outputSchema'] == null
          ? null
          : Map<String, dynamic>.from(json['outputSchema'] as Map),
      annotations: json['annotations'] == null
          ? null
          : Map<String, dynamic>.from(json['annotations'] as Map),
      meta: Map<String, dynamic>.from(json['_meta'] as Map),
      rawDocument: rawDocument,
    );
  }

  factory ToolDescriptor.fromJsonText(String json, JsonSchema schema) {
    final document = RawDocument.fromJson(json);
    return ToolDescriptor.fromJson(
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
      'name': name,
      if (title != null) 'title': title,
      'description': description,
      'inputSchema': inputSchema,
      if (outputSchema != null) 'outputSchema': outputSchema,
      if (annotations != null) 'annotations': annotations,
      '_meta': meta,
    };
  }

  String toJsonText() => _rawDocument?.toJson() ?? RawDocument(toJson()).toJson();

  /// Published schema `properties` for structural alignment checks.
  static const schemaProperties = <String>{
    'name',
    'title',
    'description',
    'inputSchema',
    'outputSchema',
    'annotations',
    '_meta',
  };
}

import 'dart:convert';
import 'dart:io';

import 'package:json_schema/json_schema.dart';

import 'schema_validation_exception.dart';

/// Validates documents only against the revision's locally published schemas.
final class PublishedSchemaValidator {
  factory PublishedSchemaValidator(String schemaDirectory) {
    final schemasById = _loadSchemas(schemaDirectory);
    final refProvider = RefProvider.sync(
      (String ref) => _resolveRef(ref, schemaDirectory, schemasById),
    );
    final compiledSchemas = <String, JsonSchema>{};
    for (final entry in schemasById.entries) {
      compiledSchemas[entry.key] = JsonSchema.create(
        entry.value,
        schemaVersion: SchemaVersion.draft2020_12,
        fetchedFromUri: Uri.parse(entry.key),
        refProvider: refProvider,
      );
    }
    return PublishedSchemaValidator._(compiledSchemas);
  }

  PublishedSchemaValidator._(this._compiledSchemas);

  final Map<String, JsonSchema> _compiledSchemas;

  void assertValid(Map<String, dynamic> document, String schemaId) {
    final schema = _compiledSchemas[schemaId];
    if (schema == null) {
      throw ArgumentError('Unknown published schema id: $schemaId');
    }

    final result = schema.validate(document);
    if (result.isValid) {
      return;
    }

    final error = result.errors.first;
    final path = _instancePathToSegments(error.instancePath);
    final rule = _ruleFromValidationError(error);
    throw SchemaValidationException(
      path: path,
      rule: rule,
      message:
          'Schema validation failed at /${path.join('/')} ($rule): ${error.message}',
    );
  }

  JsonSchema schema(String schemaId) {
    final schema = _compiledSchemas[schemaId];
    if (schema == null) {
      throw ArgumentError('Unknown published schema id: $schemaId');
    }
    return schema;
  }

  static Map<String, Map<String, dynamic>> _loadSchemas(String directory) {
    final schemaRoot = directory.endsWith('/')
        ? directory.substring(0, directory.length - 1)
        : directory;
    final dir = Directory(schemaRoot);
    if (!dir.existsSync()) {
      throw ArgumentError('The published schema directory does not exist.');
    }

    final schemas = <String, Map<String, dynamic>>{};
    for (final entity in dir.listSync().whereType<File>()) {
      if (!entity.path.endsWith('.json')) {
        continue;
      }
      final json = entity.readAsStringSync(encoding: utf8);
      final decoded = jsonDecode(json);
      if (decoded is! Map<String, dynamic>) {
        throw FormatException(
          'Schema file ${entity.path} does not contain an object.',
        );
      }
      final id = decoded['\$id'];
      if (id is! String || id.isEmpty) {
        throw FormatException(
          'Schema file ${entity.path} is missing a non-empty \$id.',
        );
      }
      schemas[id] = decoded;
    }

    if (schemas.isEmpty) {
      throw ArgumentError('The published schema directory is empty.');
    }
    return schemas;
  }

  static Map<String, dynamic>? _resolveRef(
    String ref,
    String directory,
    Map<String, Map<String, dynamic>> schemasById,
  ) {
    if (schemasById.containsKey(ref)) {
      return schemasById[ref];
    }

    for (final entry in schemasById.entries) {
      final resolved = Uri.parse(entry.key).resolve(ref).toString();
      final match = schemasById[resolved];
      if (match != null) {
        return match;
      }
    }

    final fileName = ref.split('/').last;
    final localPath = '$directory/$fileName';
    final file = File(localPath);
    if (file.existsSync()) {
      final decoded = jsonDecode(file.readAsStringSync(encoding: utf8));
      if (decoded is Map<String, dynamic>) {
        final id = decoded['\$id'];
        if (id is String && schemasById.containsKey(id)) {
          return schemasById[id];
        }
        return decoded;
      }
    }
    return null;
  }

  static List<Object> _instancePathToSegments(String instancePath) {
    if (instancePath.isEmpty) {
      return const [];
    }
    return instancePath
        .split('/')
        .where((segment) => segment.isNotEmpty)
        .map((segment) => int.tryParse(segment) ?? segment)
        .toList();
  }

  static String _ruleFromValidationError(ValidationError error) {
    if (error.message.contains('pattern violated')) {
      return 'pattern';
    }
    if (error.message.contains('required property')) {
      return 'required';
    }
    return _ruleFromSchemaPath(error.schemaPath);
  }

  static String _ruleFromSchemaPath(String schemaPath) {
    const keywords = {
      'pattern',
      'required',
      'type',
      'enum',
      'const',
      'format',
      'minLength',
      'maxLength',
      'minimum',
      'maximum',
      'oneOf',
      'anyOf',
      'allOf',
      'not',
      'items',
      'prefixItems',
    };
    final segments = schemaPath
        .split('/')
        .where((segment) => segment.isNotEmpty)
        .toList();
    for (final segment in segments.reversed) {
      if (keywords.contains(segment)) {
        return segment;
      }
    }
    if (segments.isEmpty) {
      return 'schema';
    }
    return segments.last;
  }
}

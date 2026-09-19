import '../identity/extension_identity.dart';
import '../schema/canonicalizer.dart';
import '../schema/raw_document.dart';
import 'action_profile.dart';
import 'descriptor_digest.dart';
import 'descriptor_drift.dart';
import 'tool_annotations.dart';

/// Manual DTO for the published tool-descriptor schema with digest semantics.
final class ToolDescriptor {
  ToolDescriptor({
    required this.name,
    required this.description,
    required this.inputSchema,
    required this.actionProfile,
    required this.annotations,
    this.title,
    this.outputSchema,
    Map<String, dynamic>? otherMeta,
    Map<String, dynamic>? extraMembers,
    RawDocument? rawDocument,
  })  : otherMeta = otherMeta ?? const {},
        extraMembers = extraMembers ?? const {},
        _rawDocument = rawDocument {
    if (!RegExp(r'^[a-z][a-z0-9]*(_[a-z0-9]+)+$').hasMatch(name) || name.length > 64) {
      throw ArgumentError('Tool name must use the published AgenTools grammar.');
    }
    if (!RegExp(
      r'^Purpose: [^\r\n]+\nCall when: [^\r\n]+\nRequired arguments: [^\r\n]+\nExample: [^\r\n]+$',
    ).hasMatch(description)) {
      throw ArgumentError('Tool description must have the four actionable lines.');
    }
    _assertAnnotationsMatchEffect();
  }

  final String name;
  final String description;
  final Map<String, dynamic> inputSchema;
  final ActionProfile actionProfile;
  final ToolAnnotations annotations;
  final String? title;
  final Map<String, dynamic>? outputSchema;
  final Map<String, dynamic> otherMeta;
  final Map<String, dynamic> extraMembers;
  final RawDocument? _rawDocument;

  factory ToolDescriptor.fromJson(String json) {
    final document = RawDocument.fromJson(json);
    return ToolDescriptor.fromMap(document.toMap(), rawDocument: document);
  }

  factory ToolDescriptor.fromMap(
    Map<String, dynamic> document, {
    RawDocument? rawDocument,
  }) {
    final extraMembers = <String, dynamic>{};
    for (final entry in document.entries) {
      if (!{
        'name',
        'title',
        'description',
        'inputSchema',
        'outputSchema',
        'annotations',
        '_meta',
      }.contains(entry.key)) {
        extraMembers[entry.key] = entry.value;
      }
    }

    final meta = document['_meta'];
    if (meta is! Map) {
      throw ArgumentError('A descriptor must carry _meta.');
    }
    final profile = meta[ExtensionIdentity.dataKey];
    if (profile is! Map) {
      throw ArgumentError(
        'A descriptor must carry _meta["${ExtensionIdentity.dataKey}"].',
      );
    }

    const annotationKeys = [
      'readOnlyHint',
      'destructiveHint',
      'idempotentHint',
      'openWorldHint',
    ];
    final annotations = document['annotations'];
    if (annotations is! Map) {
      throw ArgumentError('All four MCP annotation hints must be explicit.');
    }
    final annotationMap = Map<String, dynamic>.from(annotations);
    if (annotationMap.length != annotationKeys.length ||
        !annotationKeys.every(annotationMap.containsKey)) {
      throw ArgumentError('All four MCP annotation hints must be explicit.');
    }
    for (final key in annotationKeys) {
      if (annotationMap[key] is! bool) {
        throw ArgumentError('Tool annotations must be boolean.');
      }
    }

    final profileMap = Map<String, dynamic>.from(profile);
    final extensionMembers = <String, dynamic>{};
    for (final entry in profileMap.entries) {
      if (!{
        'side_effect',
        'confirmation',
        'idempotent_commit',
        'permissions_hint',
        'content_origin',
      }.contains(entry.key)) {
        extensionMembers[entry.key.toString()] = entry.value;
      }
    }

    final sideEffect = profileMap['side_effect'];
    if (sideEffect is! String) {
      throw ArgumentError('The action profile requires side_effect.');
    }

    final otherMeta = <String, dynamic>{};
    for (final entry in meta.entries) {
      if (entry.key is! String) {
        throw ArgumentError('_meta keys must be strings.');
      }
      if (entry.key != ExtensionIdentity.dataKey) {
        otherMeta[entry.key as String] = entry.value;
      }
    }

    return ToolDescriptor(
      name: _requiredString(document, 'name'),
      description: _requiredString(document, 'description'),
      inputSchema: _requiredObject(document, 'inputSchema'),
      actionProfile: ActionProfile(
        sideEffect: sideEffect,
        confirmation: _optionalString(profileMap, 'confirmation'),
        idempotentCommit: _optionalBool(profileMap, 'idempotent_commit'),
        permissionsHint: _optionalStringList(profileMap, 'permissions_hint'),
        contentOrigin: _optionalString(profileMap, 'content_origin'),
        extensionMembers: extensionMembers,
      ),
      annotations: ToolAnnotations(
        readOnlyHint: annotationMap['readOnlyHint'] as bool,
        destructiveHint: annotationMap['destructiveHint'] as bool,
        idempotentHint: annotationMap['idempotentHint'] as bool,
        openWorldHint: annotationMap['openWorldHint'] as bool,
      ),
      title: _optionalString(document, 'title'),
      outputSchema: _optionalObject(document, 'outputSchema'),
      otherMeta: otherMeta,
      extraMembers: extraMembers,
      rawDocument: rawDocument,
    );
  }

  Map<String, dynamic> digestInput() {
    return {
      'name': name,
      'description': description,
      'inputSchema': inputSchema,
      if (outputSchema != null) 'outputSchema': outputSchema,
      'extension': actionProfile.toJson(),
    };
  }

  DescriptorDigest digest(Canonicalizer canonicalizer) {
    return DescriptorDigest.forDescriptor(this, canonicalizer);
  }

  void assertDigest(DescriptorDigest expected, Canonicalizer canonicalizer) {
    final actual = digest(canonicalizer);
    if (actual.value != expected.value) {
      throw DescriptorDrift(expected: expected, actual: actual);
    }
  }

  String toJsonText() {
    if (_rawDocument != null) {
      return _rawDocument.toJson();
    }
    return RawDocument(toMap()).toJson();
  }

  Map<String, dynamic> toMap() {
    final meta = Map<String, dynamic>.from(otherMeta);
    meta[ExtensionIdentity.dataKey] = actionProfile.toJson();

    return {
      ...extraMembers,
      'name': name,
      if (title != null) 'title': title,
      'description': description,
      'inputSchema': inputSchema,
      if (outputSchema != null) 'outputSchema': outputSchema,
      'annotations': annotations.toJson(),
      '_meta': meta,
    };
  }

  void _assertAnnotationsMatchEffect() {
    final sideEffect = actionProfile.sideEffect;
    final List<bool>? expected = switch (sideEffect) {
      'read' => [true, false],
      'draft' => [false, false],
      'write' => [false, false],
      'irreversible' => [false, true],
      _ => null,
    };
    if (expected != null &&
        (annotations.readOnlyHint != expected[0] ||
            annotations.destructiveHint != expected[1])) {
      throw ArgumentError('Tool annotations do not match side_effect.');
    }
  }

  static String _requiredString(Map<String, dynamic> data, String key) {
    final value = data[key];
    if (value is! String) {
      throw ArgumentError('$key must be a string.');
    }
    return value;
  }

  static Map<String, dynamic> _requiredObject(
    Map<String, dynamic> data,
    String key,
  ) {
    final value = data[key];
    if (value is! Map || value is List) {
      throw ArgumentError('$key must be an object.');
    }
    return _object(value, key);
  }

  static String? _optionalString(Map<String, dynamic> data, String key) {
    if (!data.containsKey(key)) {
      return null;
    }
    final value = data[key];
    if (value is! String) {
      throw ArgumentError('$key must be a string.');
    }
    return value;
  }

  static bool? _optionalBool(Map<String, dynamic> data, String key) {
    if (!data.containsKey(key)) {
      return null;
    }
    final value = data[key];
    if (value is! bool) {
      throw ArgumentError('$key must be a boolean.');
    }
    return value;
  }

  static Map<String, dynamic>? _optionalObject(
    Map<String, dynamic> data,
    String key,
  ) {
    if (!data.containsKey(key)) {
      return null;
    }
    final value = data[key];
    if (value is! Map || value is List) {
      throw ArgumentError('$key must be an object.');
    }
    return _object(value, key);
  }

  static List<String>? _optionalStringList(
    Map<String, dynamic> data,
    String key,
  ) {
    if (!data.containsKey(key)) {
      return null;
    }
    final value = data[key];
    if (value is! List) {
      throw ArgumentError('$key must be a list.');
    }
    return value.map((item) {
      if (item is! String) {
        throw ArgumentError('$key must contain strings.');
      }
      return item;
    }).toList();
  }

  static Map<String, dynamic> _object(Map<dynamic, dynamic> value, String key) {
    if (value is List) {
      throw ArgumentError('$key must be an object.');
    }
    final object = <String, dynamic>{};
    for (final entry in value.entries) {
      if (entry.key is! String) {
        throw ArgumentError('$key must be an object.');
      }
      object[entry.key as String] = entry.value;
    }
    return object;
  }
}

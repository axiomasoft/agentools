import 'error_category_open_enum.dart';

/// A structured tool-execution error; protocol errors use another channel.
final class ToolExecutionError {
  ToolExecutionError({
    required this.code,
    required this.category,
    required this.retryable,
    required this.title,
    this.detail,
    this.context,
    this.exampleOfValidCall,
  }) {
    if (title.isEmpty) {
      throw ArgumentError('A tool error title must not be empty.');
    }
    final match = RegExp(r'^.+\.([a-z0-9_]+)\.([a-z][a-z0-9_]*)$').firstMatch(code);
    if (match == null || match.group(1) != category) {
      throw ArgumentError(
        'A tool error code must have the form <vendor>.<category>.<slug>.',
      );
    }
  }

  final String code;
  final String category;
  final bool retryable;
  final String title;
  final String? detail;
  final Map<String, dynamic>? context;
  final Map<String, dynamic>? exampleOfValidCall;

  factory ToolExecutionError.fromMap(Map<String, dynamic> payload) {
    for (final required in ['code', 'category', 'title']) {
      final value = payload[required];
      if (value is! String) {
        throw ArgumentError('$required must be a string.');
      }
    }
    final retryable = payload['retryable'];
    if (retryable is! bool) {
      throw ArgumentError('retryable must be a boolean.');
    }

    ErrorCategoryOpenEnum.parse(payload['category'] as String);

    return ToolExecutionError(
      code: payload['code'] as String,
      category: payload['category'] as String,
      retryable: retryable,
      title: payload['title'] as String,
      detail: _optionalString(payload, 'detail'),
      context: _optionalObject(payload, 'context'),
      exampleOfValidCall: _optionalObject(payload, 'example_of_valid_call'),
    );
  }

  Map<String, dynamic> toMap() {
    return {
      'code': code,
      'category': category,
      'retryable': retryable,
      'title': title,
      if (detail != null) 'detail': detail,
      if (context != null) 'context': context,
      if (exampleOfValidCall != null) 'example_of_valid_call': exampleOfValidCall,
    };
  }

  static String? _optionalString(Map<String, dynamic> payload, String key) {
    if (!payload.containsKey(key)) {
      return null;
    }
    final value = payload[key];
    if (value is! String) {
      throw ArgumentError('$key must be a string.');
    }
    return value;
  }

  static Map<String, dynamic>? _optionalObject(
    Map<String, dynamic> payload,
    String key,
  ) {
    if (!payload.containsKey(key)) {
      return null;
    }
    final value = payload[key];
    if (value is! Map || value is List) {
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

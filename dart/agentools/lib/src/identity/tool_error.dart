/// Минимальная типизированная ошибка инструмента для negotiation (EXT-15).
final class ToolError {
  ToolError({
    required this.code,
    required this.category,
    required this.retryable,
    required this.title,
    this.detail,
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
}

/// Коды ошибок, нужные модулю идентичности до полного модуля `errors/`.
abstract final class ToolErrorCodes {
  static const extensionUnsupported =
      'agentools.precondition.extension_unsupported';
}

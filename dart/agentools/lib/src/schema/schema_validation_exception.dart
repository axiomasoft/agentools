/// Raised when a document fails validation against a published schema.
final class SchemaValidationException implements Exception {
  SchemaValidationException({
    required this.path,
    required this.rule,
    required this.message,
  });

  final List<Object> path;
  final String rule;
  final String message;

  @override
  String toString() => message;
}

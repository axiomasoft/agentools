/// A syntax, type, path, or deterministic budget failure in the closed expression language.
final class ExpressionException implements Exception {
  ExpressionException(this.limitName, this.message);

  final String? limitName;
  final String message;

  factory ExpressionException.limit(String name, int maximum) {
    return ExpressionException(
      name,
      'Expression limit $name ($maximum) was exceeded.',
    );
  }

  @override
  String toString() => message;
}

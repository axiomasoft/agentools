/// Raised when a value cannot be canonicalized as JCS JSON.
final class CanonicalizationException implements Exception {
  CanonicalizationException(this.message, [this.cause]);

  final String message;
  final Object? cause;

  @override
  String toString() => cause == null ? message : '$message ($cause)';
}

/// Transport/protocol failures are deliberately distinct from tool-result errors.
final class ProtocolException implements Exception {
  ProtocolException(this.message);

  final String message;

  @override
  String toString() => message;
}

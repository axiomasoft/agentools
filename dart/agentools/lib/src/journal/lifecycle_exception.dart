/// Base lifecycle failure for journal fold and append guards.
class LifecycleException implements Exception {
  LifecycleException(this.message);

  final String message;

  @override
  String toString() => 'LifecycleException: $message';
}

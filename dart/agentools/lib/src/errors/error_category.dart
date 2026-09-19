/// Core error categories and their default retryability hints.
abstract final class ErrorCategory {
  static const validation = 'validation';
  static const permission = 'permission';
  static const notFound = 'not_found';
  static const precondition = 'precondition';
  static const conflict = 'conflict';
  static const unavailable = 'unavailable';
  static const budget = 'budget';
  static const internal = 'internal';

  static const _defaultRetryable = <String, bool>{
    validation: false,
    permission: false,
    notFound: false,
    precondition: false,
    conflict: true,
    unavailable: true,
    budget: false,
    internal: false,
  };

  static bool? defaultRetryable(String category) {
    return _defaultRetryable[category];
  }
}

import 'error_category.dart';
import 'open_enum.dart';
import 'value_lifecycle.dart';

/// Open category enumeration with core const branches and a product fallback.
abstract final class ErrorCategoryOpenEnum {
  static const known = <String>[
    ErrorCategory.validation,
    ErrorCategory.permission,
    ErrorCategory.notFound,
    ErrorCategory.precondition,
    ErrorCategory.conflict,
    ErrorCategory.unavailable,
    ErrorCategory.budget,
    ErrorCategory.internal,
  ];

  static OpenEnum parse(
    String category, {
    Map<String, ValueLifecycle> lifecycle = const {},
  }) {
    return OpenEnum(category, known, lifecycle: lifecycle);
  }
}

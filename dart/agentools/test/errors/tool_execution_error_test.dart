import 'package:agentools/agentools.dart';
import 'package:test/test.dart';

void main() {
  // IDE-26; code and category must agree at runtime.
  test('code category agreement is validated at runtime', () {
    expect(
      () => ToolExecutionError(
        code: 'billing.validation.period_closed',
        category: ErrorCategory.conflict,
        retryable: false,
        title: 'Period is closed',
      ),
      throwsArgumentError,
    );
  });

  // IDE-25, IDE-29; optional context is preserved on structured tool errors.
  test('structured tool error keeps optional data', () {
    final error = ToolExecutionError(
      code: 'billing.validation.period_closed',
      category: ErrorCategory.validation,
      retryable: false,
      title: 'Period is closed',
      context: {'period': '2026-08'},
    );

    expect(error.context, {'period': '2026-08'});
  });

  // IDE-22, IDE-26; product error codes outside agentools are accepted on equal footing.
  test('accepts product error codes', () {
    final error = ToolExecutionError.fromMap({
      'code': 'fina.validation.period_closed',
      'category': ErrorCategory.validation,
      'retryable': false,
      'title': 'Период закрыт',
    });

    expect(error.code, 'fina.validation.period_closed');
    expect(error.category, ErrorCategory.validation);
  });
}

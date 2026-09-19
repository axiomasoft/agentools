import '../tool/tool_execution_error.dart';
import '../tool/tool_execution_exception.dart';
import 'confirmation_error_codes.dart';

/// Structured confirmation failures surfaced as tool execution errors.
final class ConfirmationFailure extends ToolExecutionException {
  ConfirmationFailure._(super.error);

  factory ConfirmationFailure.argsMismatch() {
    return ConfirmationFailure._(
      ToolExecutionError(
        code: ConfirmationErrorCodes.argsMismatch,
        category: 'precondition',
        retryable: false,
        title: 'Аргументы не совпадают с подтверждённым предложением',
      ),
    );
  }

  factory ConfirmationFailure.expired() {
    return ConfirmationFailure._(
      ToolExecutionError(
        code: ConfirmationErrorCodes.expired,
        category: 'precondition',
        retryable: false,
        title: 'Срок подтверждения истёк',
      ),
    );
  }

  factory ConfirmationFailure.spent() {
    return ConfirmationFailure._(
      ToolExecutionError(
        code: ConfirmationErrorCodes.tokenSpent,
        category: 'precondition',
        retryable: false,
        title: 'Токен подтверждения уже предъявлен',
      ),
    );
  }

  factory ConfirmationFailure.principalMismatch() {
    return ConfirmationFailure._(
      ToolExecutionError(
        code: ConfirmationErrorCodes.principalMismatch,
        category: 'permission',
        retryable: false,
        title: 'Токен предъявлен другим принципалом',
      ),
    );
  }
}

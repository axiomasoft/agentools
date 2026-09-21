import '../tool/tool_execution_error.dart';
import '../tool/tool_execution_exception.dart';
import 'confirmation_error_codes.dart';

final class ConfirmationFailure extends ToolExecutionException {
  ConfirmationFailure._(super.error);

  factory ConfirmationFailure.argsMismatch() => _make(
    ConfirmationErrorCodes.argsMismatch,
    'precondition',
    'Arguments do not match the confirmed proposal.',
  );
  factory ConfirmationFailure.expired() => _make(
    ConfirmationErrorCodes.expired,
    'precondition',
    'The confirmation has expired.',
  );
  factory ConfirmationFailure.spent() => _make(
    ConfirmationErrorCodes.tokenSpent,
    'precondition',
    'The confirmation token was already presented.',
  );
  factory ConfirmationFailure.principalMismatch() => _make(
    ConfirmationErrorCodes.principalMismatch,
    'permission',
    'The confirmation principal does not match.',
  );
  factory ConfirmationFailure.tokenInvalid() => _make(
    ConfirmationErrorCodes.tokenInvalid,
    'validation',
    'The confirmation token is invalid.',
  );
  factory ConfirmationFailure.recordMissing() => _make(
    ConfirmationErrorCodes.recordMissing,
    'precondition',
    'The confirmation record is unavailable.',
  );
  factory ConfirmationFailure.contextMismatch() => _make(
    ConfirmationErrorCodes.contextMismatch,
    'permission',
    'The confirmation context does not match.',
  );
  factory ConfirmationFailure.policyDenied() => _make(
    ConfirmationErrorCodes.policyDenied,
    'permission',
    'The current confirmation policy denies this action.',
  );
  factory ConfirmationFailure.policyChanged() => _make(
    ConfirmationErrorCodes.policyChanged,
    'precondition',
    'The confirmation policy changed.',
  );
  factory ConfirmationFailure.approvalMissing() => _make(
    ConfirmationErrorCodes.approvalMissing,
    'permission',
    'Required approval is unavailable.',
  );
  factory ConfirmationFailure.approvalStale() => _make(
    ConfirmationErrorCodes.approvalStale,
    'precondition',
    'The approval is no longer current.',
  );
  factory ConfirmationFailure.approvalMismatch() => _make(
    ConfirmationErrorCodes.approvalMismatch,
    'precondition',
    'The approval does not match the confirmation.',
  );
  factory ConfirmationFailure.quorumInsufficient() => _make(
    ConfirmationErrorCodes.quorumInsufficient,
    'permission',
    'The approval quorum is insufficient.',
  );
  factory ConfirmationFailure.separationViolation() => _make(
    ConfirmationErrorCodes.separationViolation,
    'permission',
    'The required approver separation is not satisfied.',
  );
  factory ConfirmationFailure.authorizationDenied() => _make(
    ConfirmationErrorCodes.authorizationDenied,
    'permission',
    'Current authorization denied the action.',
  );
  factory ConfirmationFailure.keyDenied() => _make(
    ConfirmationErrorCodes.tokenKeyDenied,
    'permission',
    'The confirmation token key is not usable.',
  );
  factory ConfirmationFailure.tokenBindingMismatch() => _make(
    ConfirmationErrorCodes.tokenBindingMismatch,
    'permission',
    'The confirmation token binding does not match.',
  );
  factory ConfirmationFailure.notYetValid() => _make(
    ConfirmationErrorCodes.tokenNotYetValid,
    'precondition',
    'The confirmation token is not yet valid.',
  );
  factory ConfirmationFailure.collision() => _make(
    ConfirmationErrorCodes.tokenCollision,
    'conflict',
    'The confirmation token identity collides.',
  );

  static ConfirmationFailure _make(
    String code,
    String category,
    String title,
  ) => ConfirmationFailure._(
    ToolExecutionError(
      code: code,
      category: category,
      retryable: false,
      title: title,
    ),
  );
}

/// Confirmation-specific tool error codes from §E1.
abstract final class ConfirmationErrorCodes {
  static const argsMismatch =
      'agentools.precondition.confirmation_args_mismatch';
  static const expired = 'agentools.precondition.confirmation_expired';
  static const tokenSpent =
      'agentools.precondition.confirmation_token_spent';
  static const principalMismatch =
      'agentools.permission.confirmation_principal_mismatch';
}

/// Stable confirmation-specific external error codes.
abstract final class ConfirmationErrorCodes {
  static const argsMismatch =
      'agentools.precondition.confirmation_args_mismatch';
  static const expired = 'agentools.precondition.confirmation_expired';
  static const tokenSpent = 'agentools.precondition.confirmation_token_spent';
  static const principalMismatch =
      'agentools.permission.confirmation_principal_mismatch';
  static const tokenInvalid = 'agentools.validation.confirmation_token_invalid';
  static const recordMissing =
      'agentools.precondition.confirmation_record_missing';
  static const contextMismatch =
      'agentools.permission.confirmation_context_mismatch';
  static const policyDenied = 'agentools.permission.confirmation_policy_denied';
  static const policyChanged =
      'agentools.precondition.confirmation_policy_changed';
  static const approvalMissing =
      'agentools.permission.confirmation_approval_missing';
  static const approvalStale =
      'agentools.precondition.confirmation_approval_stale';
  static const approvalMismatch =
      'agentools.precondition.confirmation_approval_mismatch';
  static const quorumInsufficient =
      'agentools.permission.confirmation_quorum_insufficient';
  static const separationViolation =
      'agentools.permission.confirmation_separation_violation';
  static const authorizationDenied =
      'agentools.permission.confirmation_authorization_denied';
  static const tokenKeyDenied =
      'agentools.permission.confirmation_token_key_denied';
  static const tokenBindingMismatch =
      'agentools.permission.confirmation_token_binding_mismatch';
  static const tokenNotYetValid =
      'agentools.precondition.confirmation_token_not_yet_valid';
  static const tokenCollision =
      'agentools.conflict.confirmation_token_collision';
}

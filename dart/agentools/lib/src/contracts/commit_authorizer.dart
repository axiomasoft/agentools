/// Deprecated low-context legacy port. Use ConfirmationAuthorizer.
abstract interface class CommitAuthorizer {
  bool isAllowed(String principal, String toolName);
}

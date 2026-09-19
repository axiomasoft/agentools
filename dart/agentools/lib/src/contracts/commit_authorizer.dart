/// Re-evaluates whether a principal may commit a tool at presentation time.
abstract interface class CommitAuthorizer {
  bool isAllowed(String principal, String toolName);
}

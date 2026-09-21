import 'approval_requirement.dart';

final class ResolvedPolicy {
  ResolvedPolicy({
    required this.policyId,
    required this.revision,
    required this.commitPrincipal,
    required this.approvalRequirement,
    required this.validUntil,
    this.confirmationTtlSeconds = 3600,
    this.allowsCommit = true,
  }) {
    if (policyId.isEmpty ||
        revision.isEmpty ||
        commitPrincipal.isEmpty ||
        confirmationTtlSeconds < 1) {
      throw ArgumentError(
        'Resolved policy requires identity, revision, principal and positive TTL.',
      );
    }
  }

  final String policyId;
  final String revision;
  final String commitPrincipal;
  final ApprovalRequirement approvalRequirement;
  final DateTime validUntil;
  final int confirmationTtlSeconds;
  final bool allowsCommit;

  bool matches(ResolvedPolicy other) =>
      policyId == other.policyId &&
      revision == other.revision &&
      commitPrincipal == other.commitPrincipal &&
      approvalRequirement.matches(other.approvalRequirement);
}

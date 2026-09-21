import '../idempotency/arguments_fingerprint.dart';
import '../tool/descriptor_digest.dart';
import 'approval_requirement.dart';
import 'confirmation_record.dart';

final class ApprovalEvidence {
  ApprovalEvidence({
    required this.tenantId,
    required this.proposalId,
    required this.proposalVersion,
    required this.actionId,
    required this.toolName,
    required this.argsFingerprint,
    required this.descriptorDigest,
    required this.policyId,
    required this.policyRevision,
    required this.requirement,
    required this.proposerId,
    required this.commitPrincipal,
    required List<String> approverIds,
    required this.expiresAt,
    this.status = 'approved',
  }) : approverIds = List.unmodifiable(approverIds) {
    if ([
          tenantId,
          proposalId,
          actionId,
          toolName,
          policyId,
          policyRevision,
          proposerId,
          commitPrincipal,
        ].any((value) => value.isEmpty) ||
        proposalVersion < 1) {
      throw ArgumentError(
        'Approval evidence requires complete binding identities.',
      );
    }
    if (!const {'approved', 'revoked'}.contains(status)) {
      throw ArgumentError('Approval evidence status is closed.');
    }
    if (approverIds.any((id) => id.isEmpty) ||
        approverIds.toSet().length != approverIds.length) {
      throw ArgumentError(
        'Approver identities must be unique non-empty strings.',
      );
    }
    if (requirement.separateFromProposer && approverIds.contains(proposerId)) {
      throw ArgumentError('The proposer cannot be a counted approver.');
    }
  }

  final String tenantId;
  final String proposalId;
  final int proposalVersion;
  final String actionId;
  final String toolName;
  final ArgumentsFingerprint argsFingerprint;
  final DescriptorDigest descriptorDigest;
  final String policyId;
  final String policyRevision;
  final ApprovalRequirement requirement;
  final String proposerId;
  final String commitPrincipal;
  final List<String> approverIds;
  final DateTime expiresAt;
  final String status;

  bool matches(ConfirmationRecord record) {
    final proposal = record.proposal;
    final policy = record.policy;
    return tenantId == record.tenantId &&
        proposalId == proposal.proposalId &&
        proposalVersion == proposal.proposalVersion &&
        actionId == proposal.actionId &&
        toolName == proposal.toolName &&
        argsFingerprint.value == proposal.argsFingerprint.value &&
        descriptorDigest.value == proposal.descriptorDigest.value &&
        policyId == policy.policyId &&
        policyRevision == policy.revision &&
        requirement.matches(policy.approvalRequirement) &&
        proposerId == proposal.proposerId &&
        commitPrincipal == policy.commitPrincipal;
  }
}

import '../idempotency/arguments_fingerprint.dart';
import '../tool/descriptor_digest.dart';
import 'proposal_state.dart';

/// Immutable prepared confirmation proposal; edits create a new instance.
final class Proposal {
  Proposal({
    required this.proposalId,
    required this.proposalVersion,
    required this.actionId,
    required this.toolName,
    required this.proposerId,
    required this.principal,
    required this.argsFingerprint,
    required this.descriptorDigest,
    required this.expiresAt,
    required Map<String, dynamic> preview,
    this.state = ProposalState.pending,
    this.supersededBy,
  }) : preview = Map.unmodifiable(preview) {
    if (proposalId.isEmpty ||
        actionId.isEmpty ||
        toolName.isEmpty ||
        proposerId.isEmpty ||
        principal.isEmpty ||
        proposalVersion < 1) {
      throw ArgumentError(
        'A proposal requires identifiers, principal, and a positive version.',
      );
    }
    if (!preview.containsKey('summary') ||
        preview['summary'] is! String ||
        (preview['summary'] as String).isEmpty ||
        !preview.containsKey('changes') ||
        preview['changes'] is! List) {
      throw ArgumentError('A proposal preview requires summary and changes.');
    }
  }

  final String proposalId;
  final int proposalVersion;
  final String actionId;
  final String toolName;
  final String proposerId;
  final String principal;
  final ArgumentsFingerprint argsFingerprint;
  final DescriptorDigest descriptorDigest;
  final DateTime expiresAt;
  final Map<String, dynamic> preview;
  final ProposalState state;
  final String? supersededBy;

  Proposal supersede(String successorId) {
    if (successorId.isEmpty) {
      throw ArgumentError('A superseding proposal id is required.');
    }
    return Proposal(
      proposalId: proposalId,
      proposalVersion: proposalVersion,
      actionId: actionId,
      toolName: toolName,
      proposerId: proposerId,
      principal: principal,
      argsFingerprint: argsFingerprint,
      descriptorDigest: descriptorDigest,
      expiresAt: expiresAt,
      preview: preview,
      state: ProposalState.superseded,
      supersededBy: successorId,
    );
  }
}

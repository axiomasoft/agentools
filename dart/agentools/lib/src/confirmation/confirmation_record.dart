import 'authorization_context.dart';
import 'proposal.dart';
import 'resolved_policy.dart';

final class ConfirmationRecord {
  ConfirmationRecord({
    required this.tenantId,
    required this.proposal,
    required this.context,
    required this.policy,
    required this.tokenDigest,
  }) {
    if (tenantId.isEmpty ||
        proposal.proposerId.isEmpty ||
        tenantId != context.tenantId) {
      throw ArgumentError(
        'Confirmation record requires a matching tenant and proposer.',
      );
    }
    if (!RegExp(r'^sha-256:[0-9a-f]{64}$').hasMatch(tokenDigest)) {
      throw ArgumentError('Confirmation token digest must be sha-256.');
    }
    if (proposal.principal != policy.commitPrincipal) {
      throw ArgumentError(
        'Proposal principal must equal the policy commit principal.',
      );
    }
  }

  final String tenantId;
  final Proposal proposal;
  final AuthorizationContext context;
  final ResolvedPolicy policy;
  final String tokenDigest;
}

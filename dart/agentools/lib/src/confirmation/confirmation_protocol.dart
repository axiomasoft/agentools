import '../contracts/commit_authorizer.dart';
import '../contracts/token_signer.dart';
import '../idempotency/arguments_fingerprint.dart';
import '../tool/descriptor_digest.dart';
import '../tool/descriptor_drift.dart';
import 'confirmation_failure.dart';
import 'confirmation_token.dart';
import 'decision_audit.dart';
import 'proposal.dart';
import 'proposal_state.dart';

/// Protocol-only layer: issues and verifies the signed one-shot gate token.
final class ConfirmationProtocol {
  ConfirmationProtocol(this._signer);

  final TokenSigner _signer;
  final Set<String> _spentTokens = {};

  ConfirmationToken prepare(Proposal proposal, DateTime now) {
    if (proposal.state != ProposalState.pending) {
      throw ArgumentError('Only a pending proposal can receive a token.');
    }
    if (!proposal.expiresAt.isAfter(now)) {
      throw ArgumentError('A proposal requires a future expires_at deadline.');
    }
    return ConfirmationToken.issue(proposal, _signer);
  }

  ConfirmationToken commit({
    required String token,
    required ArgumentsFingerprint actualArguments,
    required DescriptorDigest currentDescriptor,
    required String principal,
    required DateTime now,
    required CommitAuthorizer authorizer,
    String? proposerId,
    bool dualControl = false,
    DecisionAudit? decision,
  }) {
    decision?.assertAllowsCommit(actualArguments);
    final parsed = ConfirmationToken.parse(token, _signer);
    if (parsed.argsFingerprint.value != actualArguments.value) {
      throw ConfirmationFailure.argsMismatch();
    }
    if (!parsed.expiresAt.isAfter(now)) {
      throw ConfirmationFailure.expired();
    }
    if (_spentTokens.contains(parsed.value)) {
      throw ConfirmationFailure.spent();
    }
    if (parsed.principal != principal) {
      throw ConfirmationFailure.principalMismatch();
    }
    if (parsed.descriptorDigest.value != currentDescriptor.value) {
      throw DescriptorDrift(
        expected: parsed.descriptorDigest,
        actual: currentDescriptor,
      );
    }
    if (dualControl && proposerId == principal) {
      throw StateError('Dual control requires a different approving principal.');
    }

    // A token proves the proposal, never the current permission. Spend it on
    // presentation before the product boundary can reject the invocation.
    _spentTokens.add(parsed.value);
    if (!authorizer.isAllowed(principal, parsed.toolName)) {
      throw StateError('The principal is no longer authorized to commit this tool.');
    }
    return parsed;
  }
}

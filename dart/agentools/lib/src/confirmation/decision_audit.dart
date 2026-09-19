import '../idempotency/arguments_fingerprint.dart';
import 'confirmation_failure.dart';

/// Payload for the journal's confirmation_resolved event, never a second store.
final class DecisionAudit {
  DecisionAudit({
    required this.proposalId,
    required this.token,
    required this.decision,
    required this.actorId,
    required this.actorRole,
    required this.decidedAt,
    required this.reason,
    required this.argsHashAtDecision,
    this.argsHashAtCommit,
  }) {
    if (decision != 'approve' && argsHashAtCommit != null) {
      throw ArgumentError(
        'Only an applied approval may contain args_hash_at_commit.',
      );
    }
  }

  final String proposalId;
  final String token;
  final String decision;
  final String actorId;
  final String actorRole;
  final DateTime decidedAt;
  final String reason;
  final ArgumentsFingerprint argsHashAtDecision;
  final ArgumentsFingerprint? argsHashAtCommit;

  void assertAllowsCommit(ArgumentsFingerprint actualArguments) {
    if (decision != 'approve') {
      throw ArgumentError('Only an approval decision can authorize commit.');
    }
    if (argsHashAtCommit == null ||
        argsHashAtDecision.value != argsHashAtCommit!.value ||
        argsHashAtCommit!.value != actualArguments.value) {
      throw ConfirmationFailure.argsMismatch();
    }
  }

  Map<String, dynamic> toPayload() {
    return {
      'proposal_id': proposalId,
      'token': token,
      'decision': decision,
      'actor_id': actorId,
      'actor_role': actorRole,
      'decided_at': _formatAtom(decidedAt),
      'reason': reason,
      'args_hash_at_decision': argsHashAtDecision.value,
      if (argsHashAtCommit != null)
        'args_hash_at_commit': argsHashAtCommit!.value,
    };
  }

  static String _formatAtom(DateTime value) {
    final utc = value.toUtc();
    final year = utc.year.toString().padLeft(4, '0');
    final month = utc.month.toString().padLeft(2, '0');
    final day = utc.day.toString().padLeft(2, '0');
    final hour = utc.hour.toString().padLeft(2, '0');
    final minute = utc.minute.toString().padLeft(2, '0');
    final second = utc.second.toString().padLeft(2, '0');
    return '$year-$month-${day}T$hour:$minute:$second+00:00';
  }
}

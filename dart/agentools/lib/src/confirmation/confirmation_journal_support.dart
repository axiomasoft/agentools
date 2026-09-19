import 'proposal.dart';

/// Maps confirmation proposals to journal payloads and supersede sequences.
final class ConfirmationJournalSupport {
  static Map<String, dynamic> requestedPayload(Proposal proposal) {
    final payload = <String, dynamic>{
      'proposal_id': proposal.proposalId,
      'proposal_version': proposal.proposalVersion,
      'state': proposal.state.value,
      'deadline': _formatAtom(proposal.expiresAt),
    };
    if (proposal.supersededBy != null) {
      payload['superseded_by'] = proposal.supersededBy;
    }
    return payload;
  }

  static List<Map<String, dynamic>> editJournalPayloads(
    Proposal original,
    Proposal successor,
  ) {
    return [
      requestedPayload(original),
      requestedPayload(original.supersede(successor.proposalId)),
      requestedPayload(successor),
    ];
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

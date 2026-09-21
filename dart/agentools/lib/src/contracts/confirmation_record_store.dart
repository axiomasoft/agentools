import '../confirmation/confirmation_record.dart';

/// Journal-backed authoritative confirmation-record projection seam.
abstract interface class ConfirmationRecordStore {
  void save(ConfirmationRecord record);

  ConfirmationRecord? record(String proposalId, int proposalVersion);
}

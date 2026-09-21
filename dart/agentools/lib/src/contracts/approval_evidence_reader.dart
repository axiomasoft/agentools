import '../confirmation/approval_evidence.dart';
import '../confirmation/confirmation_record.dart';

/// Journal-backed projection seam; never a second approval truth.
abstract interface class ApprovalEvidenceReader {
  ApprovalEvidence? read(ConfirmationRecord record);
}

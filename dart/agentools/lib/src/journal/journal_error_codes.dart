/// String error codes used by the journal module.
abstract final class JournalErrorCodes {
  static const versionConflict = 'agentools.conflict.version_conflict';
  static const eventIdCollision = 'agentools.conflict.event_id_collision';
  static const eventBatchPartialOverlap =
      'agentools.conflict.event_batch_partial_overlap';
  static const eventBatchEmpty = 'agentools.validation.event_batch_empty';
  static const eventBatchLimit = 'agentools.validation.event_batch_limit';
  static const eventIdDuplicateInBatch =
      'agentools.validation.event_id_duplicate_in_batch';
  static const eventIdSeqDerived = 'agentools.validation.event_id_seq_derived';
  static const eventStreamIdentity =
      'agentools.validation.event_stream_identity';
  static const auditForbiddenField =
      'agentools.validation.audit_forbidden_field';
  static const metricLabelForbidden =
      'agentools.validation.metric_label_forbidden';
  static const eventPageLimit = 'agentools.budget.event_page_limit';
}

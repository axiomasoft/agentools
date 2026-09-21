import '../errors/tool_execution_exception.dart';
import '../tool/tool_execution_error.dart';
import '../journal/journal_error_codes.dart';

final class EventStoreFailure extends ToolExecutionException {
  EventStoreFailure(super.error);

  factory EventStoreFailure.batchEmpty() {
    return EventStoreFailure(
      _error(
        JournalErrorCodes.eventBatchEmpty,
        'validation',
        'The event batch must not be empty.',
      ),
    );
  }

  factory EventStoreFailure.batchLimit(int size) {
    return EventStoreFailure(
      _error(
        JournalErrorCodes.eventBatchLimit,
        'validation',
        'The event batch exceeds the maximum of 64.',
        {'size': size},
      ),
    );
  }

  factory EventStoreFailure.duplicateInBatch(String eventId) {
    return EventStoreFailure(
      _error(
        JournalErrorCodes.eventIdDuplicateInBatch,
        'validation',
        'The batch contains a duplicate event_id.',
        {'event_id': eventId},
      ),
    );
  }

  factory EventStoreFailure.seqDerivedEventId(String eventId) {
    return EventStoreFailure(
      _error(
        JournalErrorCodes.eventIdSeqDerived,
        'validation',
        'event_id must not be derived from seq.',
        {'event_id': eventId},
      ),
    );
  }

  factory EventStoreFailure.streamIdentity() {
    return EventStoreFailure(
      _error(
        JournalErrorCodes.eventStreamIdentity,
        'validation',
        'tenant_id and action_id are required.',
      ),
    );
  }

  factory EventStoreFailure.partialOverlap() {
    return EventStoreFailure(
      _error(
        JournalErrorCodes.eventBatchPartialOverlap,
        'conflict',
        'The batch partially overlaps already-committed events.',
      ),
    );
  }

  factory EventStoreFailure.auditForbiddenField(String field) {
    return EventStoreFailure(
      _error(
        JournalErrorCodes.auditForbiddenField,
        'validation',
        'The audit payload contains a forbidden field.',
        {'field': field},
      ),
    );
  }

  factory EventStoreFailure.metricLabelForbidden(String label) {
    return EventStoreFailure(
      _error(
        JournalErrorCodes.metricLabelForbidden,
        'validation',
        'The metric label set is not closed.',
        {'label': label},
      ),
    );
  }

  factory EventStoreFailure.pageLimit(int limit) {
    return EventStoreFailure(
      _error(
        JournalErrorCodes.eventPageLimit,
        'budget',
        'The event page limit must be between 1 and 1000.',
        {'limit': limit},
      ),
    );
  }

  static ToolExecutionError _error(
    String code,
    String category,
    String title, [
    Map<String, Object>? context,
  ]) {
    return ToolExecutionError(
      code: code,
      category: category,
      retryable: false,
      title: title,
      context: context,
    );
  }
}

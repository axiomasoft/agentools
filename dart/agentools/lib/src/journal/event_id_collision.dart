import '../tool/tool_execution_error.dart';
import 'journal_error_codes.dart';
import 'lifecycle_exception.dart';

final class EventIdCollision extends LifecycleException {
  EventIdCollision(this.eventId)
    : error = ToolExecutionError(
        code: JournalErrorCodes.eventIdCollision,
        category: 'conflict',
        retryable: false,
        title: 'The event_id collides with a different content hash.',
        context: {'event_id': eventId},
      ),
      super('The event_id collides with a different content hash.');

  final String eventId;
  final ToolExecutionError error;
}

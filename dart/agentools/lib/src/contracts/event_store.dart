import '../persistence/append_result.dart';
import '../persistence/event_stream_page.dart';
import '../persistence/uncommitted_event.dart';

/// Storage-assigned atomic batch journal. Callers never supply seq or global_position.
abstract interface class EventStore {
  AppendResult append(
    String tenantId,
    String actionId,
    int expectedPrevSeq,
    List<UncommittedEvent> events,
  );

  EventStreamPage load(
    String tenantId,
    String actionId, {
    int afterSeq = 0,
    int limit = 500,
  });
}

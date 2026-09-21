import '../contracts/event_log.dart';
import '../journal/action_event.dart';
import '../journal/action_reducer.dart';
import '../journal/version_conflict.dart';

/// Process-local reference store. Production stores must enforce optimistic
/// concurrency atomically.
final class InMemoryEventLog implements EventLog {
  InMemoryEventLog({ActionReducer? reducer}) : _reducer = reducer;

  final ActionReducer? _reducer;
  final Map<String, List<ActionEvent>> _eventsByAction = {};
  final Map<String, Map<String, bool>> _eventIdsByAction = {};

  @override
  Future<void> append(ActionEvent event) async {
    final actionId = event.actionId;
    final known = _eventIdsByAction.putIfAbsent(actionId, () => {});
    if (known.containsKey(event.eventId)) {
      return;
    }

    final lastSeq = await this.lastSeq(actionId);
    if (event.expectedPrevSeq != lastSeq) {
      throw VersionConflict(expected: event.expectedPrevSeq, actual: lastSeq);
    }

    final events = [...?_eventsByAction[actionId], event];
    (_reducer ?? ActionReducer()).fold(events);

    _eventsByAction[actionId] = events;
    known[event.eventId] = true;
  }

  @override
  Future<List<ActionEvent>> readFrom(String actionId, int fromSeq) async {
    final events = _eventsByAction[actionId] ?? const [];
    return [
      for (final event in events)
        if (event.seq >= fromSeq) event,
    ];
  }

  @override
  Future<int> lastSeq(String actionId) async {
    final events = _eventsByAction[actionId];
    if (events == null || events.isEmpty) {
      return 0;
    }
    return events.last.seq;
  }
}

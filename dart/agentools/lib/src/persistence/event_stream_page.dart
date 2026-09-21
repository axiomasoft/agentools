import '../journal/action_event.dart';

final class EventStreamPage {
  EventStreamPage({
    required List<ActionEvent> events,
    required this.lastSeq,
    required this.hasMore,
  }) : events = List.unmodifiable(events),
       assert(lastSeq >= 0);

  final List<ActionEvent> events;
  final int lastSeq;
  final bool hasMore;
}

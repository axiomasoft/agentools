import 'action_event.dart';
import 'event_upcaster.dart';
import 'lifecycle_exception.dart';

/// Applies pure event migrations only while reading; records are never mutated.
final class EventUpcasterChain {
  EventUpcasterChain([List<EventUpcaster> upcasters = const []])
      : _upcasters = List.unmodifiable(upcasters);

  final List<EventUpcaster> _upcasters;

  ActionEvent upcast(ActionEvent event) {
    var current = event;
    while (true) {
      EventUpcaster? selected;
      for (final candidate in _upcasters) {
        if (candidate.supports(current)) {
          selected = candidate;
          break;
        }
      }
      if (selected == null) {
        return current;
      }
      final upcast = selected.upcast(current);
      if (upcast.eventVersion <= current.eventVersion) {
        throw LifecycleException(
          'An event upcaster must increase event_version.',
        );
      }
      current = upcast;
    }
  }
}

import 'action_event.dart';
import 'action_reducer.dart';
import 'action_snapshot.dart';
import 'folded_action.dart';

/// Append-only action journal. Its state is always derived by [ActionReducer].
final class ActionJournal {
  ActionJournal([List<ActionEvent> events = const []])
    : _events = List.unmodifiable(events);

  final List<ActionEvent> _events;

  List<ActionEvent> get events => _events;

  ActionJournal append(ActionEvent event, [ActionReducer? reducer]) {
    final nextEvents = [..._events, event];
    (reducer ?? ActionReducer(specRevision: nextEvents.first.specRevision))
        .fold(nextEvents);
    return ActionJournal(nextEvents);
  }

  FoldedAction state([ActionReducer? reducer]) {
    return (reducer ??
            ActionReducer(
              specRevision: _events.isEmpty
                  ? '2026-09'
                  : _events.first.specRevision,
            ))
        .fold(_events);
  }

  static FoldedAction restore(
    ActionSnapshot snapshot,
    List<ActionEvent> tail, [
    ActionReducer? reducer,
  ]) {
    final resolvedReducer =
        reducer ?? ActionReducer(specRevision: snapshot.specRevision);
    var state = snapshot.state;
    for (final event in tail) {
      state = resolvedReducer.apply(state, event);
    }
    return state;
  }
}

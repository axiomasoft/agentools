import 'action_journal.dart';
import 'action_reducer.dart';
import 'action_event.dart';
import 'folded_action.dart';

/// An optional projection; the journal remains the source of truth.
final class ActionSnapshot {
  ActionSnapshot({
    required this.state,
    required this.specRevision,
    this.contentHash = '',
  }) {
    if (state.actionId == null ||
        state.status == null ||
        state.seq < 1 ||
        specRevision.isEmpty) {
      throw ArgumentError('A snapshot requires a folded action state.');
    }
  }

  final FoldedAction state;
  final String specRevision;
  final String contentHash;

  bool matches(String expectedHash) {
    return contentHash.isNotEmpty && contentHash == expectedHash;
  }

  /// Mutated hash discards the snapshot. Events are never deleted.
  static FoldedAction restoreOrDiscard({
    required ActionSnapshot snapshot,
    required String expectedHash,
    required ActionJournal journal,
    List<ActionEvent> tail = const [],
    ActionReducer? reducer,
  }) {
    if (!snapshot.matches(expectedHash)) {
      return journal.state(reducer);
    }
    return ActionJournal.restore(snapshot, tail, reducer);
  }
}

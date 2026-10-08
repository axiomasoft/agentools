import '../schema/canonicalizer.dart';
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
    final events = journal.events;
    final seq = snapshot.state.seq;
    final checkpoint = seq <= events.length ? events[seq - 1] : null;
    final canonicalizer = Canonicalizer();
    final validCheckpoint =
        checkpoint != null &&
        checkpoint.seq == seq &&
        checkpoint.actionId == snapshot.state.actionId &&
        checkpoint.specRevision == snapshot.specRevision &&
        canonicalizer.canonicalize(
              events.skip(seq).map((e) => e.toMap()).toList(),
            ) ==
            canonicalizer.canonicalize(tail.map((e) => e.toMap()).toList());
    if (!snapshot.matches(expectedHash) || !validCheckpoint) {
      return journal.state(reducer);
    }
    return ActionJournal.restore(snapshot, tail, reducer);
  }
}

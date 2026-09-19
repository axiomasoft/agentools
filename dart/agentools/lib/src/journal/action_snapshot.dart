import 'folded_action.dart';

/// An optional projection; the journal remains the source of truth.
final class ActionSnapshot {
  ActionSnapshot({required this.state, required this.specRevision}) {
    if (state.actionId == null ||
        state.status == null ||
        state.seq < 1 ||
        specRevision.isEmpty) {
      throw ArgumentError('A snapshot requires a folded action state.');
    }
  }

  final FoldedAction state;
  final String specRevision;
}

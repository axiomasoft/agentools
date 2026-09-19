import '../contracts/clock.dart';
import '../journal/action_event.dart';
import '../journal/action_journal.dart';
import '../journal/action_reducer.dart';
import '../journal/actor.dart';

/// Appends journal events with optimistic sequence guards.
final class JournalAppender {
  JournalAppender({
    required ActionJournal journal,
    required this.actionId,
    required this.actor,
    required this.clock,
    this.epoch = 1,
    this.specRevision = '2026-08',
    ActionReducer? reducer,
  }) : _journal = journal,
       _reducer = reducer;

  final String actionId;
  final Actor actor;
  final Clock clock;
  final int epoch;
  final String specRevision;
  final ActionReducer? _reducer;

  ActionJournal _journal;

  ActionJournal journal() => _journal;

  JournalAppender append(
    String kind, {
    Map<String, dynamic> payload = const {},
    int? eventEpoch,
  }) {
    final state = _journal.state(_reducer);
    final seq = state.seq + 1;
    final event = ActionEvent(
      eventId: 'ev-$actionId-${seq.toString().padLeft(4, '0')}',
      actionId: actionId,
      seq: seq,
      expectedPrevSeq: state.seq,
      timestamp: _formatAtom(clock.now()),
      actor: actor,
      kind: kind,
      eventVersion: 1,
      specRevision: specRevision,
      payload: payload,
      epoch: eventEpoch ?? (_needsEpoch(kind) ? epoch : null),
    );
    _journal = _journal.append(event, _reducer);
    return this;
  }

  bool _needsEpoch(String kind) {
    return kind == 'effect_requested' ||
        kind == 'effect_confirmed' ||
        kind == 'effect_failed' ||
        kind == 'epoch_started';
  }

  static String _formatAtom(DateTime value) {
    final utc = value.toUtc();
    final year = utc.year.toString().padLeft(4, '0');
    final month = utc.month.toString().padLeft(2, '0');
    final day = utc.day.toString().padLeft(2, '0');
    final hour = utc.hour.toString().padLeft(2, '0');
    final minute = utc.minute.toString().padLeft(2, '0');
    final second = utc.second.toString().padLeft(2, '0');
    return '$year-$month-${day}T$hour:$minute:$second+00:00';
  }
}

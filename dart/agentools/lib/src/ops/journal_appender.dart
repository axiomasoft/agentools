import 'dart:math';

import '../contracts/audit_sink.dart';
import '../contracts/clock.dart';
import '../contracts/event_store.dart';
import '../journal/action_event.dart';
import '../journal/action_journal.dart';
import '../journal/action_reducer.dart';
import '../journal/actor.dart';
import '../persistence/event_store_failure.dart';
import '../persistence/uncommitted_event.dart';
import 'audit_redaction.dart';

/// Appends journal events through EventStore. Storage assigns seq.
final class JournalAppender {
  JournalAppender({
    required EventStore store,
    required this.tenantId,
    required this.actionId,
    required this.actor,
    required this.clock,
    this.epoch = 1,
    this.specRevision = '2026-09',
    ActionReducer? reducer,
    AuditSink? auditSink,
    AuditRedaction? redaction,
    ActionJournal? journal,
  }) : _store = store,
       _reducer = reducer,
       _auditSink = auditSink,
       _redaction = redaction ?? AuditRedaction(),
       _journal = journal ?? ActionJournal() {
    if (tenantId.isEmpty || actionId.isEmpty) {
      throw EventStoreFailure.streamIdentity();
    }
  }

  final EventStore _store;
  final String tenantId;
  final String actionId;
  final Actor actor;
  final Clock clock;
  final int epoch;
  final String specRevision;
  final ActionReducer? _reducer;
  final AuditSink? _auditSink;
  final AuditRedaction _redaction;
  ActionJournal _journal;

  ActionJournal journal() => _journal;

  EventStore store() => _store;

  static String newEventId() {
    final random = Random.secure();
    return List.generate(
      16,
      (_) => random.nextInt(256).toRadixString(16).padLeft(2, '0'),
    ).join();
  }

  JournalAppender append(
    String kind, {
    Map<String, dynamic> payload = const {},
    int? eventEpoch,
  }) {
    if (kind.startsWith('audit.')) {
      _redaction.assertPayload(payload);
    }
    final events = _journal.events;
    final expected = events.isEmpty ? 0 : events.last.seq;
    final uncommitted = UncommittedEvent(
      eventId: newEventId(),
      kind: kind,
      eventVersion: 1,
      specRevision: specRevision,
      actor: actor,
      timestamp: _formatAtom(clock.now()),
      payload: payload,
      epoch: eventEpoch ?? (_needsEpoch(kind) ? epoch : null),
    );
    final state = _journal.state(_reducer);
    final candidate = ActionEvent(
      eventId: uncommitted.eventId,
      actionId: actionId,
      seq: expected + 1,
      expectedPrevSeq: expected,
      timestamp: uncommitted.timestamp,
      actor: uncommitted.actor,
      kind: kind,
      eventVersion: uncommitted.eventVersion,
      specRevision: uncommitted.specRevision,
      payload: payload,
      epoch: uncommitted.epoch,
      previousHash: state.previousHash,
    );
    _journal.append(candidate, _reducer);
    _store.append(tenantId, actionId, expected, [uncommitted]);
    final page = _store.load(tenantId, actionId, afterSeq: expected, limit: 1);
    for (final committed in page.events) {
      _journal = _journal.append(committed, _reducer);
      _exportAudit(committed);
    }
    return this;
  }

  void _exportAudit(ActionEvent event) {
    if (!event.kind.startsWith('audit.') || _auditSink == null) {
      return;
    }
    try {
      _auditSink.export(_redaction.redactDocument(event.toMap()));
    } catch (_) {}
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

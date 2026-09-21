import '../contracts/audit_sink.dart';
import '../contracts/clock.dart';
import '../journal/action_event.dart';
import '../journal/actor.dart';
import '../schema/published_schema_validator.dart';
import 'audit_redaction.dart';
import 'journal_appender.dart';

/// Builds audit.* journal events and optionally exports them (AOB-1..AOB-16).
final class AuditEventFactory {
  AuditEventFactory({
    required this.clock,
    PublishedSchemaValidator? validator,
    AuditSink? auditSink,
    this.specRevision = '2026-08',
    AuditRedaction? redaction,
  }) : _validator = validator,
       _auditSink = auditSink,
       _redaction = redaction ?? AuditRedaction();

  final Clock clock;
  final PublishedSchemaValidator? _validator;
  final AuditSink? _auditSink;
  final AuditRedaction _redaction;
  final String specRevision;

  ActionEvent policyEvaluated({
    required String actionId,
    required int seq,
    required int expectedPrevSeq,
    required Actor actor,
    required String toolName,
    required String sideEffect,
    required String verdict,
  }) {
    return _build(
      actionId: actionId,
      seq: seq,
      expectedPrevSeq: expectedPrevSeq,
      actor: actor,
      kind: 'audit.policy_evaluated',
      payload: {
        'tool_name': toolName,
        'side_effect': sideEffect,
        'verdict': verdict,
      },
    );
  }

  ActionEvent costRecorded({
    required String actionId,
    required int seq,
    required int expectedPrevSeq,
    required Actor actor,
    required Map<String, Object> costPayload,
  }) {
    return _build(
      actionId: actionId,
      seq: seq,
      expectedPrevSeq: expectedPrevSeq,
      actor: actor,
      kind: 'audit.cost_recorded',
      payload: costPayload,
    );
  }

  ActionEvent limitExceeded({
    required String actionId,
    required int seq,
    required int expectedPrevSeq,
    required Actor actor,
    required String limitName,
    required int limitValue,
    required int observed,
  }) {
    return _build(
      actionId: actionId,
      seq: seq,
      expectedPrevSeq: expectedPrevSeq,
      actor: actor,
      kind: 'audit.limit_exceeded',
      payload: {
        'limit_name': limitName,
        'limit_value': limitValue,
        'observed': observed,
      },
    );
  }

  ActionEvent _build({
    required String actionId,
    required int seq,
    required int expectedPrevSeq,
    required Actor actor,
    required String kind,
    required Map<String, Object> payload,
  }) {
    _redaction.assertPayload(Map<String, dynamic>.from(payload));
    final event = ActionEvent(
      eventId: JournalAppender.newEventId(),
      actionId: actionId,
      seq: seq,
      expectedPrevSeq: expectedPrevSeq,
      timestamp: _formatAtom(clock.now()),
      actor: actor,
      kind: kind,
      eventVersion: 1,
      specRevision: specRevision,
      payload: Map<String, dynamic>.from(payload),
    );
    final document = _redaction.redactDocument(event.toMap());
    _validator?.assertValid(
      document,
      'https://agentools.dev/schema/2026-08/audit-event.json',
    );
    try {
      _auditSink?.export(document);
    } catch (_) {}
    return event;
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

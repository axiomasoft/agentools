import 'dart:io';

import 'package:agentools/agentools.dart';
import 'package:test/test.dart';

void main() {
  final schemaDirectory = _repoSchemaDirectory();

  // AOB-1, AOB-2, AOB-9; audit events live in the action journal with audit.* kinds.
  test('builds policy evaluated audit event', () {
    final exported = <Map<String, dynamic>>[];
    final sink = _RecordingAuditSink(exported);
    final factory = AuditEventFactory(
      clock: FixedClock(DateTime.parse('2026-08-05T12:00:00Z')),
      validator: PublishedSchemaValidator(schemaDirectory),
      auditSink: sink,
    );

    final event = factory.policyEvaluated(
      actionId: 'act-audit-1',
      seq: 2,
      expectedPrevSeq: 1,
      actor: Actor(type: 'agent', id: 'agent-1'),
      toolName: 'crm_report_list',
      sideEffect: 'read',
      verdict: 'allow',
    );

    expect(event.kind, 'audit.policy_evaluated');
    expect(event.payload['tool_name'], 'crm_report_list');
    expect(exported, hasLength(1));
  });

  // AOB-3; audit events must not drive lifecycle transitions.
  test('audit events do not change reducer projection', () {
    final actor = Actor(type: 'agent', id: 'a1');
    var journal = ActionJournal([
      ActionEvent(
        eventId: 'ev-1',
        actionId: 'act-1',
        seq: 1,
        expectedPrevSeq: 0,
        timestamp: '2026-08-05T10:00:00+00:00',
        actor: actor,
        kind: 'action_created',
        eventVersion: 1,
        specRevision: '2026-08',
        payload: const {},
      ),
    ]);
    journal = journal.append(
      ActionEvent(
        eventId: 'ev-2',
        actionId: 'act-1',
        seq: 2,
        expectedPrevSeq: 1,
        timestamp: '2026-08-05T10:00:01+00:00',
        actor: actor,
        kind: 'audit.policy_evaluated',
        eventVersion: 1,
        specRevision: '2026-08',
        payload: {
          'tool_name': 'demo',
          'side_effect': 'read',
          'verdict': 'allow',
        },
      ),
    );

    expect(journal.state().status, ActionStatus.created);
  });

  // AOB-10, AOB-15; cost audit stores integer minor units and currency.
  test('builds cost recorded audit event', () {
    final factory = AuditEventFactory(
      clock: FixedClock(DateTime.parse('2026-08-05T12:00:00Z')),
    );
    final payload = CostRecorder().costPayload(
      100,
      50,
      'USD',
      'pt-2026-08',
      {'input_minor': 2, 'output_minor': 3},
    );
    final event = factory.costRecorded(
      actionId: 'act-1',
      seq: 3,
      expectedPrevSeq: 2,
      actor: Actor(type: 'agent', id: 'a1'),
      costPayload: payload,
    );

    expect(event.kind, 'audit.cost_recorded');
    expect(event.payload['amount_minor'], 350);
    expect(event.payload['currency'], 'USD');
  });
}

String _repoSchemaDirectory() {
  final candidates = <String>[
    '../../spec/revisions/2026-08/schema',
    '../../../spec/revisions/2026-08/schema',
  ];
  if (Platform.script.isScheme('file')) {
    final testDir = File(Platform.script.toFilePath()).parent.path;
    candidates.insert(
      0,
      '$testDir/../../../../spec/revisions/2026-08/schema',
    );
  }
  for (final candidate in candidates) {
    if (Directory(candidate).existsSync()) {
      return candidate;
    }
  }
  throw StateError('Cannot locate published schema directory.');
}

final class _RecordingAuditSink implements AuditSink {
  _RecordingAuditSink(this.exported);

  final List<Map<String, dynamic>> exported;

  @override
  void export(Map<String, dynamic> auditEvent) {
    exported.add(auditEvent);
  }
}

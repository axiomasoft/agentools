import 'dart:io';

import 'package:agentools/agentools.dart';
import 'package:agentools/agentools_testkit.dart';
import 'package:test/test.dart';

void main() {
  test('journal appender assigns storage seq and CSPRNG event ids', () {
    final appender = JournalAppender(
      store: InMemoryEventStore(),
      tenantId: 'tenant-a',
      actionId: 'action-1',
      actor: Actor(type: 'user', id: 'u-1'),
      clock: FixedClock(DateTime.parse('2026-09-20T10:00:00Z')),
    );
    appender.append('action_created');
    final event = appender.journal().events.single;
    expect(event.seq, 1);
    expect(event.eventId, isNot(matches(RegExp(r'^ev-.+-\d+$'))));
    expect(event.eventId.length, 32);
  });

  test('fifty appenders yield one winner', () {
    final store = InMemoryEventStore();
    var winner = 0;
    var conflicts = 0;
    for (var i = 0; i < 50; i++) {
      final appender = JournalAppender(
        store: store,
        tenantId: 'tenant-a',
        actionId: 'action-1',
        actor: Actor(type: 'user', id: 'u-1'),
        clock: FixedClock(DateTime.parse('2026-09-20T10:00:00Z')),
      );
      try {
        appender.append('action_created');
        winner++;
      } on VersionConflict {
        conflicts++;
      }
    }
    expect(winner, 1);
    expect(conflicts, 49);
  });

  test('audit sink failure does not roll back append', () {
    final store = InMemoryEventStore();
    final appender = JournalAppender(
      store: store,
      tenantId: 'tenant-a',
      actionId: 'action-1',
      actor: Actor(type: 'user', id: 'u-1'),
      clock: FixedClock(DateTime.parse('2026-09-20T10:00:00Z')),
      auditSink: _ThrowingSink(),
    );
    appender.append(
      'audit.policy_evaluated',
      payload: {'tool_name': 'pay', 'side_effect': 'write', 'verdict': 'allow'},
    );
    expect(store.load('tenant-a', 'action-1').events, hasLength(1));
  });

  test('journal appender source does not mint seq-derived ids', () {
    final source = File('lib/src/ops/journal_appender.dart').readAsStringSync();
    expect(source, contains('EventStore'));
    expect(source, contains('newEventId'));
    expect(source, isNot(contains('ev-\$actionId-')));
  });
}

final class _ThrowingSink implements AuditSink {
  @override
  void export(Map<String, dynamic> auditEvent) {
    throw StateError('sink failed');
  }
}

import 'package:agentools/agentools.dart';
import 'package:agentools/agentools_testkit.dart';
import 'package:test/test.dart';

void main() {
  test('journal appender fifty appends one winner', () {
    final store = InMemoryEventStore();
    var winner = 0;
    var conflicts = 0;
    for (var i = 0; i < 50; i++) {
      final appender = JournalAppender(
        store: store,
        tenantId: 'tenant-gate',
        actionId: 'action-gate',
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

  test('snapshot hash mismatch discards snapshot not events', () {
    final store = InMemoryEventStore();
    final appender = JournalAppender(
      store: store,
      tenantId: 'tenant-a',
      actionId: 'action-1',
      actor: Actor(type: 'user', id: 'u-1'),
      clock: FixedClock(DateTime.parse('2026-09-20T10:00:00Z')),
    );
    appender.append('action_created');
    appender.append('action_started');
    final journal = appender.journal();
    final first = journal.events.first;
    final hash = store.contentHash(
      'tenant-a',
      'action-1',
      UncommittedEvent(
        eventId: first.eventId,
        kind: first.kind,
        eventVersion: 1,
        specRevision: '2026-08',
        actor: Actor(type: 'user', id: 'u-1'),
        timestamp: '2026-09-20T10:00:00+00:00',
        payload: const {},
      ),
    );
    final snapshot = ActionSnapshot(
      state: journal.state(),
      specRevision: '2026-08',
      contentHash: hash,
    );
    final mutated =
        '${hash.substring(0, hash.length - 1)}${hash.endsWith('a') ? 'b' : 'a'}';
    final restored = ActionSnapshot.restoreOrDiscard(
      snapshot: snapshot,
      expectedHash: mutated,
      journal: journal,
    );
    expect(restored.seq, journal.state().seq);
    expect(store.load('tenant-a', 'action-1').events, hasLength(2));
    expect(snapshot.matches(mutated), isFalse);
    expect(snapshot.matches(hash), isTrue);
  });
}

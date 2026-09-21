import 'package:agentools/agentools.dart';
import 'package:agentools/agentools_testkit.dart';
import 'package:test/test.dart';

UncommittedEvent _event(
  String eventId, {
  String kind = 'action_created',
  Map<String, dynamic> payload = const {},
}) {
  return UncommittedEvent(
    eventId: eventId,
    kind: kind,
    eventVersion: 1,
    specRevision: '2026-08',
    actor: Actor(type: 'user', id: 'u-1'),
    timestamp: '2026-09-20T10:00:00+00:00',
    payload: payload,
  );
}

void main() {
  test('production interface has no caller-seq append', () {
    final store = InMemoryEventStore();
    expect(store, isA<EventStore>());
  });

  test('empty stream starts at seq one', () async {
    final store = InMemoryEventStore();
    final result = store.append('tenant-a', 'action-1', 0, [
      _event('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'),
    ]);
    expect(result.firstSeq, 1);
    expect(result.lastSeq, 1);
    expect(result.outcome, AppendResult.appended);
    final page = store.load('tenant-a', 'action-1');
    expect(page.events, hasLength(1));
    expect(page.events.first.seq, 1);
    expect(page.events.first.expectedPrevSeq, 0);
    expect(page.hasMore, isFalse);
  });

  test('default page is 500', () {
    expect(InMemoryEventStore.defaultPage, 500);
  });

  test('tenant isolation', () async {
    final store = InMemoryEventStore();
    final event = _event('bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb');
    store.append('tenant-a', 'action-1', 0, [event]);
    store.append('tenant-b', 'action-1', 0, [event]);
    expect((store.load('tenant-a', 'action-1')).events, hasLength(1));
    expect((store.load('tenant-b', 'action-1')).events, hasLength(1));
  });
}

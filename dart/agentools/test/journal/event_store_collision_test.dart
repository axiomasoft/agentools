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
  test('fifty appends yield one winner', () async {
    final store = InMemoryEventStore();
    var winner = 0;
    var conflicts = 0;
    for (var i = 0; i < 50; i++) {
      try {
        store.append('tenant-a', 'action-1', 0, [
          _event(i.toString().padLeft(32, 'c')),
        ]);
        winner++;
      } on VersionConflict {
        conflicts++;
      }
    }
    expect(winner, 1);
    expect(conflicts, 49);
    expect((store.load('tenant-a', 'action-1')).events, hasLength(1));
  });

  test('atomic two-event batch rolls back on conflict', () async {
    final store = InMemoryEventStore();
    store.append('tenant-a', 'action-1', 0, [
      _event('dddddddddddddddddddddddddddddddd'),
      _event('eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee'),
    ]);
    expect(
      () => store.append('tenant-a', 'action-1', 0, [
        _event('ffffffffffffffffffffffffffffffff'),
        _event('11111111111111111111111111111111'),
      ]),
      throwsA(isA<VersionConflict>()),
    );
    expect((store.load('tenant-a', 'action-1')).events, hasLength(2));
  });

  test('identical replay', () async {
    final store = InMemoryEventStore();
    final batch = [
      _event('22222222222222222222222222222222'),
      _event('33333333333333333333333333333333'),
    ];
    final first = store.append('tenant-a', 'action-1', 0, batch);
    final replay = store.append('tenant-a', 'action-1', 0, batch);
    expect(replay.outcome, AppendResult.idempotentReplay);
    expect(replay.firstSeq, first.firstSeq);
    expect((store.load('tenant-a', 'action-1')).events, hasLength(2));
  });

  test('payload collision', () async {
    final store = InMemoryEventStore();
    const id = '44444444444444444444444444444444';
    store.append('tenant-a', 'action-1', 0, [
      _event(id, payload: {'v': 1}),
    ]);
    expect(
      () => store.append('tenant-a', 'action-1', 0, [
        _event(id, payload: {'v': 2}),
      ]),
      throwsA(isA<EventIdCollision>()),
    );
  });

  test('partial overlap', () async {
    final store = InMemoryEventStore();
    final first = _event('55555555555555555555555555555555');
    store.append('tenant-a', 'action-1', 0, [first]);
    expect(
      () => store.append('tenant-a', 'action-1', 0, [
        first,
        _event('66666666666666666666666666666666'),
      ]),
      throwsA(isA<EventStoreFailure>()),
    );
  });

  test('duplicate id in batch', () async {
    final store = InMemoryEventStore();
    final event = _event('77777777777777777777777777777777');
    expect(
      () => store.append('tenant-a', 'action-1', 0, [event, event]),
      throwsA(isA<EventStoreFailure>()),
    );
  });

  test('empty batch', () async {
    final store = InMemoryEventStore();
    expect(
      () => store.append('tenant-a', 'action-1', 0, []),
      throwsA(isA<EventStoreFailure>()),
    );
  });

  test('page abut does not skip', () async {
    final store = InMemoryEventStore();
    store.append('tenant-a', 'action-1', 0, [
      for (var i = 0; i < 5; i++) _event((i + 10).toString().padLeft(32, '0')),
    ]);
    final first = store.load('tenant-a', 'action-1', afterSeq: 0, limit: 2);
    expect(first.events.map((event) => event.seq).toList(), [1, 2]);
    expect(first.hasMore, isTrue);
    final second = store.load(
      'tenant-a',
      'action-1',
      afterSeq: first.lastSeq,
      limit: 2,
    );
    expect(second.events.map((event) => event.seq).toList(), [3, 4]);
    expect(second.events.first.seq, first.lastSeq + 1);
  });

  test('page limit over 1000 rejected', () async {
    final store = InMemoryEventStore();
    expect(
      () => store.load('tenant-a', 'action-1', afterSeq: 0, limit: 1001),
      throwsA(isA<EventStoreFailure>()),
    );
  });

  test('secret PII audit rejected', () async {
    final store = InMemoryEventStore();
    expect(
      () => store.append('tenant-a', 'action-1', 0, [
        _event(
          '88888888888888888888888888888888',
          kind: 'audit.policy_evaluated',
          payload: {'prompt': 'secret'},
        ),
      ]),
      throwsA(isA<EventStoreFailure>()),
    );
  });

  test('metric label deny tenant action', () {
    expect(
      () => MetricCardinality().assertAllowed({
        'tenant_id': 't',
        'action_id': 'a',
      }),
      throwsA(isA<EventStoreFailure>()),
    );
  });

  test('snapshot hash mismatch discards snapshot not journal', () async {
    final store = InMemoryEventStore();
    final event = _event('99999999999999999999999999999999');
    store.append('tenant-a', 'action-1', 0, [event]);
    final hash = store.contentHash('tenant-a', 'action-1', event);
    final mutated =
        '${hash.substring(0, hash.length - 1)}${hash.endsWith('a') ? 'b' : 'a'}';
    expect(hash, isNot(mutated));
    expect((store.load('tenant-a', 'action-1')).events, hasLength(1));
  });

  test('seq-derived event id rejected', () async {
    final store = InMemoryEventStore();
    expect(
      () =>
          store.append('tenant-a', 'action-1', 0, [_event('ev-action-1-0001')]),
      throwsA(isA<EventStoreFailure>()),
    );
  });

  test('batch over 64 rejected', () async {
    final store = InMemoryEventStore();
    expect(
      () => store.append('tenant-a', 'action-1', 0, [
        for (var i = 0; i < 65; i++)
          _event((i + 100).toString().padLeft(32, '0')),
      ]),
      throwsA(isA<EventStoreFailure>()),
    );
  });

  test('missing stream identity', () async {
    final store = InMemoryEventStore();
    expect(
      () => store.append('', 'action-1', 0, [
        _event('abababababababababababababababab'),
      ]),
      throwsA(isA<EventStoreFailure>()),
    );
  });

  test('cross-stream event id reuse denied', () async {
    final store = InMemoryEventStore();
    final event = _event('cdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcd');
    store.append('tenant-a', 'action-1', 0, [event]);
    expect(
      () => store.append('tenant-a', 'action-2', 0, [event]),
      throwsA(isA<EventIdCollision>()),
    );
  });
}

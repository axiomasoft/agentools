import 'dart:io';

import 'package:agentools/agentools.dart';
import 'package:test/test.dart';

void main() {
  ActionEvent event(
    int seq,
    int previous,
    String kind, {
    Map<String, dynamic> payload = const {},
    int? epoch,
  }) {
    return ActionEvent(
      eventId: 'event-$seq',
      actionId: 'action-1',
      seq: seq,
      expectedPrevSeq: previous,
      timestamp: '2026-08-03T09:14:22.118Z',
      actor: Actor(type: 'system', id: 'worker-1'),
      kind: kind,
      eventVersion: 1,
      specRevision: '2026-08',
      payload: payload,
      epoch: epoch,
    );
  }

  // ALC-21; append with a stale expected_prev_seq yields a typed version conflict.
  test('event log rejects wrong expected prev seq', () async {
    final log = InMemoryEventLog();
    await log.append(event(1, 0, 'action_created'));

    expect(
      () => log.append(event(2, 0, 'action_started')),
      throwsA(
        isA<VersionConflict>().having(
          (error) => error.error.context,
          'context',
          {'expected_prev_seq': 0, 'actual_prev_seq': 1},
        ),
      ),
    );
  });

  // ALC-7; re-appending the same event_id is idempotent.
  test('event log append is idempotent by event id', () async {
    final log = InMemoryEventLog();
    final created = event(1, 0, 'action_created');
    await log.append(created);
    await log.append(created);

    expect(await log.lastSeq('action-1'), 1);
    expect(await log.readFrom('action-1', 1), hasLength(1));
  });

  // ALC-32, ALC-34; the projection store is separate from the append-only log.
  test('session store persists projections', () async {
    final store = InMemorySessionStore();
    final projection = SessionStateProjection(
      actionId: 'action-1',
      status: ActionStatus.running,
      seq: 2,
      specRevision: '2026-08',
      epoch: 1,
      projectedAt: '2026-08-03T09:14:22.118Z',
    );

    await store.save(projection);

    expect(
      (await store.load('action-1'))?.toMap(),
      projection.toMap(),
    );
  });

  // ALC-17; timestamps for recorded input come from the injected clock, not fold logic.
  test('clock provides injectable now', () {
    final clock = FixedClock(DateTime.utc(2026, 8, 3, 9, 14, 22, 118));
    expect(clock.now().toIso8601String(), '2026-08-03T09:14:22.118Z');
    expect(clock, isA<Clock>());
  });

  // ALC-3, ALC-33; snapshot plus tail replay matches a full fold.
  test('snapshot projection matches folded state', () {
    final reducer = ActionReducer();
    final events = [
      event(1, 0, 'action_created'),
      event(2, 1, 'action_started'),
    ];
    final projection = SessionStateProjection.fromFoldedAction(
      reducer.fold(events),
      '2026-08',
      projectedAt: '2026-08-03T09:14:22.118Z',
    );

    expect(projection.status, ActionStatus.running);
    expect(projection.seq, 2);
  });

  // ALC-36; cross-language hash-chain anchor must match PHP byte-for-byte.
  test('hash chain matches PHP golden anchor', () {
    final chain = JournalHashChain(Canonicalizer());
    final reducer = ActionReducer(hashChain: chain);
    final first = event(1, 0, 'action_created');
    final firstHash = chain.hash(first);
    final second = event(2, 1, 'action_started').withPreviousHash(firstHash);
    final state = reducer.fold([first, second]);

    final phpScript = File('../../php/tests/Action/ActionReducerTest.php');
    expect(phpScript.existsSync(), isTrue);

    final phpOutput = Process.runSync('php', [
      '-r',
      r'''
require "vendor/autoload.php";
use AgenTools\Action\ActionEvent;
use AgenTools\Action\Actor;
use AgenTools\Action\ActionReducer;
use AgenTools\Action\JournalHashChain;
use AgenTools\Schema\Canonicalizer;

$chain = new JournalHashChain(new Canonicalizer());
$reducer = new ActionReducer(hashChain: $chain);
$first = new ActionEvent(
    "event-1",
    "action-1",
    1,
    0,
    "2026-08-03T09:14:22.118Z",
    new Actor("system", "worker-1"),
    "action_created",
    1,
    "2026-08",
    [],
    null,
);
$h1 = $chain->hash($first);
$second = (new ActionEvent(
    "event-2",
    "action-1",
    2,
    1,
    "2026-08-03T09:14:22.118Z",
    new Actor("system", "worker-1"),
    "action_started",
    1,
    "2026-08",
    [],
    null,
))->withPreviousHash($h1);
$state = $reducer->fold([$first, $second]);
echo $h1, "\n", $state->previousHash, "\n";
''',
    ], workingDirectory: '../../php');

    expect(phpOutput.exitCode, 0, reason: phpOutput.stderr.toString());
    final lines = phpOutput.stdout.toString().trim().split('\n');
    expect(lines, hasLength(2));
    // ignore: avoid_print
    print('php golden hash1: ${lines[0]}');
    // ignore: avoid_print
    print('dart actual hash1: $firstHash');
    // ignore: avoid_print
    print('php golden state hash: ${lines[1]}');
    // ignore: avoid_print
    print('dart actual state hash: ${state.previousHash}');

    expect(firstHash, lines[0]);
    expect(state.previousHash, lines[1]);
  });
}

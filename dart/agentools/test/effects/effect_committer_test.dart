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

  ActionJournal journal() {
    var current = ActionJournal();
    for (final item in [
      event(1, 0, 'action_created'),
      event(2, 1, 'epoch_started', epoch: 1),
      event(3, 2, 'action_started'),
    ]) {
      current = current.append(item);
    }
    return current;
  }

  // ALC-24, ALC-25; registry entry is written before product code can invoke an effect.
  test('records the effect before calling the external port', () {
    final applier = _RecordingApplier();
    final updated = EffectCommitter().commit(
      journal(),
      event(
        4,
        3,
        'effect_requested',
        payload: {'effect_id': 'payment-1'},
        epoch: 1,
      ),
      applier,
    );

    expect(applier.received?.status, EffectStatus.requested);
    expect(
      updated.state().effects['payment-1']?.status,
      EffectStatus.requested,
    );
  });

  // ALC-25; a confirmed effect is never sent to the external port twice.
  test('skips an already confirmed effect', () {
    var current = journal();
    for (final item in [
      event(
        4,
        3,
        'effect_requested',
        payload: {'effect_id': 'payment-1'},
        epoch: 1,
      ),
      event(
        5,
        4,
        'effect_confirmed',
        payload: {'effect_id': 'payment-1'},
        epoch: 1,
      ),
    ]) {
      current = current.append(item);
    }

    final applier = _CountingApplier();
    final result = EffectCommitter().commit(
      current,
      event(
        6,
        5,
        'effect_requested',
        payload: {'effect_id': 'payment-1'},
        epoch: 1,
      ),
      applier,
    );

    expect(applier.calls, 0);
    expect(result.events, current.events);
  });

  // ALC-26, ALC-27; after failover an executor from the old epoch is rejected.
  test('rejects a zombie executor epoch', () {
    expect(
      () => ActionReducer(specRevision: '2026-08').fold([
        event(1, 0, 'action_created'),
        event(2, 1, 'epoch_started', epoch: 1),
        event(3, 2, 'action_started'),
        event(4, 3, 'epoch_started', epoch: 2),
        event(
          5,
          4,
          'effect_requested',
          payload: {'effect_id': 'payment-1'},
          epoch: 1,
        ),
      ]),
      throwsA(isA<StaleExecutorEpoch>()),
    );
  });
}

final class _RecordingApplier implements EffectApplier {
  EffectRecord? received;

  @override
  void apply(EffectRecord effect) {
    received = effect;
  }
}

final class _CountingApplier implements EffectApplier {
  var calls = 0;

  @override
  void apply(EffectRecord effect) {
    calls++;
  }
}

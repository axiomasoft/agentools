import 'dart:convert';

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

  String serializeFoldedState(FoldedAction state) {
    final effects = <String, Map<String, dynamic>>{};
    final effectKeys = state.effects.keys.toList()..sort();
    for (final effectId in effectKeys) {
      final effect = state.effects[effectId]!;
      effects[effectId] = {
        'effect_id': effect.effectId,
        'status': effect.status.value,
        'epoch': effect.epoch,
        'requested_seq': effect.requestedSeq,
        'tool_name': effect.toolName,
        'idempotency_key': effect.idempotencyKey,
        'external_ref': effect.externalRef,
      };
    }

    return jsonEncode({
      'action_id': state.actionId,
      'status': state.status?.value,
      'seq': state.seq,
      'epoch': state.epoch,
      'inputs': state.inputs,
      'effects': effects,
      'cancellation_requested': state.cancellationRequested,
      'previous_hash': state.previousHash,
    });
  }

  test('folds lifecycle from journal only', () {
    var journal = ActionJournal();
    for (final item in [
      event(1, 0, 'action_created'),
      event(2, 1, 'epoch_started', epoch: 1),
      event(3, 2, 'action_started'),
      event(
        4,
        3,
        'effect_requested',
        payload: {
          'effect_id': 'payment-1',
          'tool_name': 'payment_send',
        },
        epoch: 1,
      ),
      event(
        5,
        4,
        'effect_confirmed',
        payload: {
          'effect_id': 'payment-1',
          'external_ref': 'pay-9',
        },
        epoch: 1,
      ),
      event(6, 5, 'action_completed'),
    ]) {
      journal = journal.append(item);
    }

    final state = journal.state();
    expect(state.status, ActionStatus.completed);
    expect(state.seq, 6);
    expect(state.effects['payment-1']?.externalRef, 'pay-9');
    expect(state.status?.isTerminal(), isTrue);
  });

  test('preserves unknown events without changing state', () {
    final reducer = ActionReducer();
    final state = reducer.fold([
      event(1, 0, 'action_created'),
      event(
        2,
        1,
        'vendor.future_event',
        payload: {'future_member': {'deep': true}},
      ),
    ]);

    expect(state.status, ActionStatus.created);
    expect(state.seq, 2);
    expect(
      event(
        2,
        1,
        'vendor.future_event',
        payload: {'future_member': {'deep': true}},
      ).toMap()['payload'],
      {'future_member': {'deep': true}},
    );
  });

  // ALC-21; a stale expected_prev_seq is a typed version conflict, not a transition error.
  test('guard order reports version conflict before invalid transition', () {
    expect(
      () => ActionReducer().fold([
        event(1, 0, 'action_created'),
        event(2, 0, 'action_completed'),
      ]),
      throwsA(
        isA<VersionConflict>().having(
          (error) => error.error.code,
          'code',
          JournalErrorCodes.versionConflict,
        ),
      ),
    );
  });

  // ALC-31; recorded events are immutable value objects, not mutable envelopes.
  test('recorded events cannot be mutated in place', () {
    final original = event(1, 0, 'action_created');
    final updated = original.withPreviousHash(
      'sha-256:${'a' * 64}',
    );

    expect(original.previousHash, isNull);
    expect(updated.previousHash, isNotNull);
    expect(identical(original, updated), isFalse);
  });

  // ALC-3; folding the same journal twice yields a byte-identical projection payload.
  test('fold is deterministic across runs', () {
    final events = [
      event(1, 0, 'action_created'),
      event(2, 1, 'epoch_started', epoch: 1),
      event(3, 2, 'action_started'),
      event(
        4,
        3,
        'input_recorded',
        payload: {'name': 'prompt', 'value': 'hello'},
      ),
      event(5, 4, 'action_completed'),
    ];
    final reducer = ActionReducer();

    final first = serializeFoldedState(reducer.fold(events));
    final second = serializeFoldedState(reducer.fold(events));

    expect(first, second);
  });

  test('rejects event after terminal action', () {
    expect(
      () => ActionReducer().fold([
        event(1, 0, 'action_created'),
        event(2, 1, 'action_started'),
        event(3, 2, 'action_completed'),
        event(
          4,
          3,
          'input_recorded',
          payload: {'name': 'now', 'value': 'later'},
        ),
      ]),
      throwsA(isA<LifecycleException>()),
    );
  });

  // ALC-30, ALC-31; the upcast is pure and leaves the recorded event untouched.
  test('upcasts only while reading the journal', () {
    final recorded = event(1, 0, 'action_created');
    final upcaster = _TestUpcaster();
    final state = ActionReducer(
      upcasters: EventUpcasterChain([upcaster]),
    ).fold([recorded]);

    expect(state.status, ActionStatus.created);
    expect(recorded.eventVersion, 1);
  });

  // ALC-35, ALC-36, ALC-37; a changed record breaks the separate integrity failure.
  test('rejects a tampered hash chain', () {
    final chain = JournalHashChain(Canonicalizer());
    final reducer = ActionReducer(hashChain: chain);
    final first = event(1, 0, 'action_created');
    final state = reducer.fold([first]);
    final second = event(2, 1, 'action_started').withPreviousHash(
      state.previousHash,
    );
    expect(
      reducer.fold([first, second]).status,
      ActionStatus.running,
    );

    expect(
      () => reducer.fold([
        first,
        event(2, 1, 'action_started').withPreviousHash(
          'sha-256:${'0' * 64}',
        ),
      ]),
      throwsA(isA<JournalIntegrityException>()),
    );
  });

  // ALC-32, ALC-33; projection plus journal tail restores the same folded state.
  test('restores from snapshot and journal tail', () {
    final reducer = ActionReducer();
    final firstTwo = [
      event(1, 0, 'action_created'),
      event(2, 1, 'action_started'),
    ];
    final snapshot = ActionSnapshot(
      state: reducer.fold(firstTwo),
      specRevision: '2026-08',
    );
    final tail = [event(3, 2, 'action_completed')];

    expect(
      reducer.fold([...firstTwo, ...tail]).status,
      ActionJournal.restore(snapshot, tail, reducer).status,
    );
  });
}

final class _TestUpcaster implements EventUpcaster {
  @override
  bool supports(ActionEvent event) {
    return event.kind == 'action_created' && event.eventVersion == 1;
  }

  @override
  ActionEvent upcast(ActionEvent event) {
    return ActionEvent(
      eventId: event.eventId,
      actionId: event.actionId,
      seq: event.seq,
      expectedPrevSeq: event.expectedPrevSeq,
      timestamp: event.timestamp,
      actor: event.actor,
      kind: event.kind,
      eventVersion: 2,
      specRevision: event.specRevision,
      payload: event.payload,
      epoch: event.epoch,
      previousHash: event.previousHash,
    );
  }
}

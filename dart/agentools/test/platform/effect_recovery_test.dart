import 'dart:convert';
import 'dart:io';
import 'package:agentools/agentools.dart';
import 'package:test/test.dart';

void main() {
  final fixture =
      jsonDecode(
            File(
              '../../fixtures/platform/v1/effects/outcomes.json',
            ).readAsStringSync(),
          )
          as Map<String, dynamic>;
  final cases = fixture['cases'] as List<dynamic>;
  if (cases.map((e) => e['id']).join(',') !=
      'effect.pending,effect.unknown,effect.success,effect.failure,effect.takeover') {
    throw StateError('Required effect regression cases changed');
  }
  for (final raw in cases) {
    final item = raw as Map<String, dynamic>;
    test('dart.${item['id']}', () {
      final reducer = ActionReducer(
        hashChain: JournalHashChain(Canonicalizer()),
      );
      var state = FoldedAction();
      for (final rawEvent in item['events'] as List<dynamic>) {
        final event = rawEvent as Map<String, dynamic>;
        state = reducer.apply(
          state,
          ActionEvent(
            eventId: 'event-${state.seq + 1}',
            actionId: 'dated-action',
            seq: state.seq + 1,
            expectedPrevSeq: state.seq,
            timestamp: '2026-11-01T00:00:00Z',
            actor: Actor(type: 'host', id: 'owner'),
            kind: event['kind'] as String,
            eventVersion: 1,
            specRevision: '2026-09',
            payload: event['payload'] as Map<String, dynamic>? ?? {},
            epoch: event['epoch'] as int?,
            previousHash: state.previousHash,
          ),
        );
      }
      expect(state.status?.value, item['status']);
      final outcome = switch (state.status?.value) {
        'completed' => 'succeeded',
        'failed' => 'failed',
        'applying' || 'reconciliation_required' => 'unknown',
        _ => null,
      };
      expect(outcome, item['outcome']);
      expect(state.effects.length, 1);
    });
  }
}

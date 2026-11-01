import 'dart:convert';
import 'dart:io';
import 'package:agentools/agentools.dart';
import 'package:test/test.dart';

// The dated SDK consumes the same cases as the additive PHP facade.
void main() {
  final fixture =
      jsonDecode(
            File(
              '../../fixtures/platform/v1/actions/outcomes.json',
            ).readAsStringSync(),
          )
          as Map<String, dynamic>;
  final cases = fixture['cases'] as List<dynamic>;
  if (cases
          .map((item) => (item as Map<String, dynamic>)['id'])
          .toList()
          .join(',') !=
      'action.accepted-pending,action.known-success,action.known-failure,action.unknown-is-not-failed') {
    throw StateError('Required shared action case set changed');
  }
  for (final rawCase in cases) {
    final item = rawCase as Map<String, dynamic>;
    test('dart.${item['id']}', () {
      final reducer = ActionReducer(
        hashChain: JournalHashChain(Canonicalizer()),
      );
      var state = FoldedAction();
      for (final raw in item['events'] as List<dynamic>) {
        final event = raw as Map<String, dynamic>;
        state = reducer.apply(
          state,
          ActionEvent(
            eventId: 'event-${state.seq + 1}',
            actionId: 'dated-action',
            seq: state.seq + 1,
            expectedPrevSeq: state.seq,
            timestamp: '2026-10-31T12:00:00Z',
            actor: Actor(type: 'host', id: 'owner-1'),
            kind: event['kind'] as String,
            eventVersion: 1,
            specRevision: '2026-09',
            payload: event['payload'] as Map<String, dynamic>? ?? {},
            epoch: event['epoch'] as int?,
            previousHash: state.previousHash,
          ),
        );
      }
      final status = state.status?.value;
      final outcome = switch (status) {
        'completed' => 'succeeded',
        'failed' => 'failed',
        'applying' || 'reconciliation_required' => 'unknown',
        _ => null,
      };
      expect(status, item['status']);
      expect(outcome, item['outcome']);
    });
  }
}

import 'package:agentools/agentools.dart';
import 'package:test/test.dart';

void main() {
  // CLR-6, CLR-10, CLR-11, CLR-14, CLR-15: MRTR preserves opaque state and addressable rounds.
  test('records a request and a reply with a different call id', () {
    final session = ClarificationSession();
    session.request('rpc-1', 'opaque-state', {
      'counterparty_id': {'mode': 'form'},
    });
    final reply = session.answer('rpc-2', {'counterparty_id': 'cp-3391'});

    expect(reply['request_state'], 'opaque-state');
    expect(session.rounds(), hasLength(2));
  });

  // CLR-11: answers may not invent request keys.
  test('rejects an unrequested answer', () {
    final session = ClarificationSession();
    session.request('rpc-1', 'opaque-state', {
      'counterparty_id': {'mode': 'form'},
    });

    expect(
      () => session.answer('rpc-2', {'unexpected': 'value'}),
      throwsArgumentError,
    );
  });

  // CLR-12, CLR-14, CLR-15: multi-round clarification stays in one logical call cycle.
  test('distinguishes multi-round MRTR from an independent retry', () {
    final session = ClarificationSession();
    session.request('rpc-1', 'opaque-state-1', {
      'counterparty_id': {'mode': 'form'},
    });
    final firstReply = session.answer('rpc-2', {'counterparty_id': 'cp-1'});
    expect(firstReply['round'], 1);
    expect(session.hasPendingRequest, isFalse);

    session.request('rpc-3', 'opaque-state-2', {
      'purpose': {'mode': 'form'},
    });
    final secondReply = session.answer('rpc-4', {'purpose': 'advance'});
    expect(secondReply['round'], 3);
    expect(session.rounds(), hasLength(4));
    expect(session.rounds()[0]['direction'], 'request');
    expect(session.rounds()[1]['direction'], 'response');
    expect(session.rounds()[2]['direction'], 'request');
    expect(session.rounds()[3]['direction'], 'response');

    final independentRetry = ClarificationSession();
    independentRetry.request('rpc-10', 'fresh-state', {
      'counterparty_id': {'mode': 'form'},
      'purpose': {'mode': 'form'},
    });
    expect(independentRetry.rounds(), hasLength(1));
    expect(independentRetry.rounds().first['request_state'], 'fresh-state');
    // ignore: avoid_print
    print('multi-round rounds=${session.rounds().length} independent rounds=1');
  });

  // CLR-13: the caller may abandon MRTR without answering.
  test('allows abandoning MRTR without treating it as action failure', () {
    final session = ClarificationSession();
    session.request('rpc-1', 'opaque-state', {
      'counterparty_id': {'mode': 'form'},
    });

    expect(session.hasPendingRequest, isTrue);
    expect(session.rounds(), hasLength(1));
    expect(
      () => session.answer('rpc-2', {'counterparty_id': 'cp-3391'}),
      returnsNormally,
    );
    expect(session.hasPendingRequest, isFalse);
  });
}

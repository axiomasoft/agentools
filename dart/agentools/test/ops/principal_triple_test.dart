import 'package:agentools/agentools.dart';
import 'package:test/test.dart';

void main() {
  // SEC-13; merged user and agent principals are rejected.
  test('rejects merged user and agent principals', () {
    final failure = PrincipalTriple().validate(
      {
        'initiator': {'id': 'user-7'},
        'agent': {'id': 'user-7'},
        'authorizer': {'id': 'approver-7'},
      },
      {
        'resource': 'report-7',
        'tool': 'demo_probe',
        'permissions': ['demo.read'],
      },
    );

    expect(failure, 'merged_user_and_agent');
  });

  // SEC-14, SEC-16; valid triple is carried by actor plus payload scope fields.
  test('builds distinct principal triple', () {
    final record = PrincipalTriple().buildInvocationRecord(
      {
        'initiator': {'id': 'user-7'},
        'agent': {'id': 'agent-7'},
        'authorizer': {'id': 'approver-7'},
      },
      {
        'resource': 'report-7',
        'tool': 'demo_probe',
        'permissions': ['demo.read'],
      },
    );

    expect(record.actor.type, 'agent');
    expect(record.actor.id, 'agent-7');
    expect(record.payload['end_user_id'], 'user-7');
    expect(record.payload['authorization_scope'], 'report-7');
  });

  // SEC-15; runtime accounts must not stand in for the end user.
  test('rejects runtime account as end user', () {
    final failure = PrincipalTriple().validate(
      {
        'initiator': {'id': 'runtime-worker-1'},
        'agent': {'id': 'agent-7'},
        'authorizer': {'id': 'approver-7'},
      },
      {
        'resource': 'report-7',
        'tool': 'demo_probe',
        'permissions': ['demo.read'],
      },
    );

    expect(failure, 'runtime_account_as_end_user');
  });

  // SEC-14; agent principal must use actor.type = agent.
  test('requires agent actor carrier', () {
    expect(
      () =>
          PrincipalTriple().assertAgentActor(Actor(type: 'user', id: 'user-7')),
      throwsArgumentError,
    );
  });
}

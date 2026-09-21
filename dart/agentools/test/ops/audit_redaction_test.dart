import 'package:agentools/agentools.dart';
import 'package:test/test.dart';

void main() {
  test('forbidden token field is denied', () {
    expect(
      () => AuditRedaction().assertPayload({'token': 'secret'}),
      throwsA(isA<EventStoreFailure>()),
    );
  });

  test('allowed audit fields pass', () {
    AuditRedaction().assertPayload({
      'tool_name': 'pay',
      'side_effect': 'write',
      'verdict': 'allow',
    });
    final document = AuditRedaction().redactDocument({
      'kind': 'audit.policy_evaluated',
      'payload': {
        'tool_name': 'pay',
        'side_effect': 'write',
        'verdict': 'allow',
      },
    });
    expect(document['payload']['tool_name'], 'pay');
  });
}

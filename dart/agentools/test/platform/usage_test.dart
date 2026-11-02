import 'package:agentools/platform_usage.dart';
import 'package:test/test.dart';
import 'usage_contract.dart';

void main() {
  for (final id in portableIds()) {
    test(id, () {
      expect(portableCase(id), isNotEmpty);
    });
  }
  test('invalid quantities overlap and exact decimal', () {
    for (final raw in <Map<String, Object?>>[
      {'input_total': 100, 'cached_subset': 101},
      {'input_total': 100, 'cached_subset': 50, 'cache_write': 51},
      {'output': 1, 'reasoning': 2},
      {'output': -1},
      {'output': 1.2},
      {'output': '1'},
      {'audio_input': 1.25, 'audio_unit': 'second'},
      {'audio_input': '1.250'},
      {'audio_input': '1\n', 'audio_unit': 'second'},
      {
        'amount': {'amount': 0.0021, 'currency': 'XXX'},
      },
    ]) {
      expect(() => event(raw), throwsArgumentError);
    }
  });
  test('immutable event and correction binding', () {
    final a = event({'output': 12});
    final document = a.toJson();
    object(document['units'])['output_tokens'] = 99;
    expect(a.units['output_tokens'], 12);
    final changed = event(
      {'output': 13},
      {
        'business_operation_ref': 'other',
        'event_id': 'v2',
        'supersedes_event_id': a.eventId,
      },
      'inv-1',
      2,
    );
    expect(() => UsageFold.fold([a, changed]), throwsA(isA<UsageConflict>()));
    final orphan = event(
      {},
      {'event_id': 'orphan', 'supersedes_event_id': 'absent'},
      'inv-1',
      2,
    );
    expect(() => UsageFold.fold([orphan]), throwsArgumentError);
  });
  test('admission fails closed and expires at microsecond boundary', () {
    final data = fixture('admission-result');
    final a = UsageAdmissionResult.fromJson(data);
    expect(a.permits('2026-11-02T11:00:00Z'), isFalse);
    expect(a.permits('2026-11-02T11:00:00.000001Z'), isFalse);
    for (final change in <Map<String, Object?>>[
      {'status': 'unavailable', 'reason': null},
      {'expires_at': null},
      {
        'limits': {'output': -1},
      },
    ]) {
      expect(
        () => UsageAdmissionResult.fromJson({...data, ...change}),
        throwsArgumentError,
      );
    }
  });
}

import 'package:agentools/agentools.dart';
import 'package:test/test.dart';

void main() {
  // AOB-23, AOB-24; cost is computed from token usage and a versioned price table.
  test('calculates cost from token usage', () {
    final recorder = CostRecorder();
    final amount = recorder.calculateAmountMinor(
      100,
      50,
      {'input_minor': 2, 'output_minor': 3},
    );

    expect(amount, 350);
  });

  // AOB-20, AOB-21, AOB-22; long gates end the prepare span and resume via link.
  test('trace correlation ends prepare span and links commit', () {
    final trace = TraceCorrelation(
      traceId: '0123456789abcdef0123456789abcdef',
      spanId: '0123456789abcdef',
      linkedTraceId: 'fedcba9876543210fedcba9876543210',
      linkedSpanId: 'fedcba9876543210',
    );

    final requested = trace.confirmationRequestedPayload();
    final commit = trace.commitResumePayload();

    expect(requested['trace'], isA<Map<String, dynamic>>());
    expect(
      (requested['trace'] as Map<String, dynamic>)['trace_id'],
      '0123456789abcdef0123456789abcdef',
    );
    expect(commit['trace'], isA<Map<String, dynamic>>());
    expect(
      (commit['trace'] as Map<String, dynamic>).containsKey('link'),
      isTrue,
    );
  });
}

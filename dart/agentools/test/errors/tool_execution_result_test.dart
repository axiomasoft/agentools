import 'package:agentools/agentools.dart';
import 'package:test/test.dart';

void main() {
  // IDE-23; a business failure is a structured tool result, not a protocol exception.
  test('returns business failure as a tool result', () {
    final error = ToolExecutionError(
      code: 'billing.validation.period_closed',
      category: ErrorCategory.validation,
      retryable: false,
      title: 'Период закрыт',
    );
    final result = ToolExecutionResult.failure(error);

    expect(result.isError, isTrue);
    expect(result.error, same(error));
    // ignore: avoid_print
    print('business failure channel: tool-result error=${result.error!.code}');
  });

  // IDE-23, IDE-24; protocol failures retain a separate exception channel.
  test('protocol failure is not a tool result', () {
    expect(
      () => throw ProtocolException('Malformed transport envelope.'),
      throwsA(isA<ProtocolException>()),
    );
    // ignore: avoid_print
    print('protocol failure channel: ProtocolException');
  });
}

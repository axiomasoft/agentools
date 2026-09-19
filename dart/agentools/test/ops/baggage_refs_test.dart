import 'package:agentools/agentools.dart';
import 'package:test/test.dart';

void main() {
  // AOB-29; baggage carries only opaque identifiers.
  test('accepts opaque identifiers', () {
    final baggage = BaggageRefs().add('act-1').add('tenant-42');

    expect(baggage.refs(), ['act-1', 'tenant-42']);
  });

  // AOB-30; substantive payloads are rejected.
  test('rejects non opaque values', () {
    expect(
      () => BaggageRefs().add('user name with spaces'),
      throwsArgumentError,
    );
  });

  // AOB-31; exceeding baggage limits yields a typed budget error.
  test('rejects baggage over item limit', () {
    var baggage = BaggageRefs();
    for (var i = 0; i < 64; i++) {
      baggage = baggage.add('id-$i');
    }

    expect(
      () => baggage.add('id-overflow'),
      throwsA(
        isA<BaggageLimitException>().having(
          (error) => error.result.error?.code,
          'code',
          OpsErrorCodes.baggageLimitExceeded,
        ),
      ),
    );
  });
}

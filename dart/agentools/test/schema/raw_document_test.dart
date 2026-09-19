import 'package:agentools/agentools.dart';
import 'package:test/test.dart';

void main() {
  group('RawDocument', () {
    test('round-trips unknown members byte for byte', () {
      const json = '''
{
  "known": "value",
  "future_extension": {"nested": true}
}''';

      expect(RawDocument.fromJson(json).toJson(), json);
    });
  });
}

import 'dart:convert';
import 'dart:io';

import 'package:agentools/agentools.dart';
import 'package:test/test.dart';

void main() {
  final repo = Directory.current.uri.resolve('../../').toFilePath();
  final casesFile = File(
    '${repo}fixtures/hardening/adversarial/corpus/v1/cases.jsonl',
  );

  test('oracle and typed JCS cases are idempotent', () {
    expect(casesFile.existsSync(), isTrue);
    final canonicalizer = Canonicalizer();
    for (final line in casesFile.readAsLinesSync()) {
      if (line.trim().isEmpty) {
        continue;
      }
      final caseJson = jsonDecode(line) as Map<String, dynamic>;
      final request = caseJson['request'] as Map<String, dynamic>;
      final expectClass =
          (caseJson['expect'] as Map<String, dynamic>)['class'] as String;
      if (request['op'] != 'jcs' || expectClass != 'accept') {
        continue;
      }
      final bytes = canonicalizer.canonicalizeJson(request['json'] as String);
      expect(canonicalizer.canonicalizeJson(bytes), bytes);
    }
  });

  test('depth limit plus one is red', () {
    expect(
      () => ExpressionEvaluator().evaluate('${'(' * 33}true${')' * 33}', {}),
      throwsA(
        isA<ExpressionException>().having(
          (error) => error.limitName,
          'limitName',
          'expression.max_depth',
        ),
      ),
    );
  });

  test('baggage limit plus one is red', () {
    var baggage = BaggageRefs();
    for (var i = 0; i < 64; i++) {
      baggage = baggage.add('id-$i');
    }
    expect(
      () => baggage.add('id-64'),
      throwsA(isA<BaggageLimitException>()),
    );
  });
}

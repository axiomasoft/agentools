import 'dart:io';

import 'package:agentools/agentools.dart';
import 'package:test/test.dart';

void main() {
  final oracleRoot = Directory(
    '${Directory.current.path}/../../conformance/oracles/jcs',
  );
  final canonicalizer = Canonicalizer();

  test('independent oracle vectors match bytes', () {
    final inputDir = Directory('${oracleRoot.path}/input');
    final inputs =
        inputDir
            .listSync()
            .whereType<File>()
            .where((file) => file.path.endsWith('.json'))
            .toList()
          ..sort((a, b) => a.path.compareTo(b.path));

    expect(inputs, isNotEmpty);
    for (final inputFile in inputs) {
      final name = inputFile.uri.pathSegments.last.replaceFirst('.json', '');
      final expectedFile = File('${oracleRoot.path}/output/$name.json');
      expect(expectedFile.existsSync(), isTrue, reason: name);
      final expected = expectedFile.readAsStringSync().replaceAll('\n', '');
      final actual = canonicalizer.canonicalizeJson(
        inputFile.readAsStringSync(),
      );
      expect(actual, expected, reason: name);
    }
  });
}

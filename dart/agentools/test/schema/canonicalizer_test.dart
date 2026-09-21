import 'dart:io';
import 'package:agentools/agentools.dart';
import 'package:test/test.dart';

void main() {
  final canonicalizer = Canonicalizer();
  final vectorDir = Directory('test/schema/data/rfc8785');

  group('Canonicalizer', () {
    test('runs official RFC 8785 vectors', () {
      final inputs =
          vectorDir
              .listSync()
              .whereType<File>()
              .where((file) => file.path.endsWith('.input.json'))
              .toList()
            ..sort((a, b) => a.path.compareTo(b.path));

      expect(inputs, isNotEmpty);
      for (final inputFile in inputs) {
        final baseName = inputFile.uri.pathSegments.last.replaceFirst(
          '.input.json',
          '',
        );
        final goldenFile = File('${vectorDir.path}/$baseName.golden.txt');
        final input = inputFile.readAsStringSync();
        final expected = goldenFile.readAsStringSync().replaceAll('\n', '');
        final actual = canonicalizer.canonicalizeJson(input);
        expect(actual, expected, reason: baseName);
      }
      // Printed for Completion Notes evidence.
      // ignore: avoid_print
      print('RFC 8785 vectors run: ${inputs.length}');
    });

    test('matches PHP golden output for BMP keys', () {
      final input = File(
        'test/schema/data/bmp-weird.input.json',
      ).readAsStringSync();
      final expected = File(
        'test/schema/data/bmp-weird.golden.txt',
      ).readAsStringSync().replaceAll('\n', '');
      expect(canonicalizer.canonicalizeJson(input), expected);
    });

    test('canonicalizes object keys in JCS order', () {
      final result = canonicalizer.canonicalize({
        '€': 'Euro Sign',
        '1': 'One',
        '😀': 'Grinning Face',
      });
      expect(result, '{"1":"One","€":"Euro Sign","😀":"Grinning Face"}');
    });

    test('rejects NaN', () {
      expect(
        () => canonicalizer.canonicalize({'value': double.nan}),
        throwsA(
          isA<CanonicalizationException>().having(
            (error) => error.message,
            'message',
            contains('NaN'),
          ),
        ),
      );
    });

    test('rejects Infinity', () {
      expect(
        () => canonicalizer.canonicalize({'value': double.infinity}),
        throwsA(isA<CanonicalizationException>()),
      );
    });

    test('rejects duplicate object names before decoding', () {
      expect(
        () => canonicalizer.canonicalizeJson('{"key":1,"key":2}'),
        throwsA(
          isA<CanonicalizationException>().having(
            (error) => error.message,
            'message',
            contains('duplicate member names'),
          ),
        ),
      );
    });

    test('preserves decomposed Unicode without NFC normalization', () {
      const decomposed = 'e\u0301';
      final input = {'value': decomposed};
      final output = canonicalizer.canonicalize(input);
      expect(output, contains(decomposed));
      expect(output, isNot(contains('é')));
      expect(decomposed.runes.toList(), [0x65, 0x0301]);
    });
  });
}

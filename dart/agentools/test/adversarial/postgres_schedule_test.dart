import 'dart:io';

import 'package:test/test.dart';

void main() {
  final repo = Directory.current.uri.resolve('../../').toFilePath();
  final schedules = File(
    '${repo}fixtures/hardening/adversarial/storage-schedules.v1.json',
  );

  test('schedule file has no sleep and covers catalog IDs', () {
    expect(schedules.existsSync(), isTrue);
    final raw = schedules.readAsStringSync();
    expect(raw.toLowerCase(), isNot(contains('sleep')));
    expect(raw, contains('SCH-TKN-CONCURRENT'));
    expect(raw, contains('SCH-IDC-TAKEOVER'));
    expect(raw, contains('SCH-EVT-CONCURRENT'));
  });

  test('storage_chaos offline tests are green', () {
    final result = Process.runSync('python3', [
      '${repo}tools/storage_chaos.py',
      'test',
    ], workingDirectory: repo);
    expect(result.exitCode, 0, reason: '${result.stdout}${result.stderr}');
  });
}

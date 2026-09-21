import 'dart:io';

import 'package:test/test.dart';

void main() {
  test('conformance_client tool exists for official MCP driver', () {
    final path = 'tool/conformance_client.dart';
    expect(File(path).existsSync(), isTrue);
  });
}

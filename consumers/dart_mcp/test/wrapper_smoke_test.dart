import 'package:agentools_mcp_dart/agentools_mcp_dart.dart';
import 'package:test/test.dart';

void main() {
  test('McpDartTransport constructs from supported libraries', () {
    expect(McpDartTransport(), isNotNull);
  });
}

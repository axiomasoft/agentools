import 'package:agentools_mcp_dart/agentools_mcp_dart.dart';
import 'package:test/test.dart';

import 'support/mock_mcp_server.dart';

void main() {
  test('discover without resultType is rejected by mcp_dart client', () async {
    final server = await startMockMcpServer(
      discoverFixturePath: 'test/fixtures/discover-no-resulttype.json',
    );
    final transport = McpDartTransport();
    Object? caught;
    try {
      await transport.connect(Uri.parse('http://localhost:${server.port}/mcp'));
    } catch (error) {
      caught = error;
    } finally {
      await transport.close();
      await server.close(force: true);
    }

    expect(caught, isNotNull);
    expect('$caught', contains('resultType'));
  });
}

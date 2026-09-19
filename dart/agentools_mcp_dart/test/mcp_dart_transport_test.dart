import 'dart:io';

import 'package:agentools/agentools.dart';
import 'package:agentools_mcp_dart/agentools_mcp_dart.dart';
import 'package:test/test.dart';

import 'support/mock_mcp_server.dart';

void main() {
  group('McpDartTransport', () {
    late HttpServer server;
    late McpDartTransport transport;
    late List<String> mockLog;

    setUp(() async {
      mockLog = <String>[];
      server = await startMockMcpServer(
        discoverFixturePath: 'test/fixtures/discover-result.json',
        callToolFixturePath: 'test/fixtures/call-tool-result.json',
        requestLog: mockLog,
      );
      transport = McpDartTransport();
      await transport.connect(
        Uri.parse('http://localhost:${server.port}/mcp'),
      );
    });

    tearDown(() async {
      await transport.close();
      await server.close(force: true);
    });

    test('connect uses server/discover without initialize', () async {
      final discover = await transport.discoverTools();
      expect(discover.protocolVersion, '2026-07-28');
      expect(discover.tools, isNotEmpty);
      expect(discover.tools.first.name, 'agentools.smoke');

      await transport.invokeTool(
        const McpToolInvocation(
          toolName: 'agentools.smoke',
          arguments: {'probe': 'p8.12'},
        ),
      );

      expect(
        mockLog.where((line) => line.contains('server/discover')),
        isNotEmpty,
      );
      expect(mockLog.where((line) => line.contains('tools/call')), isNotEmpty);
      expect(mockLog.where((line) => line.contains('initialize')), isEmpty);
    });

    test('invokeTool returns complete result', () async {
      final result = await transport.invokeTool(
        const McpToolInvocation(
          toolName: 'agentools.smoke',
          arguments: {'probe': 'p8.12'},
        ),
      );
      expect(result.kind, McpToolOutcomeKind.complete);
      expect(result.isError, isFalse);
      expect(result.content.first['text'], contains('tools/call ok'));
    });
  });
}

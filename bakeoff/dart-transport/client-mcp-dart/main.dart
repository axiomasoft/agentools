// Клиент поверх mcp_dart (P2.4, вариант "mcp_dart"). require2026 => discover-only,
// без initialize (D20 modern-only): connect() запускает server/discover, затем один
// tools/call. Порт мока передаётся первым аргументом.
import 'package:mcp_dart/mcp_dart.dart';

Future<void> main(List<String> args) async {
  final port = args[0];
  final client = McpClient(
    Implementation(name: 'bakeoff-smoke-client', version: '0.0.1'),
    options: McpClientOptions(protocol: McpProtocol.require2026),
  );

  final transport = StreamableHttpClientTransport(
    Uri.parse('http://localhost:$port/mcp'),
  );

  await client.connect(transport);
  print('CONNECT OK (server/discover, no initialize)');

  final result = await client.callTool(
    CallToolRequest(name: 'bakeoff.smoke', arguments: {'probe': 'p2.4'}),
  );
  print('CALLTOOL OK: isError=${result.isError} content=${result.content.map((c) => c.toJson())}');

  await client.close();
}

// Тонкий клиент "руками" (P2.4, вариант "тонкий клиент"): те же два вызова
// (server/discover, tools/call), без mcp_dart — голый POST JSON-RPC против
// schema.json как формы сообщения (без initialize, modern-only, D20).
import 'dart:convert';
import 'package:http/http.dart' as http;

Map<String, dynamic> meta() => {
      '_meta': {
        'io.modelcontextprotocol/protocolVersion': '2026-07-28',
        'io.modelcontextprotocol/clientInfo': {
          'name': 'bakeoff-thin-client',
          'version': '0.0.1',
        },
        'io.modelcontextprotocol/clientCapabilities': {},
      },
    };

Future<Map<String, dynamic>> call(
    Uri url, int id, String method, Map<String, dynamic> params) async {
  final resp = await http.post(
    url,
    headers: {
      'content-type': 'application/json',
      'accept': 'application/json',
    },
    body: jsonEncode({
      'jsonrpc': '2.0',
      'id': id,
      'method': method,
      'params': params,
    }),
  );
  final decoded = jsonDecode(resp.body) as Map<String, dynamic>;
  if (decoded.containsKey('error')) {
    throw StateError('JSON-RPC error: ${decoded['error']}');
  }
  final result = decoded['result'] as Map<String, dynamic>;
  if (result['resultType'] != 'complete') {
    throw StateError('unexpected resultType: ${result['resultType']}');
  }
  return result;
}

Future<void> main(List<String> args) async {
  final port = args[0];
  final url = Uri.parse('http://localhost:$port/mcp');

  final discover = await call(url, 0, 'server/discover', meta());
  print('DISCOVER OK: supportedVersions=${discover['supportedVersions']} '
      'capabilities=${discover['capabilities']}');

  final toolParams = {
    'name': 'bakeoff.smoke',
    'arguments': {'probe': 'p2.4'},
    ...meta(),
  };
  final toolResult = await call(url, 1, 'tools/call', toolParams);
  print('CALLTOOL OK: isError=${toolResult['isError']} '
      'content=${toolResult['content']}');
}

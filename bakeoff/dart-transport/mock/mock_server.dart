// Мок-сервер MCP (P2.4): фиксированные JSON-RPC ответы из fixtures/*.json,
// никакой генерации на лету (детерминизм). Отвечает ТОЛЬКО server/discover и
// tools/call; на любой другой метод (в т.ч. initialize — modern-only, D20) —
// JSON-RPC error -32601. Каждый входящий запрос логируется построчно в stderr,
// чтобы прогон обоих клиентов был воспроизводим и виден в логе.
import 'dart:convert';
import 'dart:io';
import 'package:shelf/shelf.dart';
import 'package:shelf/shelf_io.dart' as shelf_io;

Future<void> main(List<String> args) async {
  final port = int.parse(args.isNotEmpty ? args[0] : '0');
  final discoverResult =
      jsonDecode(File('fixtures/discover-result.json').readAsStringSync());
  final callToolResult =
      jsonDecode(File('fixtures/call-tool-result.json').readAsStringSync());

  Future<Response> handler(Request request) async {
    if (request.method != 'POST') {
      return Response(405);
    }
    final body = await request.readAsString();
    final req = jsonDecode(body) as Map<String, dynamic>;
    final method = req['method'] as String?;
    stderr.writeln('MOCK RECEIVED: method=$method id=${req['id']} body=$body');
    final id = req['id'];
    Map<String, dynamic> resp;
    if (method == 'server/discover') {
      resp = {'jsonrpc': '2.0', 'id': id, 'result': discoverResult};
    } else if (method == 'tools/call') {
      resp = {'jsonrpc': '2.0', 'id': id, 'result': callToolResult};
    } else {
      resp = {
        'jsonrpc': '2.0',
        'id': id,
        'error': {'code': -32601, 'message': 'Method not found: $method'},
      };
    }
    return Response.ok(jsonEncode(resp),
        headers: {'content-type': 'application/json'});
  }

  final server = await shelf_io.serve(handler, 'localhost', port);
  print('MOCK LISTENING ${server.address.host}:${server.port}');
}

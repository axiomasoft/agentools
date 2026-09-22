import 'dart:convert';
import 'dart:io';

import 'package:shelf/shelf.dart';
import 'package:shelf/shelf_io.dart' as shelf_io;

/// Local MCP mock: fixed JSON-RPC fixtures, request log on stderr.
Future<HttpServer> startMockMcpServer({
  required String discoverFixturePath,
  String? callToolFixturePath,
  List<String>? requestLog,
  bool rejectFirstDiscovery = false,
  int port = 0,
}) async {
  final discoverResult = jsonDecode(
    File(discoverFixturePath).readAsStringSync(),
  );
  final callToolResult = callToolFixturePath == null
      ? null
      : jsonDecode(File(callToolFixturePath).readAsStringSync());
  final toolsListResult = jsonDecode(
    File('test/fixtures/tools-list-result.json').readAsStringSync(),
  );
  var discoveryRejected = false;

  Future<Response> handler(Request request) async {
    if (request.method != 'POST') {
      return Response(405);
    }
    final body = await request.readAsString();
    final req = jsonDecode(body) as Map<String, dynamic>;
    final method = req['method'] as String?;
    final line = 'MOCK RECEIVED: method=$method id=${req['id']} body=$body';
    stderr.writeln(line);
    requestLog?.add(line);
    final id = req['id'];
    final Map<String, dynamic> resp;
    if (method == 'server/discover') {
      if (rejectFirstDiscovery && !discoveryRejected) {
        discoveryRejected = true;
        resp = {
          'jsonrpc': '2.0',
          'id': id,
          'error': {
            'code': -32022,
            'message': 'Unsupported protocol version',
            'data': {
              'supported': ['2026-07-28'],
              'requested': '2026-07-28',
            },
          },
        };
      } else {
        resp = {'jsonrpc': '2.0', 'id': id, 'result': discoverResult};
      }
    } else if (method == 'tools/call' && callToolResult != null) {
      resp = {'jsonrpc': '2.0', 'id': id, 'result': callToolResult};
    } else if (method == 'tools/list') {
      resp = {'jsonrpc': '2.0', 'id': id, 'result': toolsListResult};
    } else {
      resp = {
        'jsonrpc': '2.0',
        'id': id,
        'error': {'code': -32601, 'message': 'Method not found: $method'},
      };
    }
    return Response.ok(
      jsonEncode(resp),
      headers: {'content-type': 'application/json'},
    );
  }

  return shelf_io.serve(handler, 'localhost', port);
}

import 'dart:io';

import 'package:agentools/agentools.dart';
import 'package:agentools_mcp_dart/agentools_mcp_dart.dart';
import 'package:mcp_dart/mcp_dart.dart';

/// Official MCP conformance driver for scenarios reachable via [McpDartTransport].
Future<void> main(List<String> args) async {
  if (args.isEmpty || args.contains('--help')) {
    stdout.writeln(
      'Usage: dart run tool/conformance_client.dart <conformance-server-url>',
    );
    return;
  }

  final serverUrl = Uri.parse(args.last);
  final scenario = Platform.environment['MCP_CONFORMANCE_SCENARIO'];
  final transport = McpDartTransport();

  switch (scenario) {
    case 'tools_call':
      await transport.connect(serverUrl);
      await transport.discoverTools();
      await transport.invokeTool(
        const McpToolInvocation(
          toolName: 'add_numbers',
          arguments: {'a': 2, 'b': 3},
        ),
      );
      await transport.close();
    case 'sep-2322-client-request-state':
      await _runMrtr(transport, serverUrl);
    default:
      stderr.writeln('Unsupported scenario for McpDartTransport: $scenario');
      exit(2);
  }
  exit(0);
}

Future<void> _runMrtr(McpDartTransport transport, Uri url) async {
  await transport.connect(url);
  await transport.discoverTools();
  for (final name in [
    'test_mrtr_echo_state',
    'test_mrtr_no_state',
    'test_mrtr_unrelated',
    'test_mrtr_no_result_type',
  ]) {
    var invocation = McpToolInvocation(toolName: name, arguments: const {});
    for (var attempt = 0; attempt < 4; attempt++) {
      final result = await transport.invokeTool(invocation);
      if (result.kind != McpToolOutcomeKind.inputRequired) {
        break;
      }
      final requestState = result.requestState;
      final inputRequests = result.inputRequests;
      if (requestState == null || inputRequests == null) {
        break;
      }
      final parsed = InputRequest.mapFromJson(inputRequests, 'mrtr');
      if (parsed == null) {
        break;
      }
      final responses = InputResponse.mapToJson(_resolveInputRequests(parsed));
      invocation = McpToolInvocation(
        toolName: name,
        arguments: const {},
        inputResponses: responses,
        requestState: requestState,
      );
    }
  }
  await transport.close();
}

InputResponses _resolveInputRequests(InputRequests requests) {
  return {
    for (final entry in requests.entries)
      entry.key: InputResponse.fromResult(_resolveInputRequest(entry.value)),
  };
}

BaseResultData _resolveInputRequest(InputRequest request) {
  return switch (request.method) {
    Method.elicitationCreate => const ElicitResult(
      action: 'accept',
      content: {'confirmed': true},
    ),
    Method.samplingCreateMessage => const CreateMessageResult(
      role: SamplingMessageRole.assistant,
      model: 'agentools-conformance-model',
      content: SamplingTextContent(text: 'ok'),
    ),
    Method.rootsList => ListRootsResult(
      roots: [Root(uri: Directory.current.uri.toString())],
    ),
    _ => throw UnsupportedError('Unsupported input request ${request.method}'),
  };
}

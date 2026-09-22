import 'dart:io';

import 'package:agentools/agentools.dart';
import 'package:agentools_mcp_dart/agentools_mcp_dart.dart';
import 'package:mcp_dart/mcp_dart.dart';

const supportedConformanceScenarios = <String>{
  'tools_call',
  'request-metadata',
  'sep-2322-client-request-state',
  'http-standard-headers',
  'http-custom-headers',
  'http-invalid-tool-headers',
  'json-schema-ref-no-deref',
};

/// Official MCP conformance driver for scenarios reachable via [McpDartTransport].
Future<void> main(List<String> args) async {
  if (args.length == 1 && args.single == '--list-scenarios') {
    final scenarios = supportedConformanceScenarios.toList()..sort();
    stdout.write('${scenarios.join('\n')}\n');
    return;
  }
  if (args.isEmpty || args.contains('--help')) {
    stdout.writeln(
      'Usage: dart run tool/conformance_client.dart <conformance-server-url>',
    );
    return;
  }

  final serverUrl = Uri.parse(args.last);
  final scenario = Platform.environment['MCP_CONFORMANCE_SCENARIO'];
  if (!supportedConformanceScenarios.contains(scenario)) {
    stderr.writeln('Unsupported scenario for McpDartTransport: $scenario');
    exit(2);
  }
  final transport = McpDartTransport();

  switch (scenario) {
    case 'tools_call':
      await _invoke(transport, serverUrl, const [
        McpToolInvocation(toolName: 'add_numbers', arguments: {'a': 2, 'b': 3}),
      ]);
    case 'request-metadata':
      await _discover(transport, serverUrl);
    case 'sep-2322-client-request-state':
      await _runMrtr(transport, serverUrl);
    case 'http-standard-headers':
      await _invoke(transport, serverUrl, const [
        McpToolInvocation(toolName: 'test_headers', arguments: {}),
        McpToolInvocation(toolName: 'my-hyphenated-tool', arguments: {}),
      ]);
    case 'http-custom-headers':
      await _invoke(transport, serverUrl, const [
        McpToolInvocation(
          toolName: 'test_custom_headers',
          arguments: {
            'region': 'us-west1',
            'priority': 42,
            'verbose': false,
            'debug': true,
            'empty_val': '',
            'method_val': 'test-method',
            'float_val': 3.14159,
            'non_ascii_val': 'Hello, 世界',
            'whitespace_val': ' padded ',
            'leading_space_val': ' us-west1',
            'trailing_space_val': 'us-west1 ',
            'internal_space_val': 'us west 1',
            'control_char_val': 'line1\nline2',
            'crlf_val': 'line1\r\nline2',
            'tab_val': '\tindented',
            'query': 'SELECT * FROM users',
          },
        ),
        McpToolInvocation(
          toolName: 'test_custom_headers_null',
          arguments: {
            'region': 'us-east1',
            'priority': 1,
            'verbose': null,
            'query': 'SELECT 1',
          },
        ),
      ]);
    case 'http-invalid-tool-headers':
      await _invoke(transport, serverUrl, const [
        McpToolInvocation(
          toolName: 'valid_tool',
          arguments: {'region': 'us-east1'},
        ),
      ]);
    case 'json-schema-ref-no-deref':
      await _discover(transport, serverUrl);
  }
  exit(0);
}

Future<void> _discover(McpDartTransport transport, Uri url) async {
  await transport.connect(url);
  await transport.discoverTools();
  await transport.close();
}

Future<void> _invoke(
  McpDartTransport transport,
  Uri url,
  List<McpToolInvocation> invocations,
) async {
  await transport.connect(url);
  await transport.discoverTools();
  for (final invocation in invocations) {
    await transport.invokeTool(invocation);
  }
  await transport.close();
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
      if (inputRequests == null) {
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

import 'package:agentools/agentools.dart';
import 'package:mcp_dart/mcp_dart.dart';

import 'mcp_dart_mapping.dart';

/// [McpTransport] backed by [McpClient] with modern-only negotiation.
final class McpDartTransport implements McpTransport {
  McpDartTransport({McpClient? client}) : _client = client ?? _createClient();

  final McpClient _client;

  static McpClient _createClient() {
    return McpClient(
      const Implementation(name: 'agentools-mcp-dart', version: '0.0.1'),
      options: const McpClientOptions(protocol: McpProtocol.require2026),
    );
  }

  @override
  Future<void> connect(Uri endpoint) async {
    await _client.connect(StreamableHttpClientTransport(endpoint));
  }

  @override
  Future<void> close() => _client.close();

  @override
  Future<McpDiscoverSnapshot> discoverTools() async {
    final discover = await _client.discoverServer();
    final tools = await _client.listTools();
    return McpDiscoverSnapshot(
      protocolVersion: discover.supportedVersions.first,
      tools: tools.tools.map(mapToolDescriptor).toList(growable: false),
      nextCursor: tools.nextCursor,
    );
  }

  @override
  Future<McpToolCallResult> invokeTool(McpToolInvocation invocation) {
    return _sendToolCall(
      CallToolRequest(
        name: invocation.toolName,
        arguments: invocation.arguments,
        inputResponses: mapInputResponses(invocation.inputResponses),
        requestState: invocation.requestState,
      ),
    );
  }

  @override
  Future<McpToolCallResult> resumeClarification(
    McpClarificationRetry retry,
  ) {
    return _sendToolCall(
      CallToolRequest(
        name: retry.invocation.toolName,
        arguments: retry.invocation.arguments,
        inputResponses: mapInputResponses(retry.inputResponses),
        requestState: retry.requestState,
      ),
    );
  }

  @override
  Future<McpTaskSnapshot> fetchTaskState(String taskId) async {
    final task = await _client.request<Task>(
      JsonRpcGetTaskRequest(
        id: -1,
        getParams: GetTaskRequest(taskId: taskId),
      ),
      Task.fromJson,
    );
    return mapTask(task);
  }

  Future<McpToolCallResult> _sendToolCall(CallToolRequest params) async {
    final result = await _client.request<BaseResultData>(
      JsonRpcCallToolRequest(id: -1, params: params.toJson()),
      _parseToolCallResult,
    );
    return mapToolCallResult(result);
  }

  BaseResultData _parseToolCallResult(Map<String, dynamic> json) {
    switch (json['resultType']) {
      case resultTypeInputRequired:
        return InputRequiredResult.fromJson(json);
      case resultTypeTask:
        return CreateTaskExtensionResult.fromJson(json);
      default:
        return CallToolResult.fromJson(json);
    }
  }
}

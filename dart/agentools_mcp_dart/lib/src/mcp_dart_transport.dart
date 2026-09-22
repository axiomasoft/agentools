import 'package:agentools/agentools.dart';
import 'package:mcp_dart/mcp_dart.dart';

import 'mcp_dart_mapping.dart';

/// `McpTransport` backed by `McpClient` with modern-only negotiation.
final class McpDartTransport implements McpTransport {
  McpDartTransport({McpClient? client}) : _client = client ?? _createClient();

  McpClient _client;

  static McpClient _createClient() {
    return McpClient(
      const Implementation(name: 'agentools-mcp-dart', version: '0.0.1'),
      options: const McpClientOptions(protocol: McpProtocol.require2026),
    );
  }

  @override
  Future<void> connect(Uri endpoint) async {
    try {
      await _client.connect(
        _DefaultCompleteTransport(StreamableHttpClientTransport(endpoint)),
      );
    } on McpError catch (error) {
      final supported = error.data is Map
          ? (error.data as Map)['supported']
          : null;
      if (error.code != ErrorCode.unsupportedProtocolVersion.value ||
          supported is! Iterable ||
          !supported.contains('2026-07-28')) {
        rethrow;
      }
      // A stateless server may deliberately reject the first request and ask the
      // client to retry a supported version, including the same preferred one.
      _client = _createClient();
      await _client.connect(
        _DefaultCompleteTransport(StreamableHttpClientTransport(endpoint)),
      );
    }
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
  Future<McpToolCallResult> resumeClarification(McpClarificationRetry retry) {
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
      JsonRpcGetTaskRequest(id: -1, getParams: GetTaskRequest(taskId: taskId)),
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

/// Applies the stateless tools/call default before mcp_dart validates the
/// response envelope. Discovery remains strict: only tool responses may omit
/// resultType and mean `complete`.
final class _DefaultCompleteTransport
    implements
        Transport,
        ProtocolVersionAwareTransport,
        ToolParameterHeaderAwareTransport,
        RequestCancellationAwareTransport {
  _DefaultCompleteTransport(this._inner) {
    _inner.onclose = () => onclose?.call();
    _inner.onerror = (error) => onerror?.call(error);
    _inner.onmessage = _receive;
  }

  final StreamableHttpClientTransport _inner;
  final Set<RequestId> _toolCallIds = {};

  @override
  void Function()? onclose;

  @override
  void Function(Error error)? onerror;

  @override
  void Function(JsonRpcMessage message)? onmessage;

  @override
  String? get sessionId => _inner.sessionId;

  @override
  String? get protocolVersion => _inner.protocolVersion;

  @override
  set protocolVersion(String? value) => _inner.protocolVersion = value;

  @override
  Future<void> start() => _inner.start();

  @override
  Future<void> close() => _inner.close();

  @override
  Future<void> send(JsonRpcMessage message, {int? relatedRequestId}) {
    if (message is JsonRpcRequest && message.method == Method.toolsCall) {
      _toolCallIds.add(message.id);
    }
    return _inner.send(message, relatedRequestId: relatedRequestId);
  }

  void _receive(JsonRpcMessage message) {
    if (message is JsonRpcResponse && _toolCallIds.remove(message.id)) {
      final result = message.result;
      if (!result.containsKey('resultType')) {
        onmessage?.call(
          JsonRpcResponse(
            id: message.id,
            result: {...result, 'resultType': resultTypeComplete},
            meta: message.meta,
          ),
        );
        return;
      }
    } else if (message is JsonRpcError) {
      _toolCallIds.remove(message.id);
    }
    onmessage?.call(message);
  }

  @override
  void setToolParameterHeaderMappings(ToolParameterHeaderMappings mappings) =>
      _inner.setToolParameterHeaderMappings(mappings);

  @override
  bool canCancelRequest(RequestId requestId) =>
      _inner.canCancelRequest(requestId);

  @override
  Future<void> cancelRequest(RequestId requestId) =>
      _inner.cancelRequest(requestId);
}

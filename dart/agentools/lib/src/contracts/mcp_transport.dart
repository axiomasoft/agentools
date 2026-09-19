import 'mcp_transport_types.dart';

/// Network transport for MCP modern-era calls. Implementations live outside
/// core (D86); this port carries only agentools-owned types.
abstract interface class McpTransport {
  Future<void> connect(Uri endpoint);

  Future<void> close();

  /// Runs `server/discover` and returns the negotiated protocol plus tools.
  Future<McpDiscoverSnapshot> discoverTools();

  /// Invokes `tools/call`, surfacing `input_required` instead of auto-filling.
  Future<McpToolCallResult> invokeTool(McpToolInvocation invocation);

  /// Delivers MRTR answers on the same logical tool call (CLR-6).
  Future<McpToolCallResult> resumeClarification(McpClarificationRetry retry);

  /// Reads the current task status from the counterparty (document 07).
  Future<McpTaskSnapshot> fetchTaskState(String taskId);
}

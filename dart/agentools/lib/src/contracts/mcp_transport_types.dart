/// Outcome of `server/discover` plus the first `tools/list` page.
final class McpDiscoverSnapshot {
  const McpDiscoverSnapshot({
    required this.protocolVersion,
    required this.tools,
    this.nextCursor,
  });

  final String protocolVersion;
  final List<McpToolDescriptor> tools;
  final String? nextCursor;
}

/// Minimal tool metadata needed by the core runtime.
final class McpToolDescriptor {
  const McpToolDescriptor({
    required this.name,
    this.description,
    this.inputSchema,
  });

  final String name;
  final String? description;
  final Map<String, dynamic>? inputSchema;
}

/// Initial or follow-up tool invocation.
final class McpToolInvocation {
  const McpToolInvocation({
    required this.toolName,
    required this.arguments,
    this.inputResponses,
    this.requestState,
  });

  final String toolName;
  final Map<String, dynamic> arguments;
  final Map<String, dynamic>? inputResponses;
  final String? requestState;
}

/// MRTR continuation after an `input_required` round (CLR-6..CLR-15).
final class McpClarificationRetry {
  const McpClarificationRetry({
    required this.invocation,
    required this.requestState,
    required this.inputResponses,
  });

  final McpToolInvocation invocation;
  final String requestState;
  final Map<String, dynamic> inputResponses;
}

enum McpToolOutcomeKind { complete, inputRequired, task }

/// Wire-level tool result mapped into core-owned shapes.
final class McpToolCallResult {
  const McpToolCallResult._({
    required this.kind,
    this.isError = false,
    this.content = const [],
    this.structuredContent,
    this.inputRequests,
    this.requestState,
    this.taskId,
    this.taskStatus,
  });

  factory McpToolCallResult.complete({
    required bool isError,
    required List<Map<String, dynamic>> content,
    Map<String, dynamic>? structuredContent,
  }) {
    return McpToolCallResult._(
      kind: McpToolOutcomeKind.complete,
      isError: isError,
      content: content,
      structuredContent: structuredContent,
    );
  }

  factory McpToolCallResult.inputRequired({
    required Map<String, dynamic> inputRequests,
    String? requestState,
  }) {
    return McpToolCallResult._(
      kind: McpToolOutcomeKind.inputRequired,
      inputRequests: inputRequests,
      requestState: requestState,
    );
  }

  factory McpToolCallResult.task({
    required String taskId,
    required String taskStatus,
  }) {
    return McpToolCallResult._(
      kind: McpToolOutcomeKind.task,
      taskId: taskId,
      taskStatus: taskStatus,
    );
  }

  final McpToolOutcomeKind kind;
  final bool isError;
  final List<Map<String, dynamic>> content;
  final Map<String, dynamic>? structuredContent;
  final Map<String, dynamic>? inputRequests;
  final String? requestState;
  final String? taskId;
  final String? taskStatus;
}

/// Task state snapshot for long operations (LOP-1, document 07).
final class McpTaskSnapshot {
  const McpTaskSnapshot({
    required this.taskId,
    required this.status,
    this.statusMessage,
  });

  final String taskId;
  final String status;
  final String? statusMessage;
}

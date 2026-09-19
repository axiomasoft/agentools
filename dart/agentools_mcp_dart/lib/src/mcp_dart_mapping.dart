import 'package:agentools/agentools.dart';
import 'package:mcp_dart/mcp_dart.dart';

McpToolDescriptor mapToolDescriptor(Tool tool) {
  return McpToolDescriptor(
    name: tool.name,
    description: tool.description,
    inputSchema: tool.inputSchema.toJson(),
  );
}

List<Map<String, dynamic>> mapContent(List<Content> content) {
  return content.map((item) => item.toJson()).toList(growable: false);
}

InputResponses? mapInputResponses(Map<String, dynamic>? responses) {
  if (responses == null) {
    return null;
  }
  return InputResponse.mapFromJson(
    responses,
    'McpToolInvocation.inputResponses',
  );
}

Map<String, dynamic> mapInputRequests(InputRequests requests) {
  return InputRequest.mapToJson(requests);
}

McpToolCallResult mapToolCallResult(BaseResultData result) {
  if (result is InputRequiredResult) {
    final requests = result.inputRequests;
    final requestState = result.requestState;
    if (requests == null || requestState == null) {
      throw FormatException(
        'input_required result must include inputRequests and requestState',
      );
    }
    return McpToolCallResult.inputRequired(
      inputRequests: mapInputRequests(requests),
      requestState: requestState,
    );
  }

  if (result is CreateTaskExtensionResult) {
    return McpToolCallResult.task(
      taskId: result.task.taskId,
      taskStatus: result.task.status.name,
    );
  }

  if (result is CallToolResult) {
    return McpToolCallResult.complete(
      isError: result.isError,
      content: mapContent(result.content),
      structuredContent: result.structuredContent,
    );
  }

  throw FormatException('Unexpected tools/call result: ${result.runtimeType}');
}

McpTaskSnapshot mapTask(Task task) {
  return McpTaskSnapshot(
    taskId: task.taskId,
    status: task.status.name,
    statusMessage: task.statusMessage,
  );
}

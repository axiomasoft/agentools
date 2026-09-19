import '../errors/tool_execution_result.dart';

/// The scenario runtime invokes tools through this narrow, network-agnostic port.
abstract class ToolPort {
  ToolExecutionResult call(
    String tool,
    Map<String, dynamic> arguments,
    String idempotencyKey,
  );
}

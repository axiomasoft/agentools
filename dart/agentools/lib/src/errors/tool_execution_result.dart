import 'tool_execution_error.dart';

/// A completed tool call; business failures are returned as data for model recovery.
final class ToolExecutionResult {
  ToolExecutionResult._({this.value, this.error}) {
    if ((value == null) == (error == null)) {
      throw ArgumentError('A tool execution result must contain exactly one outcome.');
    }
  }

  factory ToolExecutionResult.success(Map<String, dynamic> value) {
    return ToolExecutionResult._(value: value);
  }

  factory ToolExecutionResult.failure(ToolExecutionError error) {
    return ToolExecutionResult._(error: error);
  }

  final Map<String, dynamic>? value;
  final ToolExecutionError? error;

  bool get isError => error != null;
}

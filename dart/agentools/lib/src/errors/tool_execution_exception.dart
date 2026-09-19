import 'tool_execution_error.dart';

/// Base exception for structured tool-execution failures.
class ToolExecutionException implements Exception {
  ToolExecutionException(this.error);

  final ToolExecutionError error;

  @override
  String toString() => error.title;
}

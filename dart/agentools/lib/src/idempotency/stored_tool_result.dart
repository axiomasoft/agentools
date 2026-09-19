import '../tool/tool_execution_error.dart';

/// A completed tool result, successful or erroneous, preserved without re-execution.
final class StoredToolResult {
  StoredToolResult({this.value, this.error}) {
    if ((value == null) == (error == null)) {
      throw ArgumentError(
        'A completed tool result contains exactly one of value or error.',
      );
    }
  }

  final Map<String, dynamic>? value;
  final ToolExecutionError? error;
}

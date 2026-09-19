import '../tool/tool_execution_error.dart';
import 'journal_error_codes.dart';
import 'lifecycle_exception.dart';

/// ALC-21: stale `expected_prev_seq` is a typed version conflict.
final class VersionConflict extends LifecycleException {
  VersionConflict({required this.expected, required this.actual})
      : error = ToolExecutionError(
          code: JournalErrorCodes.versionConflict,
          category: 'conflict',
          retryable: true,
          title: 'The action journal has advanced.',
          context: {
            'expected_prev_seq': expected,
            'actual_prev_seq': actual,
          },
        ),
        super('Expected previous sequence $expected, got $actual.');

  final int expected;
  final int actual;
  final ToolExecutionError error;
}

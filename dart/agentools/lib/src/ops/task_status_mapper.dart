import '../errors/tool_execution_error.dart';
import '../errors/tool_execution_result.dart';
import '../journal/action_status.dart';
import '../journal/folded_action.dart';
import 'ops_error_codes.dart';

/// Maps counterparty task status to journal append instructions (LOP-12).
final class TaskStatusMapper {
  List<({String kind, Map<String, dynamic> payload})> map(
    String status,
    FoldedAction state, {
    Map<String, dynamic>? inputPayload,
  }) {
    return switch (status) {
      'working' => const [],
      'input_required' => _mapInputRequired(state, inputPayload),
      'completed' => [
        (kind: 'action_completed', payload: <String, dynamic>{}),
      ],
      'failed' => [
        (kind: 'action_failed', payload: <String, dynamic>{}),
      ],
      'cancelled' => _mapCancelled(state),
      _ => const [],
    };
  }

  /// LOP-14; input_required during applying is a typed error, not clarification.
  List<({String kind, Map<String, dynamic> payload})> _mapInputRequired(
    FoldedAction state,
    Map<String, dynamic>? inputPayload,
  ) {
    if (state.status == ActionStatus.applying) {
      throw TaskStatusMappingException(
        ToolExecutionResult.failure(
          ToolExecutionError(
            code: OpsErrorCodes.taskInputRequiredWhileApplying,
            category: 'precondition',
            retryable: false,
            title:
                'Task requested input while an external effect was unresolved.',
          ),
        ),
      );
    }

    return [
      (
        kind: 'input_recorded',
        payload: inputPayload ??
            <String, dynamic>{'name': 'task_input', 'value': null},
      ),
    ];
  }

  /// LOP-25; cancelled maps to action_cancelled only without unresolved effects.
  List<({String kind, Map<String, dynamic> payload})> _mapCancelled(
    FoldedAction state,
  ) {
    if (state.hasUnresolvedEffect()) {
      return const [];
    }

    return [
      (kind: 'action_cancelled', payload: <String, dynamic>{}),
    ];
  }
}

final class TaskStatusMappingException implements Exception {
  TaskStatusMappingException(this.result);

  final ToolExecutionResult result;

  @override
  String toString() => 'Task status mapping failed.';
}

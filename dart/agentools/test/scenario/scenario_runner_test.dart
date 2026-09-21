import 'package:agentools/agentools.dart';
import 'package:test/test.dart';

void main() {
  // SCN-23–SCN-25: successful steps compensate in reverse order on a later failure.
  test('runs compensations in reverse order', () {
    final port = _RecordingToolPort();
    final scenario = {
      'scenario_id': 'pay',
      'spec_rev': '2026-08',
      'steps': [
        {
          'id': 'one',
          'tool': 'one',
          'input_map': <String, dynamic>{},
          'compensate': {'tool': 'undo-one', 'input_map': <String, dynamic>{}},
        },
        {
          'id': 'two',
          'tool': 'two',
          'input_map': <String, dynamic>{},
          'compensate': {'tool': 'undo-two', 'input_map': <String, dynamic>{}},
        },
        {
          'id': 'send',
          'tool': 'fail',
          'input_map': <String, dynamic>{},
          'on_semantic_fail': {'action': 'abort'},
        },
      ],
    };

    final result = ScenarioRunner().run(scenario, {}, port);

    expect(result.outcome, ScenarioOutcome.compensated);
    expect(port.calls, ['one', 'two', 'fail', 'undo-two', 'undo-one']);
  });

  // SCN-26, SCN-27: a failed compensation stops rollback and stays distinct from FAILED.
  test('reports a separate compensation failure', () {
    final port = _FailingCompensationToolPort();
    final scenario = {
      'scenario_id': 'pay',
      'spec_rev': '2026-08',
      'steps': [
        {
          'id': 'one',
          'tool': 'one',
          'input_map': <String, dynamic>{},
          'compensate': {'tool': 'undo-one', 'input_map': <String, dynamic>{}},
        },
        {
          'id': 'two',
          'tool': 'two',
          'input_map': <String, dynamic>{},
          'compensate': {'tool': 'undo-two', 'input_map': <String, dynamic>{}},
        },
        {'id': 'send', 'tool': 'fail', 'input_map': <String, dynamic>{}},
      ],
    };

    final result = ScenarioRunner().run(scenario, {}, port);

    expect(result.outcome, ScenarioOutcome.compensationFailed);
    expect(result.stoppedAtStepId, 'two');
    expect(result.outcome, isNot(ScenarioOutcome.failed));
    // ignore: avoid_print
    print('compensation failure outcome: ${result.outcome.wireValue}');
  });

  // SCN-11: continue records no result for the failed step and runs the following step.
  test('continues without exposing a failed step result', () {
    final port = _ContinueScenarioToolPort();
    final scenario = {
      'scenario_id': 'continue',
      'spec_rev': '2026-08',
      'steps': [
        {
          'id': 'fails',
          'tool': 'fails',
          'input_map': <String, dynamic>{},
          'on_semantic_fail': {'action': 'continue'},
        },
        {'id': 'next', 'tool': 'next', 'input_map': <String, dynamic>{}},
      ],
    };

    final result = ScenarioRunner().run(scenario, {}, port);

    expect(result.outcome, ScenarioOutcome.completed);
    expect(port.calls, ['fails', 'next']);
    expect(result.stepResults.containsKey('fails'), isFalse);
  });
}

final class _RecordingToolPort implements ToolPort {
  final calls = <String>[];

  @override
  ToolExecutionResult call(
    String tool,
    Map<String, dynamic> arguments,
    String idempotencyKey,
  ) {
    calls.add(tool);
    if (tool == 'fail') {
      return ToolExecutionResult.failure(
        ToolExecutionError(
          code: 'demo.validation.failed',
          category: ErrorCategory.validation,
          retryable: false,
          title: 'failed',
        ),
      );
    }
    return ToolExecutionResult.success({'reservation_id': tool});
  }
}

final class _FailingCompensationToolPort implements ToolPort {
  @override
  ToolExecutionResult call(
    String tool,
    Map<String, dynamic> arguments,
    String idempotencyKey,
  ) {
    if (tool == 'fail' || tool == 'undo-two') {
      return ToolExecutionResult.failure(
        ToolExecutionError(
          code: 'demo.validation.failed',
          category: ErrorCategory.validation,
          retryable: false,
          title: 'failed',
        ),
      );
    }
    return ToolExecutionResult.success({});
  }
}

final class _ContinueScenarioToolPort implements ToolPort {
  final calls = <String>[];

  @override
  ToolExecutionResult call(
    String tool,
    Map<String, dynamic> arguments,
    String idempotencyKey,
  ) {
    calls.add(tool);
    if (tool == 'fails') {
      return ToolExecutionResult.failure(
        ToolExecutionError(
          code: 'demo.validation.failed',
          category: ErrorCategory.validation,
          retryable: false,
          title: 'failed',
        ),
      );
    }
    return ToolExecutionResult.success({'ok': true});
  }
}

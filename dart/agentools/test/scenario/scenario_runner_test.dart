import 'package:agentools/agentools.dart';
import 'package:agentools/agentools_testkit.dart';
import 'package:test/test.dart';

final _runKey = IdempotencyKey('at1:run-0001-abcdef');

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

    final result = ScenarioRunner().run(scenario, {}, port, _runKey);

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

    final result = ScenarioRunner().run(scenario, {}, port, _runKey);

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

    final result = ScenarioRunner().run(scenario, {}, port, _runKey);

    expect(result.outcome, ScenarioOutcome.completed);
    expect(port.calls, ['fails', 'next']);
    expect(result.stepResults.containsKey('fails'), isFalse);
  });

  // IDE-1, IDE-4, SCN-8, SCN-25; keys are derived from the run key. The
  // expected values are shared with the PHP runner test (cross-language parity).
  test('derives IDE-4 keys from the run key', () {
    final port = _KeyRecordingToolPort({'send'});
    ScenarioRunner().run(
      {
        'scenario_id': 'pay',
        'spec_rev': '2026-09',
        'steps': [
          {
            'id': 'one',
            'tool': 'one',
            'input_map': <String, dynamic>{},
            'compensate': {
              'tool': 'undo-one',
              'input_map': <String, dynamic>{},
            },
          },
          {'id': 'send', 'tool': 'send', 'input_map': <String, dynamic>{}},
        ],
      },
      {},
      port,
      _runKey,
    );
    expect(port.calls, [
      ('one', 'at1:_Tdvrr8jtfeFMFwi4OSkm3ql7ITj5UoefYfT-51davE'),
      ('send', 'at1:EfAEOX0HLeq9g7oYoqR2C9NJ3RJ51FM_HpOzvG32iA4'),
      ('undo-one', 'at1:7553ycecufcG9QBkwC4GrxjDbvI4As12iuK32ZC6g_U'),
    ]);
  });

  // IDE-1; the same run key re-presents the same keys, a new one is a new intent.
  test('run keys separate intents', () {
    final scenario = {
      'scenario_id': 'pay',
      'spec_rev': '2026-09',
      'steps': [
        {'id': 'one', 'tool': 'one', 'input_map': <String, dynamic>{}},
      ],
    };
    final first = _KeyRecordingToolPort({});
    final again = _KeyRecordingToolPort({});
    final other = _KeyRecordingToolPort({});
    ScenarioRunner().run(scenario, {}, first, _runKey);
    ScenarioRunner().run(scenario, {}, again, _runKey);
    ScenarioRunner().run(
      scenario,
      {},
      other,
      IdempotencyKey('at1:run-0002-abcdef'),
    );
    expect(again.calls, first.calls);
    expect(other.calls.single.$2, isNot(first.calls.single.$2));
  });

  // IDE-4, IDE-12; runner keys pass the fenced coordinator and deduplicate.
  test('runs through the idempotency coordinator', () {
    final tools = _KeyRecordingToolPort({});
    final coordinator = IdempotencyCoordinator(
      store: InMemoryDeduplicationStore(
        DeduplicationPolicy(
          retention: const Duration(days: 1),
          confirmationLifetime: Duration.zero,
          clientRetryWindow: Duration.zero,
        ),
      ),
      catalog: ToolIdempotencyCatalog(
        {'one': ToolIdempotencyCapability.nativeKey},
        {'one': 'write'},
      ),
      tools: tools,
      canonicalizer: Canonicalizer(),
      tenantId: 'tenant-1',
      actionId: 'action-1',
    );
    final scenario = {
      'scenario_id': 'pay',
      'spec_rev': '2026-09',
      'steps': [
        {'id': 'one', 'tool': 'one', 'input_map': <String, dynamic>{}},
      ],
    };
    final first = ScenarioRunner().run(scenario, {}, coordinator, _runKey);
    final replay = ScenarioRunner().run(scenario, {}, coordinator, _runKey);
    expect(first.outcome, ScenarioOutcome.completed);
    expect(replay.outcome, ScenarioOutcome.completed);
    expect(tools.calls, hasLength(1));
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

final class _KeyRecordingToolPort implements ToolPort {
  _KeyRecordingToolPort(this.failing);

  final Set<String> failing;
  final calls = <(String, String)>[];

  @override
  ToolExecutionResult call(
    String tool,
    Map<String, dynamic> arguments,
    String idempotencyKey,
  ) {
    calls.add((tool, idempotencyKey));
    if (failing.contains(tool)) {
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

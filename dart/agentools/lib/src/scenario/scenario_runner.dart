import '../contracts/tool_port.dart';
import '../errors/tool_execution_result.dart';
import 'expression_evaluator.dart';
import 'expression_exception.dart';
import 'scenario_outcome.dart';
import 'scenario_result.dart';

/// SCN-1–SCN-11 and SCN-20–SCN-29: a linear interpreter with no goto, loops,
/// or hidden control-flow.
final class ScenarioRunner {
  ScenarioRunner({ExpressionEvaluator? expressions})
    : _expressions = expressions ?? ExpressionEvaluator();

  final ExpressionEvaluator _expressions;
  var _executedSteps = 0;

  ScenarioResult run(
    Map<String, dynamic> scenario,
    Map<String, dynamic> context,
    ToolPort tools,
  ) {
    _executedSteps = 0;
    if (!const {'2026-08', '2026-09'}.contains(scenario['spec_rev'])) {
      throw ArgumentError(
        'A scenario must declare a supported specification revision.',
      );
    }
    final scenarioId = _stringField(scenario, 'scenario_id');
    final steps = scenario['steps'];
    if (steps is! List || steps.length > 64) {
      throw ExpressionException(
        'scenario.max_steps',
        'Scenario step limit scenario.max_steps (64) was exceeded.',
      );
    }

    final results = <String, Map<String, dynamic>>{};
    final compensations = <_CompensationEntry>[];
    final ids = <String>{};

    for (final step in steps) {
      if (step is! Map) {
        throw ArgumentError('Scenario steps must be objects.');
      }
      final stepMap = Map<String, dynamic>.from(step);
      final stepId = _stringField(stepMap, 'id');
      if (ids.contains(stepId)) {
        throw ArgumentError('Scenario step identifiers must be unique.');
      }
      ids.add(stepId);

      try {
        final when = stepMap['when'];
        if (when != null) {
          final whenValue = _expressions.evaluate(
            _stringField({'when': when}, 'when'),
            context,
            results,
          );
          if (whenValue is! bool) {
            throw ExpressionException(
              null,
              'A scenario when expression must evaluate to boolean.',
            );
          }
          if (!whenValue) {
            continue;
          }
        }

        final arguments = _arguments(
          stepMap['input_map'] ?? const <String, dynamic>{},
          context,
          results,
        );
        final result = _invoke(
          tools,
          _stringField(stepMap, 'tool'),
          arguments,
          '$scenarioId:$stepId',
          stepMap['on_semantic_fail'],
        );
        if (result.isError) {
          if (_failureAction(stepMap, technical: false) == 'continue') {
            continue;
          }
          return _handleFailure(
            scenarioId: scenarioId,
            stepId: stepId,
            step: stepMap,
            compensations: compensations,
            tools: tools,
            results: results,
            technical: false,
          );
        }
        results[stepId] = Map<String, dynamic>.from(result.value ?? const {});
        final compensate = stepMap['compensate'];
        if (compensate is Map) {
          compensations.add(
            _CompensationEntry(
              id: stepId,
              compensate: Map<String, dynamic>.from(compensate),
            ),
          );
        }
      } on ExpressionException {
        if (_failureAction(stepMap, technical: true) == 'continue') {
          continue;
        }
        return _handleFailure(
          scenarioId: scenarioId,
          stepId: stepId,
          step: stepMap,
          compensations: compensations,
          tools: tools,
          results: results,
          technical: true,
        );
      }
    }

    return ScenarioResult(ScenarioOutcome.completed, results);
  }

  ScenarioResult _handleFailure({
    required String scenarioId,
    required String stepId,
    required Map<String, dynamic> step,
    required List<_CompensationEntry> compensations,
    required ToolPort tools,
    required Map<String, Map<String, dynamic>> results,
    required bool technical,
  }) {
    final action = _failureAction(step, technical: technical);
    if (action == 'continue') {
      return ScenarioResult(ScenarioOutcome.failed, const {});
    }

    for (final entry in compensations.reversed) {
      final arguments = _arguments(
        entry.compensate['input_map'] ?? const <String, dynamic>{},
        const {},
        results,
      );
      final tool = _stringField(entry.compensate, 'tool');
      final result = _call(
        tools,
        tool,
        arguments,
        '$scenarioId:${entry.id}:compensation',
      );
      if (result.isError) {
        return ScenarioResult(
          ScenarioOutcome.compensationFailed,
          const {},
          stoppedAtStepId: entry.id,
        );
      }
    }

    return ScenarioResult(
      compensations.isEmpty
          ? ScenarioOutcome.failed
          : ScenarioOutcome.compensated,
      const {},
    );
  }

  Map<String, dynamic> _arguments(
    Object? mapping,
    Map<String, dynamic> context,
    Map<String, Map<String, dynamic>> results,
  ) {
    if (mapping is! Map) {
      throw ArgumentError('input_map must be an object.');
    }
    final arguments = <String, dynamic>{};
    for (final entry in mapping.entries) {
      if (entry.key is! String) {
        throw ArgumentError('input_map keys must be strings.');
      }
      final name = entry.key as String;
      final expression = entry.value;
      arguments[name] = expression is String
          ? _expressions.evaluate(expression, context, results)
          : expression;
    }
    return arguments;
  }

  ToolExecutionResult _invoke(
    ToolPort tools,
    String tool,
    Map<String, dynamic> arguments,
    String key,
    Object? route,
  ) {
    var attempts = 1;
    if (route is Map && route['action'] == 'retry') {
      final retryAttempts = route['attempts'];
      if (retryAttempts is! int) {
        throw ArgumentError(
          'A retry route requires a positive finite attempts count.',
        );
      }
      attempts = retryAttempts;
    }
    if (attempts < 1) {
      throw ArgumentError(
        'A retry route requires a positive finite attempts count.',
      );
    }

    var result = _call(tools, tool, arguments, key);
    for (var attempt = 1; result.isError && attempt < attempts; attempt++) {
      result = _call(tools, tool, arguments, key);
    }
    return result;
  }

  String _stringField(Map<String, dynamic> value, String field) {
    final raw = value[field];
    if (raw is! String || raw.isEmpty) {
      throw ArgumentError('Scenario requires non-empty $field.');
    }
    return raw;
  }

  String _failureAction(Map<String, dynamic> step, {required bool technical}) {
    final route =
        step[technical ? 'on_technical_fail' : 'on_semantic_fail'] ??
        const {'action': 'abort'};
    if (route is! Map) {
      throw ArgumentError('Failure routes must be abort, retry, or continue.');
    }
    final action = route['action'];
    if (action is! String ||
        !const {'abort', 'retry', 'continue'}.contains(action)) {
      throw ArgumentError('Failure routes must be abort, retry, or continue.');
    }
    return action;
  }

  ToolExecutionResult _call(
    ToolPort tools,
    String tool,
    Map<String, dynamic> arguments,
    String idempotencyKey,
  ) {
    if (++_executedSteps > 64) {
      throw ExpressionException(
        'scenario.max_steps',
        'Scenario step limit scenario.max_steps (64) was exceeded.',
      );
    }
    return tools.call(tool, arguments, idempotencyKey);
  }
}

final class _CompensationEntry {
  _CompensationEntry({required this.id, required this.compensate});

  final String id;
  final Map<String, dynamic> compensate;
}

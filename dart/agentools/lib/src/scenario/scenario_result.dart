import 'scenario_outcome.dart';

final class ScenarioResult {
  ScenarioResult(this.outcome, this.stepResults, {this.stoppedAtStepId});

  final ScenarioOutcome outcome;
  final Map<String, Map<String, dynamic>> stepResults;
  final String? stoppedAtStepId;
}

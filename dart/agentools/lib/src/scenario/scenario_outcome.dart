/// SCN-28: the run outcome is not an action lifecycle state.
enum ScenarioOutcome {
  completed('COMPLETED'),
  failed('FAILED'),
  compensated('COMPENSATED'),
  compensationFailed('COMPENSATION_FAILED');

  const ScenarioOutcome(this.wireValue);

  final String wireValue;
}

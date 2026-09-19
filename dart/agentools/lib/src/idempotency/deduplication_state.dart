/// The three deduplication outcomes. A fourth "wait for completion" state is forbidden.
enum DeduplicationState {
  newIntent('new'),
  duplicateCompleted('duplicate_completed'),
  concurrentInFlight('concurrent_in_flight');

  const DeduplicationState(this.value);

  final String value;
}

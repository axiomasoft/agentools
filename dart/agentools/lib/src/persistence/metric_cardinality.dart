import '../persistence/event_store_failure.dart';

/// Closed low-cardinality metric labels. Tenant/action/event/trace ids are forbidden.
final class MetricCardinality {
  static const _allow = {
    'event_kind_class': {'lifecycle', 'audit', 'effect'},
    'append_result': {
      'appended',
      'idempotent_replay',
      'conflict',
      'validation',
    },
    'conflict_class': {'version', 'collision', 'partial'},
  };

  static const _deny = {
    'tenant_id',
    'action_id',
    'event_id',
    'trace_id',
    'span_id',
    'user_id',
  };

  void assertAllowed(Map<String, String> labels) {
    for (final entry in labels.entries) {
      final allowed = _allow[entry.key];
      if (_deny.contains(entry.key) ||
          allowed == null ||
          !allowed.contains(entry.value)) {
        throw EventStoreFailure.metricLabelForbidden(entry.key);
      }
    }
  }
}

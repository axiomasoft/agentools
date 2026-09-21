import '../persistence/metric_cardinality.dart' as persistence;

/// Closed low-cardinality metric labels for the supported journal path.
final class MetricCardinality {
  void assertAllowed(Map<String, String> labels) {
    persistence.MetricCardinality().assertAllowed(labels);
  }
}

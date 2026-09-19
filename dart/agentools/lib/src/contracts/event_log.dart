import '../journal/action_event.dart';

/// Append-only action journal persistence. The folded state is always derived elsewhere.
abstract interface class EventLog {
  Future<void> append(ActionEvent event);

  Future<List<ActionEvent>> readFrom(String actionId, int fromSeq);

  /// Returns `0` when the action has no events yet.
  Future<int> lastSeq(String actionId);
}

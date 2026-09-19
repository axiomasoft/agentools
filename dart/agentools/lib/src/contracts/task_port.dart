/// Task-backed long operations use this port; transport lives outside core (LOP-1, D86).
abstract interface class TaskPort {
  /// Returns the current counterparty task status for [taskId].
  Future<String> pollStatus(String taskId);
}

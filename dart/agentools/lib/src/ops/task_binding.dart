/// Opaque task binding record aligned with schema/task-binding.json (LOP-10, LOP-27).
final class TaskBinding {
  TaskBinding({
    required this.actionId,
    required this.taskId,
    this.channel,
    this.observedStatus,
    this.lostReason,
  }) {
    if (actionId.isEmpty || taskId.isEmpty) {
      throw ArgumentError('Action and task identifiers are required.');
    }
    if (lostReason != null &&
        lostReason != 'expired' &&
        lostReason != 'unknown') {
      throw ArgumentError(
        'Task binding loss reason must be expired or unknown.',
      );
    }
  }

  final String actionId;
  final String taskId;
  final String? channel;
  final String? observedStatus;
  final String? lostReason;

  Map<String, dynamic> boundPayload() {
    final payload = <String, dynamic>{'task_id': taskId};
    if (channel != null) {
      payload['channel'] = channel;
    }
    if (observedStatus != null) {
      payload['observed_status'] = observedStatus;
    }
    return payload;
  }

  Map<String, dynamic> lostPayload() {
    if (lostReason == null) {
      throw StateError('A lost binding requires a reason.');
    }

    final payload = <String, dynamic>{'reason': lostReason};
    if (taskId.isNotEmpty) {
      payload['task_id'] = taskId;
    }
    return payload;
  }
}

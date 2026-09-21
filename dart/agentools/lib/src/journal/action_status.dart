/// Closed FSM states from document `03`.
enum ActionStatus {
  created('created'),
  running('running'),
  awaitingConfirmation('awaiting_confirmation'),
  applying('applying'),
  reconciliationRequired('reconciliation_required'),
  completed('completed'),
  rejected('rejected'),
  expired('expired'),
  failed('failed'),
  cancelled('cancelled');

  const ActionStatus(this.value);

  final String value;

  static ActionStatus? parse(String? raw) {
    if (raw == null) {
      return null;
    }
    for (final status in ActionStatus.values) {
      if (status.value == raw) {
        return status;
      }
    }
    return null;
  }

  bool isTerminal() => switch (this) {
    ActionStatus.completed ||
    ActionStatus.rejected ||
    ActionStatus.expired ||
    ActionStatus.failed ||
    ActionStatus.cancelled => true,
    _ => false,
  };
}

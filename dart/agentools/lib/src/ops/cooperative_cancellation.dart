import '../journal/action_status.dart';
import '../journal/folded_action.dart';

/// Cooperative cancellation derived from the journal, not a separate flag (LOP-20..LOP-25).
final class CooperativeCancellation {
  bool isInEffect(FoldedAction state) => state.cancellationRequested;

  /// LOP-23; a cancellation request does not synchronously return a terminal outcome.
  bool requestOutcomeIsPending() => true;

  /// LOP-22; cancellation requests are not revocable.
  bool isRevocable() => false;

  bool canRecordCancelled(FoldedAction state) {
    if (!state.cancellationRequested || state.hasUnresolvedEffect()) {
      return false;
    }

    return state.status == ActionStatus.created ||
        state.status == ActionStatus.running ||
        state.status == ActionStatus.awaitingConfirmation;
  }

  /// LOP-24; cancellation does not forbid subsequent effect requests at the protocol level.
  bool forbidsEffectRequests(FoldedAction state) => false;
}

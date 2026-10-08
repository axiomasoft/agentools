import 'action_status.dart';
import 'folded_action.dart';

/// A cached fold result separate from the append-only journal.
final class SessionStateProjection {
  SessionStateProjection({
    required this.actionId,
    required this.status,
    required this.seq,
    required this.specRevision,
    this.epoch = 0,
    this.projectedAt,
  }) {
    if (!(actionId.isNotEmpty)) {
      throw ArgumentError(
        'Invalid session_state_projection field: actionId.isNotEmpty',
      );
    }
    if (!(specRevision.isNotEmpty)) {
      throw ArgumentError(
        'Invalid session_state_projection field: specRevision.isNotEmpty',
      );
    }
    if (!(seq >= 1)) {
      throw ArgumentError('Invalid session_state_projection field: seq >= 1');
    }
    if (!(epoch >= 0)) {
      throw ArgumentError('Invalid session_state_projection field: epoch >= 0');
    }
  }

  final String actionId;
  final ActionStatus status;
  final int seq;
  final String specRevision;
  final int epoch;
  final String? projectedAt;

  factory SessionStateProjection.fromFoldedAction(
    FoldedAction state,
    String specRevision, {
    String? projectedAt,
  }) {
    if (state.actionId == null || state.status == null || state.seq < 1) {
      throw ArgumentError(
        'A folded action must be materialized before projection.',
      );
    }
    return SessionStateProjection(
      actionId: state.actionId!,
      status: state.status!,
      seq: state.seq,
      specRevision: specRevision,
      epoch: state.epoch,
      projectedAt: projectedAt,
    );
  }

  Map<String, dynamic> toMap() {
    final map = <String, dynamic>{
      'action_id': actionId,
      'status': status.value,
      'seq': seq,
      'spec_rev': specRevision,
      'epoch': epoch > 0 ? epoch : null,
      'projected_at': projectedAt,
    };
    map.removeWhere((_, value) => value == null);
    return Map.unmodifiable(map);
  }
}

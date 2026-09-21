import '../journal/action_status.dart';
import '../journal/folded_action.dart';
import 'cooperative_cancellation.dart';
import 'journal_appender.dart';
import 'long_operation_path.dart';
import 'task_binding.dart';
import 'task_status_mapper.dart';

/// Orchestrates both long-operation paths with immediate task journaling (LOP-1..LOP-5, LOP-10..LOP-19).
final class LongOperationCoordinator {
  LongOperationCoordinator({
    TaskStatusMapper? statusMapper,
    CooperativeCancellation? cancellation,
  }) : _statusMapper = statusMapper ?? TaskStatusMapper(),
       _cancellation = cancellation ?? CooperativeCancellation();

  final TaskStatusMapper _statusMapper;
  final CooperativeCancellation _cancellation;

  bool _taskBoundRecorded = false;
  bool _firstObservationMade = false;

  /// LOP-2; both paths are always available — task extension is optional, not mandatory.
  List<LongOperationPath> availablePaths() => const [
    LongOperationPath.taskBacked,
    LongOperationPath.synchronous,
  ];

  /// LOP-10; task id must be journaled before the first status poll or subscription.
  JournalAppender bindTask(JournalAppender journal, TaskBinding binding) {
    if (_firstObservationMade) {
      throw StateError(
        'Task binding must be recorded before the first status observation.',
      );
    }

    journal.append('task_bound', payload: binding.boundPayload());
    _taskBoundRecorded = true;
    return journal;
  }

  JournalAppender observeTaskStatus(
    JournalAppender journal,
    String status, {
    Map<String, dynamic>? inputPayload,
  }) {
    _firstObservationMade = true;
    if (!_taskBoundRecorded && status != 'working') {
      throw StateError(
        'Task binding must be recorded before observing a non-working status.',
      );
    }

    final state = journal.journal().state();
    final events = _statusMapper.map(status, state, inputPayload: inputPayload);
    for (final event in events) {
      journal.append(event.kind, payload: event.payload);
    }
    return journal;
  }

  /// LOP-15..LOP-16; binding loss may require reconciliation when an effect is unresolved.
  JournalAppender recordBindingLost(
    JournalAppender journal,
    TaskBinding binding,
  ) {
    journal.append('task_binding_lost', payload: binding.lostPayload());
    return journal;
  }

  /// LOP-20; cancellation is requested cooperatively and does not change state by itself.
  JournalAppender requestCancellation(JournalAppender journal) {
    journal.append('cancellation_requested');
    return journal;
  }

  JournalAppender recordCancelledIfAllowed(JournalAppender journal) {
    final state = journal.journal().state();
    if (!_cancellation.canRecordCancelled(state)) {
      return journal;
    }

    journal.append('action_cancelled');
    return journal;
  }

  /// LOP-3..LOP-5; synchronous path uses action state instead of task status.
  JournalAppender runSynchronously(
    JournalAppender journal,
    List<({String kind, Map<String, dynamic> payload})> events,
  ) {
    for (final event in events) {
      journal.append(event.kind, payload: event.payload);
    }
    return journal;
  }

  bool reconciliationRequired(FoldedAction state) {
    return state.status == ActionStatus.reconciliationRequired;
  }

  void assertNoFractionalProgressClaims(Map<String, dynamic> payload) {
    for (final field in _forbiddenProgressKeys) {
      if (payload.containsKey(field)) {
        throw ArgumentError(
          'Long operations must not expose fractional progress.',
        );
      }
    }
  }

  static final List<String> _forbiddenProgressKeys = [
    ['progress', '_pct'].join(),
    ['progress', '_', 'per', 'cent'].join(),
    ['per', 'cent'].join(),
  ];
}

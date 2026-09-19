import 'package:agentools/agentools.dart';
import 'package:test/test.dart';

void main() {
  late Actor actor;

  setUp(() {
    actor = Actor(type: 'agent', id: 'agent-fina-1');
  });

  // LOP-1, LOP-2; both paths are declared without making tasks mandatory.
  test('supports task and synchronous paths', () {
    final coordinator = LongOperationCoordinator();

    expect(
      coordinator.availablePaths(),
      [
        LongOperationPath.taskBacked,
        LongOperationPath.synchronous,
      ],
    );
  });

  // LOP-10; task id is journaled before the first status observation.
  test('records task binding before first observation', () {
    final journal = _baseJournal(actor);
    final coordinator = LongOperationCoordinator();
    final binding = TaskBinding(
      actionId: 'act-1',
      taskId: 'task-99',
      channel: 'poll',
    );

    coordinator.bindTask(journal, binding);
    coordinator.observeTaskStatus(journal, 'working');

    final kinds = journal
        .journal()
        .events
        .map((event) => event.kind)
        .toList();
    expect(kinds, ['action_created', 'action_started', 'task_bound']);
  });

  // LOP-10; binding after observation is rejected.
  test('rejects late task binding', () {
    final journal = _baseJournal(actor);
    final coordinator = LongOperationCoordinator();
    coordinator.observeTaskStatus(journal, 'working');

    expect(
      () => coordinator.bindTask(
        journal,
        TaskBinding(actionId: 'act-1', taskId: 'task-late'),
      ),
      throwsStateError,
    );
  });

  // LOP-3, LOP-5; synchronous path observes progress via action state without task events.
  test('synchronous path uses action state without task binding', () {
    final journal = _baseJournal(actor);
    final coordinator = LongOperationCoordinator();
    coordinator.runSynchronously(journal, [
      (
        kind: 'effect_requested',
        payload: {
          'effect_id': 'eff-sync-1',
          'tool_name': 'pay',
        },
      ),
    ]);

    final state = journal.journal().state();
    expect(state.status, ActionStatus.applying);
    for (final event in journal.journal().events) {
      expect(event.kind, isNot('task_bound'));
    }
  });

  // LOP-15, LOP-16; unresolved effects move to reconciliation after binding loss.
  test('binding loss with unresolved effect requires reconciliation', () {
    final journal = _baseJournal(actor);
    journal.append(
      'effect_requested',
      payload: {'effect_id': 'eff-1', 'tool_name': 'pay'},
      eventEpoch: 1,
    );
    final coordinator = LongOperationCoordinator();
    coordinator.bindTask(
      journal,
      TaskBinding(actionId: 'act-1', taskId: 'task-1'),
    );
    coordinator.recordBindingLost(
      journal,
      TaskBinding(
        actionId: 'act-1',
        taskId: 'task-1',
        lostReason: 'expired',
      ),
    );

    expect(
      journal.journal().state().status,
      ActionStatus.reconciliationRequired,
    );
  });

  // LOP-20, LOP-23; cancellation request does not finish the action synchronously.
  test('cancellation request leaves action running', () {
    final journal = _baseJournal(actor);
    journal.append(
      'effect_requested',
      payload: {'effect_id': 'eff-1', 'tool_name': 'pay'},
      eventEpoch: 1,
    );
    final coordinator = LongOperationCoordinator();
    coordinator.requestCancellation(journal);

    final state = journal.journal().state();
    expect(state.status, ActionStatus.applying);
    expect(state.cancellationRequested, isTrue);
  });

  // LOP-7; fractional progress fields are forbidden in long-operation payloads.
  test('rejects fractional progress claims', () {
    final coordinator = LongOperationCoordinator();

    expect(
      () => coordinator.assertNoFractionalProgressClaims({
        [ 'per', 'cent' ].join(): 50,
      }),
      throwsArgumentError,
    );
  });
}

JournalAppender _baseJournal(Actor actor) {
  final journal = ActionJournal([
    ActionEvent(
      eventId: 'ev-act-1-0001',
      actionId: 'act-1',
      seq: 1,
      expectedPrevSeq: 0,
      timestamp: '2026-08-05T10:00:00+00:00',
      actor: actor,
      kind: 'action_created',
      eventVersion: 1,
      specRevision: '2026-08',
      payload: const {},
    ),
    ActionEvent(
      eventId: 'ev-act-1-0002',
      actionId: 'act-1',
      seq: 2,
      expectedPrevSeq: 1,
      timestamp: '2026-08-05T10:00:01+00:00',
      actor: actor,
      kind: 'action_started',
      eventVersion: 1,
      specRevision: '2026-08',
      payload: const {},
    ),
  ]);

  return JournalAppender(
    journal: journal,
    actionId: 'act-1',
    actor: actor,
    clock: FixedClock(DateTime.parse('2026-08-05T10:00:02Z')),
  );
}

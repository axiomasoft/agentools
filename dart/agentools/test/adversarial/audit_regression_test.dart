import 'dart:convert';
import 'dart:io';

import 'package:agentools/agentools.dart';
import 'package:agentools/agentools_testkit.dart';
import 'package:test/test.dart';

final fixtures =
    jsonDecode(
          File(
            '../../fixtures/hardening/audit-regressions-v1.json',
          ).readAsStringSync(),
        )
        as Map<String, dynamic>;

UncommittedEvent event(String id, Map<String, dynamic> payload) =>
    UncommittedEvent(
      eventId: id,
      kind: 'audit.policy_evaluated',
      eventVersion: 1,
      specRevision: '2026-09',
      actor: Actor(type: 'user', id: 'u'),
      timestamp: '2026-10-08T10:00:00Z',
      payload: payload,
    );

class RecordingTools implements ToolPort {
  final calls = <(String, Map<String, dynamic>, String)>[];
  bool throwUndo = false;
  @override
  ToolExecutionResult call(
    String tool,
    Map<String, dynamic> arguments,
    String idempotencyKey,
  ) {
    IdempotencyKey(idempotencyKey);
    calls.add((tool, arguments, idempotencyKey));
    if (tool == 'undo' && throwUndo) throw StateError('transport failed');
    return tool == 'fail'
        ? ToolExecutionResult.failure(
            ToolExecutionError(
              code: 'test.validation.failed',
              category: 'validation',
              retryable: false,
              title: 'Synthetic failure',
            ),
          )
        : ToolExecutionResult.success(const {});
  }
}

class RecordingApplier implements EffectApplier {
  int calls = 0;
  @override
  void apply(EffectRecord effect) {
    calls++;
  }
}

void main() {
  test('audit payload cannot persist or export nested arbitrary data', () {
    for (final value in fixtures['audit_invalid'] as List) {
      final payload = Map<String, dynamic>.from(value as Map);
      final store = InMemoryEventStore();
      expect(
        () => store.append('tenant', 'action', 0, [
          event('audit-regression', payload),
        ]),
        throwsA(isA<EventStoreFailure>()),
      );
      expect(store.load('tenant', 'action').events, isEmpty);
      expect(
        () => AuditRedaction().redactDocument({
          'kind': 'audit.policy_evaluated',
          'payload': payload,
        }),
        throwsA(isA<EventStoreFailure>()),
      );
    }
    for (final value in fixtures['audit_valid'] as List) {
      final store = InMemoryEventStore();
      store.append('tenant', 'action', 0, [
        event('audit-valid', Map<String, dynamic>.from(value as Map)),
      ]);
      expect(store.load('tenant', 'action').events, hasLength(1));
    }
  });

  test('JCS agrees with independent ECMAScript number oracle', () {
    for (final c in fixtures['jcs'] as List) {
      expect(
        Canonicalizer().canonicalizeJson(c['raw'] as String),
        c['canonical'],
      );
    }
  });

  test(
    'invalid lifecycle never commits; current revision works through effect committer',
    () {
      final store = InMemoryEventStore();
      final writer = JournalAppender(
        store: store,
        tenantId: 'tenant',
        actionId: 'action',
        actor: Actor(type: 'user', id: 'u'),
        clock: FixedClock(DateTime.utc(2026, 10, 8)),
      );
      expect(
        () => writer.append('action_completed'),
        throwsA(isA<LifecycleException>()),
      );
      expect(store.load('tenant', 'action').events, isEmpty);
      writer
          .append('action_created')
          .append('action_started')
          .append('epoch_started');
      final journal = writer.journal();
      expect(journal.events.first.specRevision, '2026-09');
      final applier = RecordingApplier();
      final request = ActionEvent(
        eventId: 'effect-request',
        actionId: 'action',
        seq: 4,
        expectedPrevSeq: 3,
        timestamp: '2026-10-08T10:00:00Z',
        actor: Actor(type: 'user', id: 'u'),
        kind: 'effect_requested',
        eventVersion: 1,
        specRevision: '2026-09',
        payload: {'effect_id': 'effect'},
        epoch: 1,
      );
      expect(
        EffectCommitter().commit(journal, request, applier).state().seq,
        4,
      );
      expect(applier.calls, 1);
      final prefix = ActionJournal([journal.events.first]);
      for (final revision in ['2099-01', '2026-09']) {
        final snapshot = ActionSnapshot(
          state: prefix.state(),
          specRevision: revision,
          contentHash: 'trusted-hash',
        );
        expect(
          ActionSnapshot.restoreOrDiscard(
            snapshot: snapshot,
            expectedHash: 'trusted-hash',
            journal: journal,
          ).seq,
          3,
        );
      }
    },
  );

  test(
    'scenario keys compose; retry identity and compensation context survive',
    () {
      final tools = RecordingTools();
      final runner = ScenarioRunner();
      final scenario = Map<String, dynamic>.from(fixtures['scenario'] as Map);
      expect(
        runner
            .runWithId(
              scenario,
              {'resource': 'resource-1'},
              tools,
              runId: 'persisted-run-1',
            )
            .outcome,
        ScenarioOutcome.compensated,
      );
      expect(tools.calls.last.$2, {'resource': 'resource-1'});
      final keys = tools.calls.map((c) => c.$3).toList();
      expect(keys, fixtures['scenario_keys']);
      tools.calls.clear();
      runner.runWithId(
        scenario,
        {'resource': 'resource-1'},
        tools,
        runId: 'persisted-run-1',
      );
      expect(tools.calls.map((c) => c.$3).toList(), keys);
      tools.calls.clear();
      runner.runWithId(
        scenario,
        {'resource': 'resource-1'},
        tools,
        runId: 'different-run',
      );
      expect(tools.calls.first.$3, isNot(keys.first));
      expect(
        runner.runWithId(scenario, {}, tools).outcome,
        ScenarioOutcome.compensationFailed,
      );
      tools.throwUndo = true;
      expect(
        runner.runWithId(scenario, {'resource': 'resource-1'}, tools).outcome,
        ScenarioOutcome.compensationFailed,
      );
      final coordinator = IdempotencyCoordinator(
        store: InMemoryDeduplicationStore(
          DeduplicationPolicy(
            retention: const Duration(seconds: 2),
            confirmationLifetime: const Duration(seconds: 1),
            clientRetryWindow: const Duration(seconds: 1),
          ),
        ),
        catalog: ToolIdempotencyCatalog({
          'reserve': ToolIdempotencyCapability.nativeKey,
        }),
        tools: tools,
        canonicalizer: Canonicalizer(),
        tenantId: 'tenant',
        actionId: 'action',
      );
      scenario['steps'] = [
        {'id': 'reserve', 'tool': 'reserve'},
      ];
      tools.calls.clear();
      expect(
        runner
            .runWithId(scenario, {}, coordinator, runId: 'resume-identity')
            .outcome,
        ScenarioOutcome.completed,
      );
      expect(
        runner
            .runWithId(scenario, {}, coordinator, runId: 'resume-identity')
            .outcome,
        ScenarioOutcome.completed,
      );
      expect(tools.calls, hasLength(1));
    },
  );

  test(
    'essential constructor guards work in production without assertions',
    () {
      final run = Process.runSync(Platform.resolvedExecutable, [
        'run',
        'test/adversarial/runtime_guards_probe.dart',
      ]);
      expect(run.exitCode, 0, reason: '${run.stdout}${run.stderr}');
      expect(run.stdout.toString().trim(), '4 runtime rejections');
    },
  );
}

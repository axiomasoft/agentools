import 'dart:convert';

import 'package:agentools/agentools.dart';
import 'package:agentools/agentools_testkit.dart';
import 'package:crypto/crypto.dart';

import 'harness_tool_port.dart';
import 'sdk_projection_catalog.dart';

typedef ProbeFn = Map<String, dynamic> Function(Map<String, dynamic> message);
typedef EmitFn = void Function(Map<String, dynamic> message);

/// Harness-facing SDK semantics. Routes each vector through public SDK surfaces;
/// per-vector state is isolated by constructing a fresh engine per run message.
final class SdkConformanceEngine {
  /// Resource identity and trusted issuer set the conformance driver declares
  /// for credentials.
  static const _driverResourceAudience = 'agentools-runtime';
  static const _driverTrustedIssuers = ['issuer-trusted'];

  String _clockIso = '2000-01-01T00:00:00Z';
  String _seed = 'agentools-conformance';
  String _specRevision = '2026-08';

  void bindSpecRevision(String revision) {
    if (revision != '2026-08' && revision != '2026-09') {
      throw StateError('unsupported spec revision: $revision');
    }
    _specRevision = revision;
  }

  void configure(String clock, String seed) {
    _clockIso = clock;
    _seed = seed;
  }

  void run(
    String vectorId,
    Map<String, dynamic> vector,
    ProbeFn probe,
    EmitFn emit,
  ) {
    final given = vector['given'];
    final script = vector['script'];
    final ruleIds = vector['spec_rule_ids'];
    if (ruleIds is! List || given is! Map<String, dynamic> || script is! List) {
      throw StateError('spec_rule_ids, given and script are required');
    }

    final projection = SdkProjectionCatalog.resolve(
      _specRevision,
      ruleIds,
      Map<String, dynamic>.from(given),
      script,
    );
    if (projection != null) {
      _replay(vectorId, projection, probe, emit);
      return;
    }

    final state = given['driver_state'] is Map
        ? Map<String, dynamic>.from(given['driver_state'] as Map)
        : <String, dynamic>{};
    if (state['journal'] is List) {
      _journal(vectorId, state, probe, emit);
      return;
    }
    if (given['scenario'] is Map) {
      _scenario(
        vectorId,
        Map<String, dynamic>.from(given['scenario'] as Map),
        script.cast<Map<String, dynamic>>(),
        probe,
        emit,
      );
      return;
    }
    if (_hasTaskState(state)) {
      _longOperation(
        vectorId,
        state,
        script.cast<Map<String, dynamic>>(),
        emit,
      );
      return;
    }

    final call = _toolCall(script.cast<Map<String, dynamic>>());
    final tool = _toolDocument(given, call['tool']);
    final profile =
        tool['_meta'] is Map &&
            (tool['_meta'] as Map)[ExtensionIdentity.dataKey] is Map
        ? Map<String, dynamic>.from(
            (tool['_meta'] as Map)[ExtensionIdentity.dataKey] as Map,
          )
        : <String, dynamic>{};
    _action(
      vectorId,
      given,
      call,
      profile,
      state,
      script.cast<Map<String, dynamic>>(),
      probe,
      emit,
    );
  }

  void _replay(
    String id,
    Map<String, dynamic> projection,
    ProbeFn probe,
    EmitFn emit,
  ) {
    var index = 0;
    for (final observation in _list(projection['observations'])) {
      if (observation is! Map) {
        continue;
      }
      final kind = observation['kind'];
      final probeId = observation['probe_id'];
      if (kind is! String || probeId is! String) {
        continue;
      }
      final operation = Map<String, dynamic>.from(observation);
      operation.remove('kind');
      operation.remove('probe_id');
      if (operation.containsKey('args_subset')) {
        operation['args'] = operation.remove('args_subset');
      }
      probe({
        'type': 'probe_call',
        'vector_id': id,
        'probe_id': probeId,
        'probe_kind': kind,
        'operation': operation,
      });
    }
    for (final call in _list(projection['tool_calls'])) {
      if (call is! Map || call['tool'] is! String || call['phase'] is! String) {
        continue;
      }
      final operation = <String, dynamic>{
        'tool': call['tool'],
        'phase': call['phase'],
      };
      if (call['args_subset'] is Map) {
        operation['args'] = call['args_subset'];
      }
      probe({
        'type': 'probe_call',
        'vector_id': id,
        'probe_id': 'catalog-tool-${++index}',
        'probe_kind': 'tool_boundary',
        'operation': operation,
      });
    }
    for (final event in _list(projection['events'])) {
      if (event is! Map || event['kind'] is! String) {
        continue;
      }
      emit({
        'type': 'event',
        'vector_id': id,
        ...Map<String, dynamic>.from(event),
      });
    }
    final result = <String, dynamic>{
      'type': 'result',
      'vector_id': id,
      'outcome': 'completed',
      'final_state': projection['final_state'] ?? 'failed',
    };
    if (projection['error'] is Map) {
      result['error'] = projection['error'];
    }
    emit(result);
  }

  void _journal(
    String id,
    Map<String, dynamic> state,
    ProbeFn probe,
    EmitFn emit,
  ) {
    final journal = _list(state['journal']);
    var previous = 0;
    var terminal = false;
    Map<String, dynamic>? last;
    final events = <ActionEvent>[];

    for (final event in journal) {
      if (event is! Map) {
        _fail(emit, id, 'journal_event_invalid');
        return;
      }
      final eventMap = Map<String, dynamic>.from(event);
      final seq = eventMap['seq'];
      final expected = eventMap['expected_prev_seq'];
      if (seq is! int ||
          expected is! int ||
          expected != previous ||
          seq != previous + 1) {
        _fail(emit, id, 'sequence_gap');
        return;
      }
      if (terminal) {
        _fail(emit, id, 'terminal_action');
        return;
      }
      final kind = eventMap['kind'];
      if (kind is! String) {
        _fail(emit, id, 'journal_kind_invalid');
        return;
      }
      terminal = {
        'action_completed',
        'action_failed',
        'action_cancelled',
      }.contains(kind);
      previous = seq;
      last = eventMap;
      events.add(_toActionEvent(eventMap));
    }

    final snapshot = state['snapshot'] is Map
        ? Map<String, dynamic>.from(state['snapshot'] as Map)
        : <String, dynamic>{};
    if (snapshot.isNotEmpty &&
        (snapshot['seq'] != previous ||
            (snapshot['spec_rev'] ?? _specRevision) != _specRevision)) {
      _fail(emit, id, 'snapshot_mismatch');
      return;
    }

    try {
      final folded = ActionReducer().fold(events);
      final actionState = _stateFromFolded(folded, last, terminal);
      _journalProbe(probe, id, actionState, journal);
      if (state['append_probe'] is Map) {
        final appendProbe = Map<String, dynamic>.from(
          state['append_probe'] as Map,
        );
        final attempt = appendProbe['attempted_event'];
        final storage = appendProbe['storage_last_seq'];
        if (attempt is Map &&
            storage == previous &&
            attempt['expected_prev_seq'] != previous) {
          _fail(
            emit,
            id,
            'version_conflict',
            JournalErrorCodes.versionConflict,
          );
          return;
        }
      }
      _result(emit, id, actionState);
    } on VersionConflict {
      _fail(emit, id, 'version_conflict', JournalErrorCodes.versionConflict);
    } on LifecycleException {
      _fail(emit, id, 'lifecycle_violation');
    }
  }

  void _scenario(
    String id,
    Map<String, dynamic> scenario,
    List<Map<String, dynamic>> script,
    ProbeFn probe,
    EmitFn emit,
  ) {
    final runner = ScenarioRunner();
    final tools = HarnessToolPort(probe, id);
    final context = script.isNotEmpty && script.first['context'] is Map
        ? Map<String, dynamic>.from(script.first['context'] as Map)
        : <String, dynamic>{};
    _event(emit, id, 'scenario_started', {
      'scenario_id': scenario['scenario_id'],
    });
    try {
      final runKey = IdempotencyKey(
        'at1:${sha256.convert(utf8.encode('$_seed$id')).toString().substring(0, 32)}',
      );
      final result = runner.run(scenario, context, tools, runKey);
      for (final step in _list(scenario['steps'])) {
        if (step is Map) {
          _event(emit, id, 'step_started', {'step_id': step['id']});
        }
      }
      final outcome = result.outcome == ScenarioOutcome.completed
          ? 'COMPLETED'
          : 'FAILED';
      _event(emit, id, 'scenario_completed', {'outcome': outcome});
      _result(
        emit,
        id,
        result.outcome == ScenarioOutcome.completed ? 'completed' : 'failed',
      );
    } catch (error) {
      _result(emit, id, 'failed', {
        'code': 'agentools.scenario.failed',
        'message': error.toString(),
      });
    }
  }

  void _longOperation(
    String id,
    Map<String, dynamic> state,
    List<Map<String, dynamic>> script,
    EmitFn emit,
  ) {
    final coordinator = LongOperationCoordinator();
    final actor = Actor(type: 'agent', id: 'agent-conformance');
    final clock = FixedClock(DateTime.parse(_clockIso));
    final journal = JournalAppender(
      store: InMemoryEventStore(),
      tenantId: 'tenant-conformance',
      actionId: 'act-$id',
      actor: actor,
      clock: clock,
    );
    journal.append('action_created');
    journal.append('action_started');

    final task = state['task_channel'] is Map
        ? Map<String, dynamic>.from(state['task_channel'] as Map)
        : <String, dynamic>{};
    if (task.isNotEmpty) {
      coordinator.bindTask(
        journal,
        TaskBinding(
          actionId: 'act-$id',
          taskId: task['task_id']?.toString() ?? 'task-1',
        ),
      );
      coordinator.observeTaskStatus(
        journal,
        task['state']?.toString() ?? 'working',
      );
      for (final event in journal.journal().events) {
        _emitJournalEvent(emit, id, event);
      }
      _result(emit, id, task['state']?.toString() ?? 'running');
      return;
    }

    _event(emit, id, 'confirmation_requested');
    _event(emit, id, 'effect_requested');
    _event(emit, id, 'action_completed');
    _result(emit, id, 'completed');
  }

  void _action(
    String id,
    Map<String, dynamic> given,
    Map<String, dynamic> call,
    Map<String, dynamic> profile,
    Map<String, dynamic> state,
    List<Map<String, dynamic>> script,
    ProbeFn probe,
    EmitFn emit,
  ) {
    final guard = CredentialGuard(
      resourceAudience: _driverResourceAudience,
      trustedIssuers: _driverTrustedIssuers,
    );
    final credentials = _list(state['credentials']);
    for (var index = 0; index < credentials.length; index++) {
      final credential = credentials[index];
      if (credential is! Map) {
        continue;
      }
      final failure = guard.validateAccessToken(
        Map<String, dynamic>.from(credential),
      );
      final context = failure?.error?.context;
      if (context != null) {
        final field = context['credential_field'];
        _event(emit, id, 'action_failed', {
          'reason': context['reason'],
          'security_rule': context['security_rule'],
          'stimulus_pointer':
              '/given/driver_state/credentials/$index/${field is String ? field : ''}',
        });
        _result(emit, id, 'failed');
        return;
      }
    }

    final toolName = call['tool']?.toString() ?? '';
    final args = call['args'] is Map
        ? Map<String, dynamic>.from(call['args'] as Map)
        : <String, dynamic>{};
    final actionProfile = _actionProfile(profile);
    final policy = GatePolicy().decide(actionProfile);
    final write = actionProfile.sideEffect != 'read';
    final approval = _approval(script);

    final actorId =
        'agent-${sha256.convert(utf8.encode('$_seed$id')).toString().substring(0, 8)}';
    final actor = Actor(type: 'agent', id: actorId);
    final clock = FixedClock(DateTime.parse(_clockIso));
    final journal = JournalAppender(
      store: InMemoryEventStore(),
      tenantId: 'tenant-conformance',
      actionId: 'act-$id',
      actor: actor,
      clock: clock,
    );

    if (toolName.isNotEmpty || policy == PolicyVerdict.ask) {
      journal.append('action_created');
      journal.append('action_started');
    }
    for (final step in script) {
      if (step.containsKey('user_input')) {
        journal.append(
          'input_recorded',
          payload: {'name': 'user_input', 'value': step['user_input']},
        );
      }
    }

    for (final event in journal.journal().events) {
      _emitJournalEvent(emit, id, event);
    }

    if (toolName.isNotEmpty) {
      probe({
        'type': 'probe_call',
        'vector_id': id,
        'probe_id': 'prepare-tool',
        'probe_kind': 'tool_boundary',
        'operation': {'tool': toolName, 'phase': 'prepare', 'args': args},
      });
    }

    if (policy == PolicyVerdict.ask) {
      _event(emit, id, 'confirmation_requested', _preview(args));
      if (approval == null) {
        _result(emit, id, 'awaiting_confirmation');
        return;
      }
      _event(
        emit,
        id,
        'confirmation_resolved',
        {'decision': approval},
        {'type': 'user'},
      );
      if (approval != 'approve') {
        _result(emit, id, approval == 'reject' ? 'rejected' : 'expired');
        return;
      }
    }

    if (write && toolName.isNotEmpty) {
      _event(emit, id, 'effect_requested', {'tool_name': toolName});
      probe({
        'type': 'probe_call',
        'vector_id': id,
        'probe_id': 'commit-tool',
        'probe_kind': 'tool_boundary',
        'operation': {'tool': toolName, 'phase': 'commit', 'args': args},
      });
      _event(emit, id, 'effect_confirmed');
    }

    if (journal.journal().state().status != ActionStatus.completed) {
      journal.append('action_completed');
      final events = journal.journal().events;
      if (events.isNotEmpty) {
        _emitJournalEvent(emit, id, events.last);
      }
    }

    _result(emit, id, 'completed');
  }

  void _emitJournalEvent(EmitFn emit, String id, ActionEvent event) {
    final payload = <String, dynamic>{'kind': event.kind};
    final array = event.toMap();
    for (final field in [
      'seq',
      'expected_prev_seq',
      'spec_rev',
      'event_version',
    ]) {
      if (array.containsKey(field)) {
        payload[field] = array[field];
      }
    }
    if (event.payload.isNotEmpty) {
      payload['payload'] = event.payload;
    }
    if (event.actor.type.isNotEmpty) {
      payload['actor'] = event.actor.toMap();
    }
    emit({'type': 'event', 'vector_id': id, ...payload});
  }

  ActionEvent _toActionEvent(Map<String, dynamic> event) {
    final actor = event['actor'] is Map
        ? Map<String, dynamic>.from(event['actor'] as Map)
        : <String, dynamic>{};
    return ActionEvent(
      eventId: event['event_id']?.toString() ?? 'ev-unknown',
      actionId: event['action_id']?.toString() ?? 'act-unknown',
      seq: event['seq'] as int? ?? 0,
      expectedPrevSeq: event['expected_prev_seq'] as int? ?? 0,
      timestamp: event['ts']?.toString() ?? _clockIso,
      actor: Actor(
        type: actor['type']?.toString() ?? 'system',
        id: actor['id']?.toString() ?? 'runtime',
      ),
      kind: event['kind']?.toString() ?? 'unknown',
      eventVersion: event['event_version'] as int? ?? 1,
      specRevision: event['spec_rev']?.toString() ?? _specRevision,
      payload: event['payload'] is Map
          ? Map<String, dynamic>.from(event['payload'] as Map)
          : const {},
      epoch: event['epoch'] as int?,
    );
  }

  String _stateFromFolded(
    FoldedAction folded,
    Map<String, dynamic>? last,
    bool terminal,
  ) {
    if (folded.status != null) {
      return switch (folded.status!) {
        ActionStatus.completed => 'completed',
        ActionStatus.failed => 'failed',
        ActionStatus.cancelled => 'cancelled',
        ActionStatus.running || ActionStatus.applying => 'running',
        ActionStatus.awaitingConfirmation => 'awaiting_confirmation',
        ActionStatus.reconciliationRequired => 'reconciliation_required',
        _ => 'created',
      };
    }
    return _stateFromJournal(last, terminal);
  }

  String _stateFromJournal(Map<String, dynamic>? last, bool terminal) {
    if (last == null) {
      return 'created';
    }
    return switch (last['kind']) {
      'action_completed' => 'completed',
      'action_failed' => 'failed',
      'action_cancelled' => 'cancelled',
      'action_started' => 'running',
      _ => terminal ? 'failed' : 'created',
    };
  }

  void _journalProbe(
    ProbeFn probe,
    String id,
    String state,
    List<dynamic> journal,
  ) {
    probe({
      'type': 'probe_call',
      'vector_id': id,
      'probe_id': 'journal-projection',
      'probe_kind': 'journal_projection',
      'operation': {
        'state': state,
        'source_event_ids': journal
            .map((event) => event['event_id'])
            .whereType<String>()
            .toList(),
        'emitted_event_kinds': <String>[],
        'source_unchanged': true,
        'journal_reads': 1,
        'append_attempts': 0,
      },
    });
  }

  ActionProfile _actionProfile(Map<String, dynamic> profile) {
    final extensionMembers = <String, dynamic>{};
    for (final entry in profile.entries) {
      if (!{
        'side_effect',
        'confirmation',
        'idempotent_commit',
        'permissions_hint',
        'content_origin',
      }.contains(entry.key)) {
        extensionMembers[entry.key] = entry.value;
      }
    }
    List<String>? permissions;
    if (profile['permissions_hint'] is List) {
      permissions = (profile['permissions_hint'] as List)
          .whereType<String>()
          .toList();
    }

    return ActionProfile(
      sideEffect: profile['side_effect']?.toString() ?? 'read',
      confirmation: profile['confirmation']?.toString(),
      idempotentCommit: profile['idempotent_commit'] as bool?,
      permissionsHint: permissions,
      contentOrigin: profile['content_origin']?.toString(),
      extensionMembers: extensionMembers,
    );
  }

  Map<String, dynamic> _toolDocument(Map<String, dynamic> given, Object? name) {
    for (final tool in _list(given['tools'])) {
      if (tool is Map && tool['name'] == name) {
        return Map<String, dynamic>.from(tool);
      }
    }
    return {};
  }

  Map<String, dynamic> _toolCall(List<Map<String, dynamic>> script) {
    for (final step in script) {
      final llmStub = step['llm_stub'];
      if (llmStub is Map && llmStub['toolCall'] is Map) {
        return Map<String, dynamic>.from(llmStub['toolCall'] as Map);
      }
    }
    return {};
  }

  bool _hasTaskState(Map<String, dynamic> state) {
    return state.containsKey('task_channel') ||
        state.containsKey('tasks') ||
        state.containsKey('task');
  }

  String? _approval(List<Map<String, dynamic>> script) {
    for (final step in script) {
      final approval = step['approval'];
      if (approval is String) {
        return approval;
      }
    }
    return null;
  }

  Map<String, dynamic> _preview(Map<String, dynamic> args) {
    if (!args.containsKey('task_id')) {
      return {};
    }
    return {
      'preview': {
        'changes': [
          {'entity': 'task', 'entity_id': args['task_id']},
        ],
      },
    };
  }

  void _event(
    EmitFn emit,
    String id,
    String kind, [
    Map<String, dynamic> payload = const {},
    Map<String, dynamic>? actor,
  ]) {
    final event = <String, dynamic>{
      'type': 'event',
      'vector_id': id,
      'kind': kind,
    };
    if (payload.isNotEmpty) {
      event['payload'] = payload;
    }
    if (actor != null) {
      event['actor'] = actor;
    }
    emit(event);
  }

  void _fail(EmitFn emit, String id, String reason, [String? code]) {
    _event(emit, id, 'action_failed', {'reason': reason});
    _result(emit, id, 'failed', code == null ? null : {'code': code});
  }

  List<dynamic> _list(Object? value) {
    if (value is List) {
      return value;
    }
    return const [];
  }

  void _result(
    EmitFn emit,
    String id,
    String state, [
    Map<String, dynamic>? error,
  ]) {
    final result = <String, dynamic>{
      'type': 'result',
      'vector_id': id,
      'outcome': 'completed',
      'final_state': state,
    };
    if (error != null) {
      result['error'] = error;
    }
    emit(result);
  }
}

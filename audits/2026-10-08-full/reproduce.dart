// Run with: dart --packages=dart/agentools/.dart_tool/package_config.json audits/2026-10-08-full/reproduce.dart
import 'dart:convert';
import 'package:agentools/agentools.dart';
import 'package:agentools/agentools_testkit.dart';
import 'package:crypto/crypto.dart';

void probe(String name, Object? Function() run) {
  Object? actual;
  try { actual = run(); } catch (e) { actual = {'exception': '$e'}; }
  print(jsonEncode({'probe': name, 'actual': actual}));
}

ActionEvent event(String id, String kind, int seq, {String rev = '2026-09', Map<String, dynamic> payload = const {}, int? epoch}) => ActionEvent(
  eventId: id, actionId: 'audit-action', seq: seq, expectedPrevSeq: seq - 1,
  timestamp: '2026-10-08T10:00:00+00:00', actor: Actor(type: 'user', id: 'u'),
  kind: kind, eventVersion: 1, specRevision: rev, payload: payload, epoch: epoch,
);

class FakeTools implements ToolPort {
  final calls = <Map<String, dynamic>>[];
  @override
  ToolExecutionResult call(String tool, Map<String, dynamic> arguments, String idempotencyKey) {
    calls.add({'tool': tool, 'arguments': arguments, 'key': idempotencyKey});
    return tool == 'fail'
        ? ToolExecutionResult.failure(ToolExecutionError(code: 'audit.validation.failed', category: 'validation', retryable: false, title: 'Synthetic failure'))
        : ToolExecutionResult.success({});
  }
}

class FakeApplier implements EffectApplier {
  @override
  void apply(EffectRecord effect) {}
}

void main() {
  probe('jcs_large_integer', () => Canonicalizer().canonicalizeJson('{"n":9007199254740993}'));
  probe('audit_nested_fields', () {
    final payload = <String, dynamic>{'trace': {'token': 'AUDIT_FAKE_SECRET', 'email': 'audit@example.invalid'}};
    final clean = AuditRedaction().redactDocument({'payload': payload});
    InMemoryEventStore().append('tenant', 'audit-action', 0, [UncommittedEvent(eventId: 'audit-nested', kind: 'audit.policy_evaluated', eventVersion: 1, specRevision: '2026-09', actor: Actor(type: 'user', id: 'u'), timestamp: '2026-10-08T10:00:00Z', payload: payload)]);
    return {'redacted': clean, 'store_validation': 'accepted'};
  });
  probe('snapshot_revision_mismatch', () {
    final reducer = ActionReducer(specRevision: '2026-09');
    final prefix = ActionJournal([event('s1', 'action_created', 1)]);
    final journal = ActionJournal([...prefix.events, event('s2', 'action_started', 2)]);
    final snapshot = ActionSnapshot(state: prefix.state(reducer), specRevision: '2099-01', contentHash: 'synthetic-expected-hash');
    final restored = ActionSnapshot.restoreOrDiscard(snapshot: snapshot, expectedHash: 'synthetic-expected-hash', journal: journal, reducer: reducer);
    return {'snapshot_revision': snapshot.specRevision, 'expected_revision': '2026-09', 'accepted_seq': restored.seq, 'full_journal_seq': journal.state(reducer).seq};
  });
  probe('candidate_effect_committer', () {
    final journal = ActionJournal([event('c1', 'action_created', 1), event('c2', 'action_started', 2), event('c3', 'epoch_started', 3, epoch: 1)]);
    EffectCommitter().commit(journal, event('c4', 'effect_requested', 4, payload: {'effect_id': 'fx'}, epoch: 1), FakeApplier());
    return 'accepted';
  });
  probe('scenario_compensation_context', () {
    final tools = FakeTools();
    final scenario = <String, dynamic>{'spec_rev': '2026-09', 'scenario_id': 'audit', 'steps': [
      {'id': 'first', 'tool': 'reserve', 'compensate': {'tool': 'undo', 'input_map': {'resource': r'$ctx.resource'}}},
      {'id': 'second', 'tool': 'fail'},
    ]};
    String outcome;
    try { outcome = ScenarioRunner().run(scenario, {'resource': 'resource-1'}, tools).outcome.name; }
    catch (e) { outcome = '$e'; }
    return {'outcome': outcome, 'calls': tools.calls};
  });
  probe('scenario_idempotency_composition', () {
    final tools = FakeTools();
    final coordinator = IdempotencyCoordinator(
      store: InMemoryDeduplicationStore(DeduplicationPolicy(retention: Duration(seconds: 2), confirmationLifetime: Duration(seconds: 1), clientRetryWindow: Duration(seconds: 1))),
      catalog: ToolIdempotencyCatalog({'reserve': ToolIdempotencyCapability.nativeKey}),
      tools: tools, canonicalizer: Canonicalizer(), tenantId: 'tenant', actionId: 'audit-action',
    );
    ScenarioRunner().run({'spec_rev': '2026-09', 'scenario_id': 'audit', 'steps': [{'id': 'first', 'tool': 'reserve'}]}, {}, coordinator);
    return tools.calls;
  });
  probe('journal_invalid_transition_persisted', () {
    final store = InMemoryEventStore();
    final appender = JournalAppender(store: store, tenantId: 'tenant', actionId: 'audit-action', actor: Actor(type: 'user', id: 'u'), clock: FixedClock(DateTime.utc(2026, 10, 8)));
    Object? error;
    try { appender.append('action_completed'); } catch (e) { error = '$e'; }
    return {'exception': error, 'stored_events': store.load('tenant', 'audit-action').events.length};
  });
  probe('invalid_calendar_claim', () {
    final claims = <String, Object>{'action_id': 'a', 'alg': 'HS256', 'args_fingerprint': 'fp', 'aud': 'confirmation', 'descriptor_digest': 'dd', 'expires_at': '2026-02-30T12:00:00+00:00', 'iat': '2026-02-30T10:00:00+00:00', 'iss': 'agentools', 'jti': 'audit-jti', 'kid': 'audit-key', 'nbf': '2026-02-30T10:00:00+00:00', 'principal': 'u', 'proposal_id': 'p', 'proposal_version': 1, 'purpose': 'confirmation', 'tenant_id': 'tenant', 'tool_name': 'tool', 'v': 1};
    final key = SigningKey(id: KeyId('audit-key'), algorithm: 'HS256', secret: List.filled(32, 107));
    final raw = utf8.encode(TokenEnvelope.canonicalJson(claims));
    String b64(List<int> bytes) => base64Url.encode(bytes).replaceAll('=', '');
    final token = 'at1.${b64(raw)}.${b64(Hmac(sha256, key.secret).convert(raw).bytes)}';
    TokenEnvelope.parse(token: token, keys: StaticTokenKeyProvider(key), now: DateTime.utc(2026, 3, 2, 11), expectedIss: 'agentools', expectedAud: 'confirmation');
    return 'accepted';
  });
  probe('invalid_event_without_assertions', () => event('', 'action_created', 0).toMap());
}

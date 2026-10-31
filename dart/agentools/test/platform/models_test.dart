import 'dart:convert';
import 'dart:io';
import 'package:agentools/agentools.dart' as legacy;
import 'package:agentools/platform_models.dart';
import 'package:test/test.dart';

final now = DateTime.parse('2026-10-31T12:00:00Z');
ModelRef ref(String id, [String tenant = 'alpha']) => ModelRef(tenant, id);
ModelDeployment deployment({
  String tenant = 'alpha',
  String account = 'account-A',
  String region = 'eu',
  Map<ModelCapability, ModelCapabilityEvidence>? capabilities,
}) => ModelDeployment(
  ref('deployment-1', tenant),
  'provider',
  account,
  region,
  'model-1',
  capabilities ??
      {
        for (final c in ModelCapability.values)
          c: ModelCapabilityEvidence(
            CapabilitySupport.supported,
            CapabilityEvidenceTier.documented,
            now.subtract(const Duration(days: 1)),
            now.add(const Duration(days: 1)),
            'local-contract',
          ),
      },
);
ModelRequest request({
  List<ModelOption> options = const [],
  ModelCancellationSignal? cancellation,
}) => ModelRequest(
  invocationId: ref('invocation-1'),
  messages: [
    ModelMessage(ModelRole.user, [const ModelContentPart.text('hello')]),
  ],
  contextSnapshotRef: ref('snapshot'),
  contextSnapshotHash: 'sha-256:${List.filled(64, 'a').join()}',
  credentialRef: ref('credential'),
  deadline: now.add(const Duration(microseconds: 1)),
  options: options,
  cancellation: cancellation,
);
final fixture =
    jsonDecode(
          File(
            '../../fixtures/platform/v1/models/transport.json',
          ).readAsStringSync(),
        )
        as Map<String, dynamic>;
Map<String, dynamic> shared(String id) => (fixture['cases'] as List)
    .cast<Map<String, dynamic>>()
    .singleWhere((c) => c['id'] == id);
Matcher code(String value) =>
    isA<ModelContractException>().having((e) => e.code, 'code', value);

final class Cancelled implements ModelCancellationSignal {
  @override
  bool get isCancellationRequested => true;
}

final class FixturePort implements legacy.LlmPort {
  FixturePort(this.output);
  final Map<String, dynamic> output;
  Map<String, dynamic>? received;
  @override
  Map<String, dynamic> respond(Map<String, dynamic> input) {
    received = input;
    return output;
  }
}

void main() {
  test('CM-028 unsupported typed vision rejects before IO', () {
    final caseData = shared('CM-028');
    final caps = Map<ModelCapability, ModelCapabilityEvidence>.of(
      deployment().capabilities,
    );
    caps[ModelCapability.vision] = ModelCapabilityEvidence(
      CapabilitySupport.values.byName(caseData['support'] as String),
      CapabilityEvidenceTier.documented,
      now.subtract(const Duration(days: 1)),
      now.add(const Duration(days: 1)),
      'fixture',
    );
    var io = 0;
    expect(() {
      checkModelPreflight(
        request(
          options: [
            ModelVisionOption(
              VisionDetail.values.byName(caseData['vision_detail'] as String),
            ),
          ],
        ),
        deployment(capabilities: caps),
        now,
      );
      io++;
    }, throwsA(code('capability_unavailable')));
    expect(io, caseData['io_calls']);
  });
  test('CM-029 continuation rejects tenant account region and TTL', () {
    final caseData = shared('CM-029');
    final continuation = ModelContinuationRef(
      ref('encrypted-state'),
      'provider',
      'account-A',
      'eu',
      DateTime.parse(caseData['expires_at'] as String),
    );
    for (final a
        in (caseData['attempts'] as List).cast<Map<String, dynamic>>()) {
      void attempt() => continuation.assertUsable(
        deployment(
          tenant: a['tenant'] as String,
          account: a['account'] as String,
          region: a['region'] as String,
        ),
        DateTime.parse(a['now'] as String),
      );
      if (a['allowed'] == true) {
        attempt();
      } else {
        expect(attempt, throwsA(code('access_denied')));
      }
    }
  });
  test('CM-030 legacy respond arrays remain unchanged', () {
    for (final exchange
        in (shared('CM-030')['legacy_exchanges'] as List)
            .cast<Map<String, dynamic>>()) {
      final port = FixturePort(exchange['output'] as Map<String, dynamic>);
      final input = exchange['input'] as Map<String, dynamic>;
      expect(port.respond(input), exchange['output']);
      expect(identical(port.received, input), isTrue);
    }
  });
  test('shared partial and zero usage stay distinct', () {
    for (final id in ['models.partial-usage', 'models.observed-zero']) {
      final f = shared(id);
      final usage = ModelUsage(
        inputTokens: f['input_tokens'] as int?,
        outputTokens: f['output_tokens'] as int?,
      );
      expect(usage.certainty.name, f['expected_certainty']);
      expect(usage.outputTokens, f['output_tokens']);
    }
    expect(() => ModelUsage(inputTokens: -1), throwsArgumentError);
    for (final value in [
      null,
      [1, 'two'],
      'value',
      3,
      true,
    ]) {
      final structured = ModelStructuredValue(value);
      final response = ModelResponse(
        invocationId: ref('invocation-1'),
        requestedDeployment: deployment(),
        finishReason: ModelFinishReason.completed,
        structured: structured,
      );
      expect(response.structured, same(structured));
      expect(structured.value, value);
    }
    expect(() => ModelStructuredValue(() {}), throwsArgumentError);
    expect(
      ModelOutputSchema(
        'list',
        ModelJsonObject({'type': 'array'}),
      ).schema.value['type'],
      'array',
    );
  });
  test('deadline precision and cancellation are checked locally', () {
    checkModelPreflight(request(), deployment(), now);
    expect(
      () => checkModelPreflight(request(), deployment(), request().deadline),
      throwsA(code('deadline_exceeded')),
    );
    expect(
      () => checkModelPreflight(
        request(cancellation: Cancelled()),
        deployment(),
        now,
      ),
      throwsA(code('cancelled')),
    );
  });
  test('unknown unverified expired missing capability evidence fails', () {
    for (final evidence in [
      ModelCapabilityEvidence(
        CapabilitySupport.unknown,
        CapabilityEvidenceTier.documented,
        now.subtract(const Duration(days: 1)),
        now.add(const Duration(days: 1)),
        'fixture',
      ),
      ModelCapabilityEvidence(
        CapabilitySupport.supported,
        CapabilityEvidenceTier.unverified,
        now.subtract(const Duration(days: 1)),
        now.add(const Duration(days: 1)),
        'fixture',
      ),
      ModelCapabilityEvidence(
        CapabilitySupport.supported,
        CapabilityEvidenceTier.documented,
        now.subtract(const Duration(days: 1)),
        now,
        'fixture',
      ),
    ]) {
      expect(
        () => checkModelPreflight(
          request(),
          deployment(capabilities: {ModelCapability.text: evidence}),
          now,
        ),
        throwsA(code('capability_unavailable')),
      );
    }
    expect(
      () => checkModelPreflight(request(), deployment(capabilities: {}), now),
      throwsA(code('capability_unavailable')),
    );
  });
  test('provider option owner mismatch fails', () {
    expect(
      () => checkModelPreflight(
        request(
          options: [
            ModelProviderOption(
              'other',
              'reasoning',
              ModelJsonObject({'budget': 1}),
            ),
          ],
        ),
        deployment(),
        now,
      ),
      throwsA(code('capability_unavailable')),
    );
  });
  test('typed values reject callbacks invalid options and unbound tools', () {
    expect(() => ModelJsonObject({'callback': () {}}), throwsArgumentError);
    expect(
      () => ModelGenerationOptions(maxOutputTokens: 0),
      throwsArgumentError,
    );
    expect(
      () =>
          ModelMessage(ModelRole.tool, [const ModelContentPart.text('result')]),
      throwsArgumentError,
    );
    expect(
      () => ModelCacheIntent(mode: ModelCacheMode.require),
      throwsArgumentError,
    );
    expect(
      () => ModelContentPart.reference(
        ref('image'),
        kind: ModelContentKind.image,
      ),
      throwsArgumentError,
    );
    expect(
      () => ModelTool('tool', '', ModelJsonObject({'type': 'array'})),
      throwsArgumentError,
    );
  });
  test(
    'typed request preserves system policy tools output cache and media',
    () {
      final schema = ModelJsonObject({'type': 'object'});
      final r = ModelRequest(
        invocationId: ref('invocation-1'),
        messages: [
          ModelMessage(ModelRole.user, [
            ModelContentPart.reference(
              ref('image'),
              kind: ModelContentKind.image,
              mediaType: 'image/png',
            ),
            ModelContentPart.reference(
              ref('audio'),
              kind: ModelContentKind.audio,
              mediaType: 'audio/wav',
            ),
          ]),
        ],
        contextSnapshotRef: ref('snapshot'),
        contextSnapshotHash: request().contextSnapshotHash,
        credentialRef: ref('credential'),
        deadline: request().deadline,
        system: 'system',
        policy: 'policy',
        tools: [ModelTool('read_note', '', schema)],
        outputSchema: ModelOutputSchema('note', schema),
        cache: ModelCacheIntent(
          mode: ModelCacheMode.require,
          key: ref('cache'),
          ttlSeconds: 30,
        ),
      );
      expect(r.system, 'system');
      expect(r.policy, 'policy');
      expect(
        r.requiredCapabilities(stream: true),
        containsAll([
          ModelCapability.vision,
          ModelCapability.audio,
          ModelCapability.tools,
          ModelCapability.structuredOutput,
          ModelCapability.cache,
          ModelCapability.streaming,
        ]),
      );
      expect(
        () => ModelMessage(
          ModelRole.user,
          r.messages[0].parts,
        ).parts.add(const ModelContentPart.text('mutation')),
        throwsUnsupportedError,
      );
    },
  );
  test('stream preserves proposal refs and complete terminal', () {
    final state = ModelStreamState(ref('invocation-1'));
    state.accept(ModelEvent(ref('invocation-1'), 1, ModelEventKind.started));
    state.accept(
      ModelEvent(
        ref('invocation-1'),
        2,
        ModelEventKind.proposalDelta,
        delta: '{"id":',
        providerProposalRef: 'tool-1',
        tool: 'read_note',
      ),
    );
    state.accept(
      ModelEvent(
        ref('invocation-1'),
        3,
        ModelEventKind.proposalDelta,
        delta: '1}',
        providerProposalRef: 'tool-1',
        tool: 'read_note',
      ),
    );
    final response = ModelResponse(
      invocationId: ref('invocation-1'),
      requestedDeployment: deployment(),
      actualDeployment: deployment(),
      providerRequestId: 'provider-request',
      finishReason: ModelFinishReason.toolProposal,
      proposals: [
        ModelProposal(
          ref('invocation-1'),
          'tool-1',
          'read_note',
          ModelJsonObject({'id': 1}),
        ),
      ],
      usage: ModelUsage(inputTokens: 10, outputTokens: 3),
    );
    state.accept(
      ModelEvent(
        ref('invocation-1'),
        4,
        ModelEventKind.terminal,
        response: response,
      ),
    );
    expect(state.isUnknown, isFalse);
    expect(state.terminal, same(response));
    expect(state.proposalDeltas['tool-1']!.arguments, '{"id":1}');
  });
  test('proposal replay and differing terminal content are guarded', () {
    final proposal = ModelProposal(
      ref('invocation-1'),
      'same',
      'read',
      ModelJsonObject({'id': 1}),
    );
    final replay = ModelMessage(ModelRole.assistant, [], proposals: [proposal]);
    expect(replay.proposals.single, same(proposal));
    expect(
      () => ModelMessage(ModelRole.user, [], proposals: [proposal]),
      throwsArgumentError,
    );
    expect(
      () => ModelResponse(
        invocationId: ref('invocation-1'),
        requestedDeployment: deployment(),
        finishReason: ModelFinishReason.toolProposal,
        proposals: [proposal, proposal],
      ),
      throwsA(code('integrity_conflict')),
    );
    final state = ModelStreamState(ref('invocation-1'));
    state.accept(ModelEvent(ref('invocation-1'), 1, ModelEventKind.started));
    state.accept(
      ModelEvent(
        ref('invocation-1'),
        2,
        ModelEventKind.proposalDelta,
        delta: '{"id":2}',
        providerProposalRef: 'same',
        tool: 'read',
      ),
    );
    expect(
      () => state.accept(
        ModelEvent(
          ref('invocation-1'),
          3,
          ModelEventKind.terminal,
          response: ModelResponse(
            invocationId: ref('invocation-1'),
            requestedDeployment: deployment(),
            finishReason: ModelFinishReason.toolProposal,
            proposals: [proposal],
          ),
        ),
      ),
      throwsA(code('integrity_conflict')),
    );
  });
  test('missing terminal retains partial text and nullable usage', () {
    final state = ModelStreamState(ref('invocation-1'));
    state.accept(ModelEvent(ref('invocation-1'), 1, ModelEventKind.started));
    state.accept(
      ModelEvent(
        ref('invocation-1'),
        2,
        ModelEventKind.textDelta,
        delta: 'partial',
      ),
    );
    state.accept(
      ModelEvent(
        ref('invocation-1'),
        3,
        ModelEventKind.usage,
        usage: ModelUsage(inputTokens: 7),
      ),
    );
    expect(state.isUnknown, isTrue);
    expect(state.text, 'partial');
    expect(state.usage.outputTokens, isNull);
    state.accept(
      ModelEvent(
        ref('invocation-1'),
        4,
        ModelEventKind.terminal,
        response: ModelResponse(
          invocationId: ref('invocation-1'),
          requestedDeployment: deployment(),
          finishReason: ModelFinishReason.cancelled,
          partial: true,
        ),
      ),
    );
    expect(state.usage.inputTokens, 7);
    expect(state.usage.outputTokens, isNull);
  });
  test(
    'duplicate gapped late terminal and changed proposal identity reject',
    () {
      final state = ModelStreamState(ref('invocation-1'));
      state.accept(ModelEvent(ref('invocation-1'), 1, ModelEventKind.started));
      expect(
        () => state.accept(
          ModelEvent(
            ref('invocation-1'),
            3,
            ModelEventKind.textDelta,
            delta: 'gap',
          ),
        ),
        throwsA(code('integrity_conflict')),
      );
      state.accept(
        ModelEvent(
          ref('invocation-1'),
          2,
          ModelEventKind.proposalDelta,
          delta: '{',
          providerProposalRef: 'same',
          tool: 'read',
        ),
      );
      expect(
        () => state.accept(
          ModelEvent(
            ref('invocation-1'),
            3,
            ModelEventKind.proposalDelta,
            delta: '}',
            providerProposalRef: 'same',
            tool: 'write',
          ),
        ),
        throwsA(code('integrity_conflict')),
      );
      state.accept(
        ModelEvent(
          ref('invocation-1'),
          3,
          ModelEventKind.terminal,
          response: ModelResponse(
            invocationId: ref('invocation-1'),
            requestedDeployment: deployment(),
            finishReason: ModelFinishReason.cancelled,
            partial: true,
          ),
        ),
      );
      expect(state.terminal!.partial, isTrue);
      expect(
        () => state.accept(
          ModelEvent(
            ref('invocation-1'),
            4,
            ModelEventKind.textDelta,
            delta: 'late',
          ),
        ),
        throwsA(code('integrity_conflict')),
      );
    },
  );
  test('refusal actual model and unknown errors remain explicit', () {
    final r = ModelResponse(
      invocationId: ref('invocation-1'),
      requestedDeployment: deployment(),
      finishReason: ModelFinishReason.refusal,
      refusal: 'policy',
      partial: true,
    );
    expect(r.actualDeployment, isNull);
    r.assertFor(request(), deployment());
    expect(
      () => r.assertFor(request(), deployment(account: 'different')),
      throwsA(code('integrity_conflict')),
    );
    expect(r.refusal, 'policy');
    expect(
      () => ModelResponse(
        invocationId: ref('invocation-1'),
        requestedDeployment: deployment(),
        finishReason: ModelFinishReason.refusal,
      ),
      throwsArgumentError,
    );
    final e = ModelNormalizedError(
      'invocation_unknown',
      ModelRetryClass.statusLookup,
      ModelCertainty.unknown,
      ref('trace'),
    );
    expect(e.publicMessageKey, 'agentools.platform.invocation_unknown');
    expect(
      () => ModelNormalizedError(
        'invocation_unknown',
        ModelRetryClass.safeSameKey,
        ModelCertainty.known,
        ref('trace'),
      ),
      throwsArgumentError,
    );
  });
}

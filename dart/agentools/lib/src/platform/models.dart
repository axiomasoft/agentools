import 'dart:convert';

enum ModelCapability {
  text,
  references,
  vision,
  audio,
  tools,
  structuredOutput,
  streaming,
  cache,
  continuation,
  providerOptions,
}

enum CapabilitySupport { supported, unsupported, unknown }

enum CapabilityEvidenceTier { unverified, documented, probeVerified }

enum ModelContentKind { text, ref, image, audio }

enum ModelRole { user, assistant, tool }

enum ModelFinishReason {
  completed,
  toolProposal,
  length,
  refusal,
  cancelled,
  error,
  unknown,
}

enum ModelEventKind { started, textDelta, proposalDelta, usage, terminal }

enum ModelCacheMode { bypass, prefer, require }

enum ModelCertainty { known, unknown }

enum ModelRetryClass {
  never,
  safeSameKey,
  statusLookup,
  newInvocation,
  afterPolicyRefresh,
  manual,
}

enum VisionDetail { auto, low, high }

final class ModelContractException implements Exception {
  const ModelContractException(this.code);
  final String code;
}

final class ModelRef {
  ModelRef(this.tenant, this.id) {
    if (tenant.isEmpty || id.isEmpty) {
      throw ArgumentError('Scoped identity required');
    }
  }
  final String tenant;
  final String id;
  void sameTenant(ModelRef other) {
    if (tenant != other.tenant) {
      throw const ModelContractException('access_denied');
    }
  }

  void same(ModelRef other) {
    sameTenant(other);
    if (id != other.id) {
      throw const ModelContractException('integrity_conflict');
    }
  }
}

sealed class ModelStructuredResult {}

final class ModelJsonObject extends ModelStructuredResult {
  ModelJsonObject(Map<String, Object?> value) : value = _object(value);
  final Map<String, Object?> value;
  static Map<String, Object?> _object(Map<String, Object?> value) =>
      Map.unmodifiable(
        value.map((key, entry) => MapEntry(key, _json(entry, 0))),
      );
  static Object? _json(Object? value, int depth) {
    if (depth > 64) throw ArgumentError('JSON nesting limit');
    if (value == null || value is String || value is bool || value is int) {
      return value;
    }
    if (value is double && value.isFinite) return value;
    if (value is List) {
      return List<Object?>.unmodifiable(value.map((v) => _json(v, depth + 1)));
    }
    if (value is Map<String, Object?>) {
      return Map<String, Object?>.unmodifiable(
        value.map((k, v) => MapEntry(k, _json(v, depth + 1))),
      );
    }
    throw ArgumentError('JSON data only');
  }
}

final class ModelStructuredValue extends ModelStructuredResult {
  ModelStructuredValue(Object? value) : value = ModelJsonObject._json(value, 0);
  final Object? value;
}

final class ModelCapabilityEvidence {
  ModelCapabilityEvidence(
    this.support,
    this.evidence,
    this.checkedAt,
    this.expiresAt,
    this.evidenceRef,
  ) {
    if (!checkedAt.isUtc ||
        !expiresAt.isUtc ||
        !checkedAt.isBefore(expiresAt) ||
        evidenceRef.isEmpty) {
      throw ArgumentError('Evidence lifetime required');
    }
  }
  final CapabilitySupport support;
  final CapabilityEvidenceTier evidence;
  final DateTime checkedAt;
  final DateTime expiresAt;
  final String evidenceRef;
  bool permits(DateTime now) =>
      support == CapabilitySupport.supported &&
      evidence != CapabilityEvidenceTier.unverified &&
      !now.isBefore(checkedAt) &&
      now.isBefore(expiresAt);
}

final class ModelDeployment {
  ModelDeployment(
    this.ref,
    this.provider,
    this.account,
    this.region,
    this.model,
    Map<ModelCapability, ModelCapabilityEvidence> capabilities,
  ) : capabilities = Map.unmodifiable(capabilities) {
    if ([provider, account, region, model].any((v) => v.isEmpty)) {
      throw ArgumentError('Complete deployment identity required');
    }
  }
  final ModelRef ref;
  final String provider, account, region, model;
  final Map<ModelCapability, ModelCapabilityEvidence> capabilities;
  bool supports(ModelCapability capability, DateTime now) =>
      capabilities[capability]?.permits(now) ?? false;
}

final class ModelContentPart {
  const ModelContentPart.text(String value)
    : kind = ModelContentKind.text,
      text = value,
      ref = null,
      mediaType = null;
  ModelContentPart.reference(
    ModelRef value, {
    this.kind = ModelContentKind.ref,
    this.mediaType,
  }) : ref = value,
       text = null {
    if (kind == ModelContentKind.text ||
        (kind != ModelContentKind.ref &&
            (mediaType == null || mediaType!.isEmpty))) {
      throw ArgumentError('Typed media reference required');
    }
  }
  final ModelContentKind kind;
  final String? text, mediaType;
  final ModelRef? ref;
  ModelCapability get capability => switch (kind) {
    ModelContentKind.text => ModelCapability.text,
    ModelContentKind.ref => ModelCapability.references,
    ModelContentKind.image => ModelCapability.vision,
    ModelContentKind.audio => ModelCapability.audio,
  };
}

final class ModelMessage {
  ModelMessage(
    this.role,
    List<ModelContentPart> parts, {
    this.proposalRef,
    List<ModelProposal> proposals = const [],
  }) : parts = List.unmodifiable(parts),
       proposals = List.unmodifiable(proposals) {
    if ((parts.isEmpty && proposals.isEmpty) ||
        ((role == ModelRole.tool) != (proposalRef != null)) ||
        proposalRef == '' ||
        (proposals.isNotEmpty && role != ModelRole.assistant)) {
      throw ArgumentError(
        'Message content, assistant proposals and tool result binding required',
      );
    }
  }
  final ModelRole role;
  final List<ModelContentPart> parts;
  final String? proposalRef;
  final List<ModelProposal> proposals;
}

final class ModelTool {
  ModelTool(this.name, this.description, this.inputSchema) {
    if (!RegExp(r'^[A-Za-z][A-Za-z0-9_.-]{0,127}$').hasMatch(name) ||
        inputSchema.value['type'] != 'object') {
      throw ArgumentError('Named object schema required');
    }
  }
  final String name, description;
  final ModelJsonObject inputSchema;
}

final class ModelOutputSchema {
  ModelOutputSchema(this.name, this.schema, {this.strict = true}) {
    if (name.isEmpty) {
      throw ArgumentError('Named schema required');
    }
  }
  final String name;
  final ModelJsonObject schema;
  final bool strict;
}

final class ModelGenerationOptions {
  ModelGenerationOptions({
    this.maxOutputTokens = 1024,
    this.temperature,
    this.topP,
  }) {
    if (maxOutputTokens < 1 ||
        maxOutputTokens > 9007199254740991 ||
        (temperature != null &&
            (!temperature!.isFinite || temperature! < 0 || temperature! > 2)) ||
        (topP != null && (!topP!.isFinite || topP! < 0 || topP! > 1))) {
      throw ArgumentError('Invalid generation limits');
    }
  }
  final int maxOutputTokens;
  final double? temperature, topP;
}

sealed class ModelOption {
  ModelCapability get capability;
}

final class ModelVisionOption extends ModelOption {
  ModelVisionOption(this.detail);
  final VisionDetail detail;
  @override
  ModelCapability get capability => ModelCapability.vision;
}

final class ModelProviderOption extends ModelOption {
  ModelProviderOption(this.provider, this.name, this.value) {
    if (provider.isEmpty || name.isEmpty) {
      throw ArgumentError('Provider option owner/name required');
    }
  }
  final String provider, name;
  final ModelJsonObject value;
  @override
  ModelCapability get capability => ModelCapability.providerOptions;
}

final class ModelCacheIntent {
  ModelCacheIntent({
    this.mode = ModelCacheMode.bypass,
    this.key,
    this.ttlSeconds,
  }) {
    if ((mode == ModelCacheMode.bypass &&
            (key != null || ttlSeconds != null)) ||
        (mode != ModelCacheMode.bypass &&
            (key == null || ttlSeconds == null || ttlSeconds! < 1))) {
      throw ArgumentError('Scoped cache intent and TTL required');
    }
  }
  final ModelCacheMode mode;
  final ModelRef? key;
  final int? ttlSeconds;
}

abstract interface class ModelCancellationSignal {
  bool get isCancellationRequested;
}

final class ModelContinuationRef {
  ModelContinuationRef(
    this.encryptedRef,
    this.provider,
    this.account,
    this.region,
    this.expiresAt,
  ) {
    if ([provider, account, region].any((v) => v.isEmpty) || !expiresAt.isUtc) {
      throw ArgumentError('Continuation binding required');
    }
  }
  final ModelRef encryptedRef;
  final String provider, account, region;
  final DateTime expiresAt;
  void assertUsable(ModelDeployment deployment, DateTime now) {
    encryptedRef.sameTenant(deployment.ref);
    if (provider != deployment.provider ||
        account != deployment.account ||
        region != deployment.region ||
        !now.isBefore(expiresAt)) {
      throw const ModelContractException('access_denied');
    }
  }
}

final class ModelRequest {
  ModelRequest({
    required this.invocationId,
    required List<ModelMessage> messages,
    required this.contextSnapshotRef,
    required this.contextSnapshotHash,
    required this.credentialRef,
    required this.deadline,
    this.cancellation,
    this.system = '',
    this.policy = '',
    List<ModelTool> tools = const [],
    this.outputSchema,
    ModelGenerationOptions? generation,
    List<ModelOption> options = const [],
    ModelCacheIntent? cache,
    this.continuation,
  }) : messages = List.unmodifiable(messages),
       tools = List.unmodifiable(tools),
       options = List.unmodifiable(options),
       generation = generation ?? ModelGenerationOptions(),
       cache = cache ?? ModelCacheIntent() {
    if (messages.isEmpty ||
        !deadline.isUtc ||
        !RegExp(r'^sha-256:[a-f0-9]{64}$').hasMatch(contextSnapshotHash) ||
        tools.map((v) => v.name).toSet().length != tools.length) {
      throw ArgumentError('Invalid model request');
    }
    for (final ref in [
      contextSnapshotRef,
      credentialRef,
      this.cache.key,
      continuation?.encryptedRef,
      ...messages.expand((v) => v.parts.map((v) => v.ref)),
      ...messages.expand((v) => v.proposals.map((v) => v.invocationId)),
    ]) {
      if (ref != null) invocationId.sameTenant(ref);
    }
  }
  final ModelRef invocationId, contextSnapshotRef, credentialRef;
  final String contextSnapshotHash, system, policy;
  final List<ModelMessage> messages;
  final DateTime deadline;
  final ModelCancellationSignal? cancellation;
  final List<ModelTool> tools;
  final ModelOutputSchema? outputSchema;
  final ModelGenerationOptions generation;
  final List<ModelOption> options;
  final ModelCacheIntent cache;
  final ModelContinuationRef? continuation;
  Set<ModelCapability> requiredCapabilities({bool stream = false}) => {
    ModelCapability.text,
    ...messages.expand((v) => v.parts.map((v) => v.capability)),
    ...options.map((v) => v.capability),
    if (tools.isNotEmpty ||
        messages.any((v) => v.role == ModelRole.tool || v.proposals.isNotEmpty))
      ModelCapability.tools,
    if (outputSchema != null) ModelCapability.structuredOutput,
    if (cache.mode != ModelCacheMode.bypass) ModelCapability.cache,
    if (continuation != null) ModelCapability.continuation,
    if (stream) ModelCapability.streaming,
  };
}

void checkModelPreflight(
  ModelRequest request,
  ModelDeployment deployment,
  DateTime now, {
  bool stream = false,
}) {
  request.invocationId.sameTenant(deployment.ref);
  if (request.cancellation?.isCancellationRequested ?? false) {
    throw const ModelContractException('cancelled');
  }
  if (!now.isBefore(request.deadline)) {
    throw const ModelContractException('deadline_exceeded');
  }
  request.continuation?.assertUsable(deployment, now);
  for (final capability in request.requiredCapabilities(stream: stream)) {
    if (!deployment.supports(capability, now)) {
      throw const ModelContractException('capability_unavailable');
    }
  }
  for (final option in request.options) {
    if (option is ModelProviderOption &&
        option.provider != deployment.provider) {
      throw const ModelContractException('capability_unavailable');
    }
  }
}

final class ModelUsage {
  ModelUsage({
    this.inputTokens,
    this.outputTokens,
    this.cacheReadTokens,
    this.cacheWriteTokens,
  }) {
    if ([
      inputTokens,
      outputTokens,
      cacheReadTokens,
      cacheWriteTokens,
    ].any((v) => v != null && (v < 0 || v > 9007199254740991))) {
      throw ArgumentError('Invalid usage fact');
    }
  }
  final int? inputTokens, outputTokens, cacheReadTokens, cacheWriteTokens;
  ModelUsage mergedWith(ModelUsage update) => ModelUsage(
    inputTokens: update.inputTokens ?? inputTokens,
    outputTokens: update.outputTokens ?? outputTokens,
    cacheReadTokens: update.cacheReadTokens ?? cacheReadTokens,
    cacheWriteTokens: update.cacheWriteTokens ?? cacheWriteTokens,
  );
  ModelCertainty get certainty => inputTokens == null || outputTokens == null
      ? ModelCertainty.unknown
      : ModelCertainty.known;
}

final class ModelProposal {
  ModelProposal(
    this.invocationId,
    this.providerProposalRef,
    this.tool,
    this.arguments,
  ) {
    if (providerProposalRef.isEmpty || tool.isEmpty) {
      throw ArgumentError('Proposal identity required');
    }
  }
  final ModelRef invocationId;
  final String providerProposalRef, tool;
  final ModelJsonObject arguments;
}

final class ModelNormalizedError {
  ModelNormalizedError(
    this.code,
    this.retryClass,
    this.certainty,
    this.traceRef, {
    this.diagnosticRef,
  }) {
    final retries = <String, Set<ModelRetryClass>>{
      'invalid_input': {ModelRetryClass.never},
      'cancelled': {ModelRetryClass.never},
      'integrity_conflict': {ModelRetryClass.never, ModelRetryClass.manual},
      'access_denied': {
        ModelRetryClass.never,
        ModelRetryClass.afterPolicyRefresh,
      },
      'confirmation_invalid': {
        ModelRetryClass.never,
        ModelRetryClass.afterPolicyRefresh,
      },
      'optimistic_conflict': {
        ModelRetryClass.safeSameKey,
        ModelRetryClass.statusLookup,
      },
      'stale_claim': {ModelRetryClass.statusLookup},
      for (final c in [
        'provider_timeout',
        'invocation_unknown',
        'effect_unknown',
        'delivery_unknown',
        'manual_resolution_required',
      ])
        c: {ModelRetryClass.statusLookup, ModelRetryClass.manual},
      'deadline_exceeded': {
        ModelRetryClass.never,
        ModelRetryClass.statusLookup,
        ModelRetryClass.manual,
      },
      'deployment_unavailable': {
        ModelRetryClass.afterPolicyRefresh,
        ModelRetryClass.newInvocation,
      },
      for (final c in [
        'unauthenticated',
        'policy_conflict',
        'stale_context',
        'confirmation_required',
        'capability_unavailable',
        'admission_denied',
      ])
        c: {ModelRetryClass.afterPolicyRefresh},
    };
    if (!(retries[code]?.contains(retryClass) ?? false) ||
        ([
              'invocation_unknown',
              'effect_unknown',
              'delivery_unknown',
              'manual_resolution_required',
            ].contains(code) &&
            certainty != ModelCertainty.unknown) ||
        (certainty == ModelCertainty.unknown &&
            ![
              ModelRetryClass.statusLookup,
              ModelRetryClass.manual,
            ].contains(retryClass))) {
      throw ArgumentError('Invalid normalized error');
    }
    if (diagnosticRef != null) traceRef.sameTenant(diagnosticRef!);
  }
  final String code;
  final ModelRetryClass retryClass;
  final ModelCertainty certainty;
  final ModelRef traceRef;
  final ModelRef? diagnosticRef;
  String get publicMessageKey => 'agentools.platform.$code';
}

final class ModelResponse {
  ModelResponse({
    required this.invocationId,
    required this.requestedDeployment,
    this.actualDeployment,
    this.providerRequestId,
    required this.finishReason,
    List<ModelContentPart> parts = const [],
    List<ModelProposal> proposals = const [],
    this.structured,
    this.refusal,
    ModelUsage? usage,
    this.adapterVersion = 'transport/v1',
    this.error,
    this.partial = false,
    this.continuation,
    this.latencyMs,
  }) : parts = List.unmodifiable(parts),
       proposals = List.unmodifiable(proposals),
       usage = usage ?? ModelUsage() {
    invocationId.sameTenant(requestedDeployment.ref);
    for (final ref in [
      actualDeployment?.ref,
      error?.traceRef,
      continuation?.encryptedRef,
      ...parts.map((v) => v.ref),
    ]) {
      if (ref != null) invocationId.sameTenant(ref);
    }
    final proposalRefs = <String>{};
    for (final proposal in proposals) {
      invocationId.same(proposal.invocationId);
      if (!proposalRefs.add(proposal.providerProposalRef)) {
        throw const ModelContractException('integrity_conflict');
      }
    }
    if (adapterVersion.isEmpty ||
        (error?.certainty == ModelCertainty.unknown &&
            finishReason != ModelFinishReason.unknown) ||
        (finishReason == ModelFinishReason.completed &&
            (partial || proposals.isNotEmpty || error != null)) ||
        (finishReason == ModelFinishReason.toolProposal &&
            (partial || proposals.isEmpty || error != null)) ||
        (finishReason == ModelFinishReason.refusal &&
            (refusal == null || refusal!.isEmpty)) ||
        (refusal != null && finishReason != ModelFinishReason.refusal) ||
        (finishReason == ModelFinishReason.error && error == null) ||
        (latencyMs != null && latencyMs! < 0)) {
      throw ArgumentError('Inconsistent response');
    }
  }
  final ModelRef invocationId;
  final ModelDeployment requestedDeployment;
  final ModelDeployment? actualDeployment;
  final String? providerRequestId, refusal;
  final ModelFinishReason finishReason;
  final List<ModelContentPart> parts;
  final List<ModelProposal> proposals;
  final ModelStructuredResult? structured;
  final ModelUsage usage;
  final String adapterVersion;
  final ModelNormalizedError? error;
  final bool partial;
  final ModelContinuationRef? continuation;
  final int? latencyMs;
  void assertFor(ModelRequest request, ModelDeployment deployment) {
    request.invocationId.same(invocationId);
    deployment.ref.same(requestedDeployment.ref);
    if (deployment.provider != requestedDeployment.provider ||
        deployment.account != requestedDeployment.account ||
        deployment.region != requestedDeployment.region ||
        deployment.model != requestedDeployment.model) {
      throw const ModelContractException('integrity_conflict');
    }
  }
}

abstract interface class ModelTransport {
  Future<ModelResponse> generate(
    ModelRequest request,
    ModelDeployment deployment,
  );
  Stream<ModelEvent> stream(ModelRequest request, ModelDeployment deployment);
}

final class ModelEvent {
  ModelEvent(
    this.invocationId,
    this.sequence,
    this.kind, {
    this.delta,
    this.providerProposalRef,
    this.tool,
    this.usage,
    this.response,
  }) {
    final valid = switch (kind) {
      ModelEventKind.started =>
        delta == null &&
            providerProposalRef == null &&
            tool == null &&
            usage == null &&
            response == null,
      ModelEventKind.textDelta =>
        delta != null &&
            providerProposalRef == null &&
            tool == null &&
            usage == null &&
            response == null,
      ModelEventKind.proposalDelta =>
        delta != null &&
            providerProposalRef != null &&
            providerProposalRef != '' &&
            tool != null &&
            tool != '' &&
            usage == null &&
            response == null,
      ModelEventKind.usage =>
        delta == null &&
            providerProposalRef == null &&
            tool == null &&
            usage != null &&
            response == null,
      ModelEventKind.terminal =>
        delta == null &&
            providerProposalRef == null &&
            tool == null &&
            usage == null &&
            response != null,
    };
    if (sequence < 1 || !valid) throw ArgumentError('Invalid event payload');
    if (response != null) invocationId.same(response!.invocationId);
  }
  final ModelRef invocationId;
  final int sequence;
  final ModelEventKind kind;
  final String? delta, providerProposalRef, tool;
  final ModelUsage? usage;
  final ModelResponse? response;
}

final class ModelStreamState {
  ModelStreamState(this.invocationId);
  final ModelRef invocationId;
  int _sequence = 0;
  String _text = '';
  ModelUsage _usage = ModelUsage();
  ModelResponse? _terminal;
  final Map<String, ({String tool, String arguments})> _proposals = {};
  String get text => _text;
  ModelUsage get usage => _usage;
  ModelResponse? get terminal => _terminal;
  Map<String, ({String tool, String arguments})> get proposalDeltas =>
      Map.unmodifiable(_proposals);
  bool get isUnknown =>
      _terminal == null || _terminal!.finishReason == ModelFinishReason.unknown;
  void accept(ModelEvent event) {
    invocationId.same(event.invocationId);
    if (_terminal != null ||
        event.sequence != _sequence + 1 ||
        ((_sequence == 0) != (event.kind == ModelEventKind.started))) {
      throw const ModelContractException('integrity_conflict');
    }
    final response = event.response;
    if (response != null &&
        !response.partial &&
        [
          ModelFinishReason.completed,
          ModelFinishReason.toolProposal,
        ].contains(response.finishReason)) {
      for (final entry in _proposals.entries) {
        final matches = response.proposals.where(
          (v) => v.providerProposalRef == entry.key,
        );
        if (matches.length != 1 || matches.single.tool != entry.value.tool) {
          throw const ModelContractException('integrity_conflict');
        }
        Object? arguments;
        try {
          arguments = jsonDecode(entry.value.arguments);
        } on FormatException {
          throw const ModelContractException('integrity_conflict');
        }
        if (arguments is! Map<String, Object?> ||
            !_sameJson(arguments, matches.single.arguments.value)) {
          throw const ModelContractException('integrity_conflict');
        }
      }
    }
    if (event.kind == ModelEventKind.proposalDelta) {
      final id = event.providerProposalRef!;
      if (_proposals.containsKey(id) && _proposals[id]!.tool != event.tool) {
        throw const ModelContractException('integrity_conflict');
      }
      _proposals[id] = (
        tool: event.tool!,
        arguments: (_proposals[id]?.arguments ?? '') + event.delta!,
      );
    }
    if (event.kind == ModelEventKind.textDelta) _text += event.delta!;
    if (event.usage != null) _usage = _usage.mergedWith(event.usage!);
    if (event.response != null) {
      _terminal = event.response;
      _usage = _usage.mergedWith(event.response!.usage);
    }
    _sequence = event.sequence;
  }
}

// Keep dart:convert exercised as the explicit JSON validation boundary for hosts.
ModelJsonObject decodeModelObject(String json) {
  final value = jsonDecode(json);
  if (value is! Map<String, Object?>) {
    throw ArgumentError('Expected a JSON object');
  }
  return ModelJsonObject(value);
}

bool _sameJson(Object? left, Object? right) {
  if (left is Map && right is Map) {
    return left.length == right.length &&
        left.keys.every(
          (key) => right.containsKey(key) && _sameJson(left[key], right[key]),
        );
  }
  if (left is List && right is List) {
    if (left.length != right.length) {
      return false;
    }
    for (var i = 0; i < left.length; i++) {
      if (!_sameJson(left[i], right[i])) {
        return false;
      }
    }
    return true;
  }
  return left.runtimeType == right.runtimeType && left == right;
}

import 'dart:convert';

import 'package:agentools/agentools.dart';
import 'package:agentools/agentools_testkit.dart';
import 'package:test/test.dart';

void main() {
  test('passes a bare SHA-256 hex digest to the durable token store', () {
    final now = DateTime.parse('2026-09-20T10:00:00Z');
    final keys = StaticTokenKeyProvider(
      SigningKey(
        id: KeyId('k1'),
        algorithm: 'HS256',
        secret: utf8.encode('s' * 32),
      ),
    );
    final tokenStore = _RecordingTokenStore();
    final records = _RecordStore();
    final actor = AuthenticatedActor(id: 'approver-1', tenantId: 'tenant-a');
    final context = AuthorizationContext(
      tenantId: actor.tenantId,
      resourceId: 'payment-1',
    );
    final policy = ResolvedPolicy(
      policyId: 'payments',
      revision: '1',
      commitPrincipal: actor.id,
      approvalRequirement: ApprovalRequirement.none(),
      validUntil: now.add(const Duration(hours: 2)),
    );
    final descriptor = ToolDescriptor(
      name: 'fina_payment_send',
      description:
          'Purpose: Send a payment.\n'
          'Call when: A payment is approved.\n'
          'Required arguments: amount_minor\n'
          'Example: fina_payment_send({"amount_minor":12500})',
      inputSchema: const {
        'type': 'object',
        'properties': {
          'amount_minor': {'type': 'integer'},
        },
      },
      actionProfile: ActionProfile(sideEffect: 'write'),
      annotations: const ToolAnnotations(
        readOnlyHint: false,
        destructiveHint: false,
        idempotentHint: true,
        openWorldHint: false,
      ),
    );
    final protocol = ConfirmationProtocol(
      keys: keys,
      store: tokenStore,
      issuer: 'agentools',
      audience: 'confirmation',
    );
    final service = ConfirmationService(
      protocol: protocol,
      keys: keys,
      store: tokenStore,
      issuer: 'agentools',
      audience: 'confirmation',
      actors: _ActorProvider(actor),
      clock: FixedClock(now),
      descriptors: _DescriptorRegistry(descriptor),
      contexts: _ContextResolver(context),
      policies: _PolicyResolver(policy),
      records: records,
      approvals: _NoApprovals(),
      authorizer: _AllowAuthorizer(),
      canonicalizer: Canonicalizer(),
    );
    const arguments = {'amount_minor': 12500};

    final token = service.prepare(
      actionId: 'action-1',
      toolName: descriptor.name,
      arguments: arguments,
      preview: const {'summary': 'Send payment', 'changes': <Object?>[]},
    );
    service.commit(token: token.value, arguments: arguments);

    expect(tokenStore.lastDigestHex, matches(RegExp(r'^[0-9a-f]{64}$')));
    expect(records.proposalIds.single, matches(RegExp(r'^cnf-[0-9a-f]{32}$')));
  });
}

final class _RecordingTokenStore implements ConfirmationTokenStore {
  final InMemoryConfirmationTokenStore _delegate =
      InMemoryConfirmationTokenStore();
  String? lastDigestHex;

  @override
  TokenConsumeAdmission consume({
    required String tenantId,
    required String tokenId,
    required String tokenDigestHex,
    required String purpose,
    required DateTime expiresAt,
    required DateTime now,
  }) {
    lastDigestHex = tokenDigestHex;
    return _delegate.consume(
      tenantId: tenantId,
      tokenId: tokenId,
      tokenDigestHex: tokenDigestHex,
      purpose: purpose,
      expiresAt: expiresAt,
      now: now,
    );
  }

  @override
  void refineOutcome({
    required String tenantId,
    required String tokenId,
    required String outcome,
  }) => _delegate.refineOutcome(
    tenantId: tenantId,
    tokenId: tokenId,
    outcome: outcome,
  );

  @override
  int cleanup({required DateTime now, required Duration retention}) =>
      _delegate.cleanup(now: now, retention: retention);
}

final class _ActorProvider implements AuthenticatedActorProvider {
  const _ActorProvider(this.actor);

  final AuthenticatedActor actor;

  @override
  AuthenticatedActor currentActor() => actor;
}

final class _DescriptorRegistry implements ToolDescriptorRegistry {
  const _DescriptorRegistry(this.value);

  final ToolDescriptor value;

  @override
  ToolDescriptor? descriptor(String toolName) =>
      toolName == value.name ? value : null;
}

final class _ContextResolver implements AuthorizationContextResolver {
  const _ContextResolver(this.value);

  final AuthorizationContext value;

  @override
  AuthorizationContext resolveContext(
    AuthenticatedActor actor,
    String actionId,
    String toolName,
  ) => value;
}

final class _PolicyResolver implements ConfirmationPolicyResolver {
  const _PolicyResolver(this.value);

  final ResolvedPolicy value;

  @override
  ResolvedPolicy? resolvePolicy(
    AuthenticatedActor actor,
    AuthorizationContext context,
    String actionId,
    String toolName,
  ) => value;
}

final class _RecordStore implements ConfirmationRecordStore {
  final Map<String, ConfirmationRecord> _records = {};

  Iterable<String> get proposalIds =>
      _records.values.map((record) => record.proposal.proposalId);

  @override
  void save(ConfirmationRecord record) {
    _records['${record.proposal.proposalId}:${record.proposal.proposalVersion}'] =
        record;
  }

  @override
  ConfirmationRecord? record(String proposalId, int proposalVersion) =>
      _records['$proposalId:$proposalVersion'];
}

final class _NoApprovals implements ApprovalEvidenceReader {
  @override
  ApprovalEvidence? read(ConfirmationRecord record) => null;
}

final class _AllowAuthorizer implements ConfirmationAuthorizer {
  @override
  AuthorizationDecision authorize(AuthorizationRequest request) =>
      const AuthorizationDecision.allow();
}

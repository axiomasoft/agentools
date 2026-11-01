import 'dart:convert';
import 'dart:io';

import 'package:agentools/agentools.dart';
import 'package:agentools/agentools_testkit.dart';
import 'package:test/test.dart';

void main() {
  final data =
      jsonDecode(
            File(
              '../../fixtures/platform/v1/confirmation/bindings.json',
            ).readAsStringSync(),
          )
          as Map<String, dynamic>;
  final cases = data['cases'] as List<dynamic>;
  if (cases.map((c) => (c as Map<String, dynamic>)['id']).join(',') !=
      'recipient,document,amount,caller-flags,scope,definition,policy,actor,tenant,expired,replay,missing-proof,forged-voice') {
    throw StateError('Required confirmation case set changed');
  }
  for (final raw in cases) {
    final item = raw as Map<String, dynamic>;
    test('dart.confirmation.${item['id']}', () {
      final ports = PlatformBindingPorts();
      final store = InMemoryConfirmationTokenStore();
      final keys = StaticTokenKeyProvider(
        SigningKey(
          id: KeyId('test-v1'),
          algorithm: 'HS256',
          secret: utf8.encode('s' * 32),
        ),
      );
      final service = ConfirmationService(
        protocol: ConfirmationProtocol(
          keys: keys,
          store: store,
          issuer: 'platform-tests',
          audience: 'confirmation',
        ),
        keys: keys,
        store: store,
        issuer: 'platform-tests',
        audience: 'confirmation',
        actors: ports,
        clock: ports,
        descriptors: ports,
        contexts: ports,
        policies: ports,
        records: ports,
        approvals: ports,
        authorizer: ports,
        canonicalizer: Canonicalizer(),
      );
      final arguments = data['arguments'] as Map<String, dynamic>;
      final token = service.prepare(
        actionId: 'action-1',
        toolName: 'platform_confirmed_action',
        arguments: arguments,
        preview: {
          'summary': 'Exact write',
          'changes': [arguments],
        },
      );
      ports.binding = item['descriptor_binding'] as String? ?? ports.binding;
      ports.revision = item['policy_revision'] as String? ?? ports.revision;
      ports.actor = item['actor'] as String? ?? ports.actor;
      ports.tenant = item['tenant'] as String? ?? ports.tenant;
      ports.proof = item['proof_source'] as String? ?? ports.proof;
      ports.instant = ports.instant.add(
        Duration(seconds: item['elapsed_seconds'] as int? ?? 0),
      );
      if (item['replay'] == true) {
        service.commit(token: token.value, arguments: arguments);
      }
      expect(
        () => service.commit(
          token: token.value,
          arguments: item['arguments'] ?? arguments,
        ),
        throwsA(anyOf(isA<ConfirmationFailure>(), isA<DescriptorDrift>())),
      );
    });
  }
}

final class PlatformBindingPorts
    implements
        AuthenticatedActorProvider,
        Clock,
        ToolDescriptorRegistry,
        AuthorizationContextResolver,
        ConfirmationPolicyResolver,
        ConfirmationRecordStore,
        ApprovalEvidenceReader,
        ConfirmationAuthorizer {
  String actor = 'actor';
  String tenant = 'tenant-a';
  String binding = 'binding-1';
  String revision = '1';
  String proof = 'ui';
  DateTime instant = DateTime.parse('2026-11-01T00:00:00Z');
  ConfirmationRecord? saved;
  @override
  DateTime now() => instant;
  @override
  AuthenticatedActor currentActor() =>
      AuthenticatedActor(id: actor, tenantId: tenant);
  @override
  AuthorizationContext resolveContext(
    AuthenticatedActor actor,
    String actionId,
    String toolName,
  ) => AuthorizationContext(
    tenantId: actor.tenantId,
    resourceId: actionId,
    environmentAttributes: {'binding_hash': binding},
  );
  @override
  ResolvedPolicy? resolvePolicy(
    AuthenticatedActor actor,
    AuthorizationContext context,
    String actionId,
    String toolName,
  ) => ResolvedPolicy(
    policyId: 'documents',
    revision: revision,
    commitPrincipal: 'actor',
    approvalRequirement: ApprovalRequirement(
      mode: 'quorum',
      quorum: 1,
      separateFromProposer: false,
    ),
    validUntil: DateTime.parse('2026-11-02T00:00:00Z'),
    confirmationTtlSeconds: 300,
  );
  @override
  ToolDescriptor? descriptor(String toolName) => ToolDescriptor(
    name: 'platform_confirmed_action',
    description:
        'Purpose: Bound write.\nCall when: Host consent exists.\nRequired arguments: recipient document amount\nExample: platform_confirmed_action({})',
    inputSchema: const {'type': 'object'},
    actionProfile: ActionProfile(
      sideEffect: 'write',
      confirmation: 'confirm',
      idempotentCommit: true,
      extensionMembers: {'platform_binding_hash': binding},
    ),
    annotations: const ToolAnnotations(
      readOnlyHint: false,
      destructiveHint: false,
      idempotentHint: true,
      openWorldHint: false,
    ),
  );
  @override
  void save(ConfirmationRecord record) => saved = record;
  @override
  ConfirmationRecord? record(String proposalId, int proposalVersion) =>
      saved?.proposal.proposalId == proposalId &&
          saved?.proposal.proposalVersion == proposalVersion
      ? saved
      : null;
  @override
  ApprovalEvidence? read(ConfirmationRecord record) {
    if (!const {'ui', 'voice'}.contains(proof)) {
      return null;
    }
    final p = record.proposal;
    final policy = record.policy;
    return ApprovalEvidence(
      tenantId: record.tenantId,
      proposalId: p.proposalId,
      proposalVersion: p.proposalVersion,
      actionId: p.actionId,
      toolName: p.toolName,
      argsFingerprint: p.argsFingerprint,
      descriptorDigest: p.descriptorDigest,
      policyId: policy.policyId,
      policyRevision: policy.revision,
      requirement: policy.approvalRequirement,
      proposerId: p.proposerId,
      commitPrincipal: policy.commitPrincipal,
      approverIds: const ['actor'],
      expiresAt: p.expiresAt,
    );
  }

  @override
  AuthorizationDecision authorize(AuthorizationRequest request) =>
      const AuthorizationDecision.allow();
}

import 'dart:convert';

import 'package:crypto/crypto.dart';

import '../contracts/approval_evidence_reader.dart';
import '../contracts/authenticated_actor_provider.dart';
import '../contracts/authorization_context_resolver.dart';
import '../contracts/clock.dart';
import '../contracts/confirmation_authorizer.dart';
import '../contracts/confirmation_policy_resolver.dart';
import '../contracts/confirmation_record_store.dart';
import '../contracts/confirmation_token_store.dart';
import '../contracts/tool_descriptor_registry.dart';
import '../idempotency/arguments_fingerprint.dart';
import '../schema/canonicalizer.dart';
import '../tool/descriptor_digest.dart';
import '../tool/descriptor_drift.dart';
import 'authorization_request.dart';
import 'confirmation_failure.dart';
import 'confirmation_protocol.dart';
import 'confirmation_record.dart';
import 'confirmation_token.dart';
import 'proposal.dart';
import 'token_envelope.dart';
import 'token_key_provider.dart';
import 'token_purpose.dart';

/// Supported confirmation boundary.
final class ConfirmationService {
  ConfirmationService({
    required ConfirmationProtocol protocol,
    required TokenKeyProvider keys,
    required ConfirmationTokenStore store,
    required String issuer,
    required String audience,
    required AuthenticatedActorProvider actors,
    required Clock clock,
    required ToolDescriptorRegistry descriptors,
    required AuthorizationContextResolver contexts,
    required ConfirmationPolicyResolver policies,
    required ConfirmationRecordStore records,
    required ApprovalEvidenceReader approvals,
    required ConfirmationAuthorizer authorizer,
    required Canonicalizer canonicalizer,
  }) : _protocol = protocol,
       _keys = keys,
       _store = store,
       _issuer = issuer,
       _audience = audience,
       _actors = actors,
       _clock = clock,
       _descriptors = descriptors,
       _contexts = contexts,
       _policies = policies,
       _records = records,
       _approvals = approvals,
       _authorizer = authorizer,
       _canonicalizer = canonicalizer {
    if (issuer.isEmpty || audience.isEmpty) {
      throw ArgumentError('Issuer and audience are required.');
    }
  }

  final ConfirmationProtocol _protocol;
  final TokenKeyProvider _keys;
  final ConfirmationTokenStore _store;
  final String _issuer;
  final String _audience;
  final AuthenticatedActorProvider _actors;
  final Clock _clock;
  final ToolDescriptorRegistry _descriptors;
  final AuthorizationContextResolver _contexts;
  final ConfirmationPolicyResolver _policies;
  final ConfirmationRecordStore _records;
  final ApprovalEvidenceReader _approvals;
  final ConfirmationAuthorizer _authorizer;
  final Canonicalizer _canonicalizer;
  ConfirmationToken prepare({
    required String actionId,
    required String toolName,
    required Object? arguments,
    required Map<String, Object?> preview,
  }) {
    if (actionId.isEmpty || toolName.isEmpty) {
      throw ConfirmationFailure.contextMismatch();
    }
    final actor = _actors.currentActor();
    final now = _clock.now();
    final descriptor = _descriptors.descriptor(toolName);
    if (descriptor == null || descriptor.name != toolName) {
      throw ConfirmationFailure.contextMismatch();
    }
    final context = _contexts.resolveContext(actor, actionId, toolName);
    if (context.tenantId != actor.tenantId) {
      throw ConfirmationFailure.contextMismatch();
    }
    final policy = _policies.resolvePolicy(actor, context, actionId, toolName);
    if (policy == null ||
        !policy.allowsCommit ||
        !policy.validUntil.isAfter(now)) {
      throw ConfirmationFailure.policyDenied();
    }

    final proposal = Proposal(
      proposalId: 'cnf-${TokenEnvelope.newJti()}',
      proposalVersion: 1,
      actionId: actionId,
      toolName: toolName,
      proposerId: actor.id,
      principal: policy.commitPrincipal,
      argsFingerprint: ArgumentsFingerprint.fromArguments(
        arguments,
        _canonicalizer,
      ),
      descriptorDigest: DescriptorDigest.forDescriptor(
        descriptor,
        _canonicalizer,
      ),
      expiresAt: now.add(Duration(seconds: policy.confirmationTtlSeconds)),
      preview: preview,
    );
    final token = _protocol.prepare(proposal, now, actor.tenantId);
    _records.save(
      ConfirmationRecord(
        tenantId: actor.tenantId,
        proposal: proposal,
        context: context,
        policy: policy,
        tokenDigest: _digestToken(token.value),
      ),
    );
    return token;
  }

  ConfirmationToken commit({
    required String token,
    required Object? arguments,
  }) {
    final actor = _actors.currentActor();
    final now = _clock.now();
    final envelope = TokenEnvelope.parse(
      token: token,
      keys: _keys,
      now: now,
      expectedIss: _issuer,
      expectedAud: _audience,
    );
    final parsed = ConfirmationToken.fromEnvelope(envelope);

    final record = _records.record(parsed.proposalId, parsed.proposalVersion);
    if (record == null) throw ConfirmationFailure.recordMissing();
    final proposal = record.proposal;
    if (record.tenantId != actor.tenantId ||
        record.context.tenantId != actor.tenantId ||
        envelope.tenantId != actor.tenantId ||
        record.tokenDigest != _digestToken(token) ||
        parsed.proposalId != proposal.proposalId ||
        parsed.proposalVersion != proposal.proposalVersion ||
        parsed.actionId != proposal.actionId ||
        parsed.toolName != proposal.toolName ||
        parsed.principal != proposal.principal) {
      throw ConfirmationFailure.contextMismatch();
    }
    if (!parsed.expiresAt.isAfter(now) || !proposal.expiresAt.isAfter(now)) {
      throw ConfirmationFailure.expired();
    }

    final actual = ArgumentsFingerprint.fromArguments(
      arguments,
      _canonicalizer,
    );
    if (actual.value != proposal.argsFingerprint.value ||
        parsed.argsFingerprint.value != actual.value) {
      throw ConfirmationFailure.argsMismatch();
    }
    final descriptor = _descriptors.descriptor(proposal.toolName);
    if (descriptor == null) throw ConfirmationFailure.contextMismatch();
    final currentDigest = DescriptorDigest.forDescriptor(
      descriptor,
      _canonicalizer,
    );
    if (currentDigest.value != proposal.descriptorDigest.value ||
        parsed.descriptorDigest.value != currentDigest.value) {
      throw DescriptorDrift(
        expected: proposal.descriptorDigest,
        actual: currentDigest,
      );
    }

    _store.consume(
      tenantId: envelope.tenantId,
      tokenId: envelope.jti,
      tokenDigestHex: _digestTokenHex(token),
      purpose: TokenPurpose.confirmation.wire,
      expiresAt: parsed.expiresAt,
      now: now,
    );

    try {
      final policy = _policies.resolvePolicy(
        actor,
        record.context,
        proposal.actionId,
        proposal.toolName,
      );
      if (policy == null ||
          !policy.allowsCommit ||
          !policy.validUntil.isAfter(now)) {
        throw ConfirmationFailure.policyDenied();
      }
      if (!policy.matches(record.policy)) {
        throw ConfirmationFailure.policyChanged();
      }
      if (actor.id != policy.commitPrincipal) {
        throw ConfirmationFailure.principalMismatch();
      }

      final requirement = policy.approvalRequirement;
      if (requirement.mode != 'none') {
        final evidence = _approvals.read(record);
        if (evidence == null) throw ConfirmationFailure.approvalMissing();
        if (evidence.status != 'approved' || !evidence.expiresAt.isAfter(now)) {
          throw ConfirmationFailure.approvalStale();
        }
        if (!evidence.matches(record)) {
          throw ConfirmationFailure.approvalMismatch();
        }
        if (evidence.approverIds.length < requirement.quorum) {
          throw ConfirmationFailure.quorumInsufficient();
        }
        if (requirement.separateFromProposer &&
            evidence.approverIds.contains(proposal.proposerId)) {
          throw ConfirmationFailure.separationViolation();
        }
      }

      final request = AuthorizationRequest(
        actor: actor,
        tenantId: record.tenantId,
        resourceId: record.context.resourceId,
        actionId: proposal.actionId,
        toolName: proposal.toolName,
        proposalId: proposal.proposalId,
        proposalVersion: proposal.proposalVersion,
        argsFingerprint: actual,
        descriptorDigest: currentDigest,
        policyId: policy.policyId,
        policyRevision: policy.revision,
        environmentAttributes: record.context.environmentAttributes,
        riskAttributes: record.context.riskAttributes,
        delegationId: record.context.delegationId,
      );
      if (!_authorizer.authorize(request).isAllowed) {
        throw ConfirmationFailure.authorizationDenied();
      }

      _store.refineOutcome(
        tenantId: envelope.tenantId,
        tokenId: envelope.jti,
        outcome: 'authorized',
      );
      return parsed;
    } on ConfirmationFailure catch (failure) {
      _refineOutcomeForFailure(envelope.tenantId, envelope.jti, failure);
      rethrow;
    }
  }

  void _refineOutcomeForFailure(
    String tenantId,
    String jti,
    ConfirmationFailure failure,
  ) {
    final code = failure.error.code;
    if (code.contains('policy')) {
      _store.refineOutcome(
        tenantId: tenantId,
        tokenId: jti,
        outcome: 'policy_denied',
      );
      return;
    }
    if (code.contains('authorization')) {
      _store.refineOutcome(
        tenantId: tenantId,
        tokenId: jti,
        outcome: 'authorization_denied',
      );
    }
  }

  static String _digestToken(String token) =>
      'sha-256:${_digestTokenHex(token)}';

  static String _digestTokenHex(String token) =>
      sha256.convert(utf8.encode(token)).toString();
}

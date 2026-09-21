import 'dart:convert';

import 'package:crypto/crypto.dart';

import '../contracts/commit_authorizer.dart';
import '../contracts/confirmation_token_store.dart';
import '../idempotency/arguments_fingerprint.dart';
import '../tool/descriptor_digest.dart';
import '../tool/descriptor_drift.dart';
import 'confirmation_failure.dart';
import 'confirmation_token.dart';
import 'decision_audit.dart';
import 'proposal.dart';
import 'proposal_state.dart';
import 'token_envelope.dart';
import 'token_key_provider.dart';
import 'token_purpose.dart';

/// Deprecated unsafe expert/legacy primitive. Use ConfirmationService.
final class ConfirmationProtocol {
  ConfirmationProtocol({
    required TokenKeyProvider keys,
    required ConfirmationTokenStore store,
    required String issuer,
    required String audience,
    int maxLifetimeSeconds = TokenEnvelope.defaultMaxLifetimeSeconds,
  }) : _keys = keys,
       _store = store,
       _issuer = issuer,
       _audience = audience,
       _maxLifetimeSeconds = maxLifetimeSeconds {
    if (issuer.isEmpty || audience.isEmpty) {
      throw ArgumentError('Issuer and audience are required.');
    }
    if (maxLifetimeSeconds < 1 ||
        maxLifetimeSeconds > TokenEnvelope.hardMaxLifetimeSeconds) {
      throw ArgumentError(
        'Confirmation lifetime limit must be between 1 second and 7 days.',
      );
    }
  }

  final TokenKeyProvider _keys;
  final ConfirmationTokenStore _store;
  final String _issuer;
  final String _audience;
  final int _maxLifetimeSeconds;

  ConfirmationToken prepare(Proposal proposal, DateTime now, String tenantId) {
    if (proposal.state != ProposalState.pending) {
      throw ArgumentError('Only a pending proposal can receive a token.');
    }
    if (!proposal.expiresAt.isAfter(now)) {
      throw ArgumentError('A proposal requires a future expires_at deadline.');
    }
    if (proposal.expiresAt.difference(now).inSeconds > _maxLifetimeSeconds) {
      throw ArgumentError(
        'The proposal lifetime exceeds the configured confirmation limit.',
      );
    }
    if (tenantId.isEmpty) {
      throw ArgumentError(
        'Tenant id is required to issue a confirmation token.',
      );
    }
    return ConfirmationToken.issueAt1(
      proposal: proposal,
      tenantId: tenantId,
      keys: _keys,
      issuer: _issuer,
      audience: _audience,
      now: now,
    );
  }

  ConfirmationToken verify(String token, DateTime now) =>
      ConfirmationToken.fromEnvelope(
        TokenEnvelope.parse(
          token: token,
          keys: _keys,
          now: now,
          expectedIss: _issuer,
          expectedAud: _audience,
        ),
      );

  /// Unsafe application boundary: security inputs are caller-controlled.
  @Deprecated('Use ConfirmationService.commit')
  ConfirmationToken commit({
    required String token,
    required ArgumentsFingerprint actualArguments,
    required DescriptorDigest currentDescriptor,
    required String principal,
    required DateTime now,
    required CommitAuthorizer authorizer,
    String? proposerId,
    bool dualControl = false,
    DecisionAudit? decision,
  }) {
    decision?.assertAllowsCommit(actualArguments);
    final envelope = TokenEnvelope.parse(
      token: token,
      keys: _keys,
      now: now,
      expectedIss: _issuer,
      expectedAud: _audience,
    );
    final parsed = ConfirmationToken.fromEnvelope(envelope);
    if (parsed.argsFingerprint.value != actualArguments.value) {
      throw ConfirmationFailure.argsMismatch();
    }
    if (parsed.principal != principal) {
      throw ConfirmationFailure.principalMismatch();
    }
    if (parsed.descriptorDigest.value != currentDescriptor.value) {
      throw DescriptorDrift(
        expected: parsed.descriptorDigest,
        actual: currentDescriptor,
      );
    }
    if (dualControl && proposerId == principal) {
      throw StateError(
        'Dual control requires a different approving principal.',
      );
    }

    _store.consume(
      tenantId: envelope.tenantId,
      tokenId: envelope.jti,
      tokenDigestHex: _digestToken(token),
      purpose: TokenPurpose.confirmation.wire,
      expiresAt: parsed.expiresAt,
      now: now,
    );

    if (!authorizer.isAllowed(principal, parsed.toolName)) {
      _store.refineOutcome(
        tenantId: envelope.tenantId,
        tokenId: envelope.jti,
        outcome: 'authorization_denied',
      );
      throw ConfirmationFailure.authorizationDenied();
    }

    _store.refineOutcome(
      tenantId: envelope.tenantId,
      tokenId: envelope.jti,
      outcome: 'authorized',
    );
    return parsed;
  }

  static String _digestToken(String token) =>
      sha256.convert(utf8.encode(token)).toString();
}

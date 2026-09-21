import '../idempotency/arguments_fingerprint.dart';
import '../tool/descriptor_digest.dart';
import 'authenticated_actor.dart';

final class AuthorizationRequest {
  AuthorizationRequest({
    required this.actor,
    required this.tenantId,
    required this.resourceId,
    required this.actionId,
    required this.toolName,
    required this.proposalId,
    required this.proposalVersion,
    required this.argsFingerprint,
    required this.descriptorDigest,
    required this.policyId,
    required this.policyRevision,
    Map<String, Object?> environmentAttributes = const {},
    Map<String, Object?> riskAttributes = const {},
    this.delegationId,
  }) : environmentAttributes = Map.unmodifiable(environmentAttributes),
       riskAttributes = Map.unmodifiable(riskAttributes) {
    if ([
          tenantId,
          resourceId,
          actionId,
          toolName,
          proposalId,
          policyId,
          policyRevision,
        ].any((value) => value.isEmpty) ||
        proposalVersion < 1) {
      throw ArgumentError('Authorization request requires complete context.');
    }
    if (actor.tenantId != tenantId || delegationId == '') {
      throw ArgumentError('Authorization actor context does not match.');
    }
  }

  final AuthenticatedActor actor;
  final String tenantId;
  final String resourceId;
  final String actionId;
  final String toolName;
  final String proposalId;
  final int proposalVersion;
  final ArgumentsFingerprint argsFingerprint;
  final DescriptorDigest descriptorDigest;
  final String policyId;
  final String policyRevision;
  final Map<String, Object?> environmentAttributes;
  final Map<String, Object?> riskAttributes;
  final String? delegationId;
}

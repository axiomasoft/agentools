final class AuthorizationContext {
  AuthorizationContext({
    required this.tenantId,
    required this.resourceId,
    Map<String, Object?> environmentAttributes = const {},
    Map<String, Object?> riskAttributes = const {},
    this.delegationId,
  }) : environmentAttributes = Map.unmodifiable(environmentAttributes),
       riskAttributes = Map.unmodifiable(riskAttributes) {
    if (tenantId.isEmpty || resourceId.isEmpty) {
      throw ArgumentError('Authorization tenant and resource are required.');
    }
    if (delegationId == '') {
      throw ArgumentError('Delegation id cannot be empty.');
    }
  }

  final String tenantId;
  final String resourceId;
  final Map<String, Object?> environmentAttributes;
  final Map<String, Object?> riskAttributes;
  final String? delegationId;
}

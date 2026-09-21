final class AuthenticatedActor {
  AuthenticatedActor({
    required this.id,
    required this.tenantId,
    List<String> roles = const [],
    this.delegationId,
  }) : roles = List.unmodifiable(roles) {
    if (id.isEmpty || tenantId.isEmpty) {
      throw ArgumentError(
        'Authenticated actor identity and tenant are required.',
      );
    }
    if (delegationId == '') {
      throw ArgumentError('Delegation id cannot be empty.');
    }
    if (roles.any((role) => role.isEmpty) ||
        roles.toSet().length != roles.length) {
      throw ArgumentError('Actor roles must be unique non-empty strings.');
    }
  }

  final String id;
  final String tenantId;
  final List<String> roles;
  final String? delegationId;
}

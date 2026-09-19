import '../journal/actor.dart';

/// Invocation record carrying the distinguishable principal triple (SEC-13..SEC-16).
typedef InvocationRecord = ({Actor actor, Map<String, String> payload});

/// Validates and builds the distinguishable principal triple (SEC-13..SEC-16).
final class PrincipalTriple {
  String? validate(
    Map<String, dynamic> principals,
    Map<String, dynamic> authorization,
  ) {
    final initiator = _principalId(principals, 'initiator');
    final agent = _principalId(principals, 'agent');
    if (initiator == null || agent == null) {
      return 'missing_principal';
    }
    if (initiator == agent) {
      return 'merged_user_and_agent';
    }
    if (_looksLikeRuntimeAccount(initiator)) {
      return 'runtime_account_as_end_user';
    }

    final scope = authorization['resource'];
    if (scope is! String || scope.isEmpty) {
      return 'missing_authorization_scope';
    }
    return null;
  }

  InvocationRecord buildInvocationRecord(
    Map<String, dynamic> principals,
    Map<String, dynamic> authorization,
  ) {
    final failure = validate(principals, authorization);
    if (failure != null) {
      throw ArgumentError('Principal triple is invalid: $failure.');
    }

    final agentId = _principalId(principals, 'agent');
    final initiatorId = _principalId(principals, 'initiator');
    if (agentId == null || initiatorId == null) {
      throw ArgumentError('Principal triple is invalid.');
    }

    final scope = authorization['resource'];
    if (scope is! String || scope.isEmpty) {
      throw ArgumentError('Authorization scope is required.');
    }

    return (
      actor: Actor(type: 'agent', id: agentId),
      payload: {
        'end_user_id': initiatorId,
        'authorization_scope': scope,
      },
    );
  }

  /// SEC-14; the agent principal lives in actor.type = agent.
  void assertAgentActor(Actor actor) {
    if (actor.type != 'agent') {
      throw ArgumentError(
        'The agent principal must be carried by actor.type = agent.',
      );
    }
  }

  String? _principalId(Map<String, dynamic> principals, String role) {
    final entry = principals[role];
    if (entry is! Map) {
      return null;
    }
    final id = entry['id'];
    if (id is! String || id.isEmpty) {
      return null;
    }
    return id;
  }

  bool _looksLikeRuntimeAccount(String initiatorId) {
    return initiatorId.startsWith('agent-') ||
        initiatorId.startsWith('runtime-');
  }
}

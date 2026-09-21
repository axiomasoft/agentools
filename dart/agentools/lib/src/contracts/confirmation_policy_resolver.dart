import '../confirmation/authenticated_actor.dart';
import '../confirmation/authorization_context.dart';
import '../confirmation/resolved_policy.dart';

abstract interface class ConfirmationPolicyResolver {
  ResolvedPolicy? resolvePolicy(
    AuthenticatedActor actor,
    AuthorizationContext context,
    String actionId,
    String toolName,
  );
}

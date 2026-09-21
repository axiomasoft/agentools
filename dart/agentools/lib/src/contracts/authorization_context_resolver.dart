import '../confirmation/authenticated_actor.dart';
import '../confirmation/authorization_context.dart';

abstract interface class AuthorizationContextResolver {
  AuthorizationContext resolveContext(
    AuthenticatedActor actor,
    String actionId,
    String toolName,
  );
}

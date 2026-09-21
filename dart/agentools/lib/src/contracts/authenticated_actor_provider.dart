import '../confirmation/authenticated_actor.dart';

abstract interface class AuthenticatedActorProvider {
  AuthenticatedActor currentActor();
}

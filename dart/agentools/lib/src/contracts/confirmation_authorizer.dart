import '../confirmation/authorization_decision.dart';
import '../confirmation/authorization_request.dart';

abstract interface class ConfirmationAuthorizer {
  AuthorizationDecision authorize(AuthorizationRequest request);
}

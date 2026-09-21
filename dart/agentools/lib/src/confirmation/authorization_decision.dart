final class AuthorizationDecision {
  const AuthorizationDecision._(this.result);

  const AuthorizationDecision.allow() : this._('allow');
  const AuthorizationDecision.deny() : this._('deny');

  final String result;

  bool get isAllowed => result == 'allow';
}

import 'package:agentools/agentools.dart';
import 'package:test/test.dart';

void main() {
  // SEC-1, SEC-3; foreign audience tokens are rejected with a distinct permission code.
  test('rejects foreign audience tokens', () {
    final result = CredentialGuard().validateAccessToken({
      'kind': 'access_token',
      'audience': 'downstream-api',
      'issuer': 'issuer-trusted',
      'integrity': 'verified',
    });

    expect(result, isNotNull);
    expect(result!.isError, isTrue);
    expect(result.error!.code, OpsErrorCodes.foreignAccessToken);
    expect(result.error!.context?['security_rule'], 'SEC-3');
    expect(result.error!.context?['reason'], 'foreign_access_token');
  });

  // SEC-2; trusted audience passes validation.
  test('accepts tokens issued for us', () {
    final result = CredentialGuard().validateAccessToken({
      'kind': 'access_token',
      'audience': 'agentools',
      'issuer': 'issuer-trusted',
      'integrity': 'verified',
    });

    expect(result, isNull);
  });

  // SEC-5; tokens smuggled through arguments are rejected.
  test('rejects tokens embedded in arguments', () {
    final result = CredentialGuard().rejectTokensInArguments({
      'token': {'kind': 'access_token', 'audience': 'agentools'},
    });

    expect(result, isNotNull);
    expect(result!.error!.code, OpsErrorCodes.foreignAccessToken);
  });

  // SEC-6; confirmation tokens cannot be used as access tokens.
  test('rejects confirmation token as access token', () {
    final result = CredentialGuard().rejectConfirmationTokenAsAccess(
      'confirmation_token',
    );

    expect(result, isNotNull);
    expect(result!.error!.code, OpsErrorCodes.foreignAccessToken);
  });
}

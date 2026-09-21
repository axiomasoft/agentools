import 'key_id.dart';
import 'key_state.dart';
import 'signing_key.dart';
import 'token_key_provider.dart';
import 'token_purpose.dart';
import 'verification_key.dart';

/// Single-key provider for tests and reference contours.
final class StaticTokenKeyProvider implements TokenKeyProvider {
  StaticTokenKeyProvider(this._signing);

  final SigningKey _signing;

  @override
  SigningKey signingKey(String tenantId, TokenPurpose purpose) => _signing;

  @override
  VerificationKey? verificationKey(
    String tenantId,
    TokenPurpose purpose,
    KeyId kid,
  ) {
    if (kid.value != _signing.id.value) {
      return null;
    }
    return VerificationKey(
      id: _signing.id,
      algorithm: _signing.algorithm,
      secret: _signing.secret,
      state: KeyState.active,
    );
  }
}

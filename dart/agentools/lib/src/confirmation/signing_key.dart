import 'confirmation_failure.dart';
import 'key_id.dart';
import 'key_state.dart';

/// Active HS256 signing material. Verify-only/compromised/retired keys cannot be constructed here.
final class SigningKey {
  SigningKey({
    required this.id,
    required this.algorithm,
    required this.secret,
    this.state = KeyState.active,
  }) {
    if (algorithm != 'HS256') {
      throw ArgumentError('Signing keys allow HS256 only.');
    }
    if (secret.length < 32) {
      throw ArgumentError('HS256 keys must be at least 32 bytes.');
    }
    if (state != KeyState.active) {
      throw ConfirmationFailure.keyDenied();
    }
  }

  final KeyId id;
  final String algorithm;
  final List<int> secret;
  final KeyState state;
}

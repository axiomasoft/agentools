import 'key_id.dart';
import 'key_state.dart';

final class VerificationKey {
  VerificationKey({
    required this.id,
    required this.algorithm,
    required this.secret,
    required this.state,
  }) {
    if (algorithm != 'HS256') {
      throw ArgumentError('Verification keys allow HS256 only.');
    }
    if (secret.length < 32) {
      throw ArgumentError('HS256 keys must be at least 32 bytes.');
    }
  }

  final KeyId id;
  final String algorithm;
  final List<int> secret;
  final KeyState state;

  bool get canVerify =>
      state == KeyState.active || state == KeyState.verifyOnly;
}

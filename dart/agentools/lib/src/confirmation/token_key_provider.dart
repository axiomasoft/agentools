import 'key_id.dart';
import 'signing_key.dart';
import 'token_purpose.dart';
import 'verification_key.dart';

abstract interface class TokenKeyProvider {
  SigningKey signingKey(String tenantId, TokenPurpose purpose);

  VerificationKey? verificationKey(
    String tenantId,
    TokenPurpose purpose,
    KeyId kid,
  );
}

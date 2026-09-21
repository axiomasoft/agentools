import '../confirmation/token_consume_admission.dart';

abstract interface class ConfirmationTokenStore {
  TokenConsumeAdmission consume({
    required String tenantId,
    required String tokenId,
    required String tokenDigestHex,
    required String purpose,
    required DateTime expiresAt,
    required DateTime now,
  });

  void refineOutcome({
    required String tenantId,
    required String tokenId,
    required String outcome,
  });

  int cleanup({required DateTime now, required Duration retention});
}

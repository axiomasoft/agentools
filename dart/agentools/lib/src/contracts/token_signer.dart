/// Signs and verifies opaque confirmation token payloads.
abstract interface class TokenSigner {
  /// Returns the raw HMAC digest for [payload].
  String sign(String payload);

  /// Returns whether [signature] matches the digest for [payload].
  bool verify(String payload, String signature);
}

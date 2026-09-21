/// Lookup handle for tenant/purpose scoped MAC material.
final class KeyId {
  KeyId(this.value) {
    if (value.isEmpty) {
      throw ArgumentError('Key id must not be empty.');
    }
  }

  final String value;
}

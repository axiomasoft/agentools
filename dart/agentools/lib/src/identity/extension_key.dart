import 'extension_identity.dart';
import 'extension_key_parse.dart';

/// Чистый разбор ключа, который производитель называет данными AgenTools.
final class ExtensionKey {
  const ExtensionKey._(this.value);

  final String value;

  /// EXT-7: единственный объявленный ключ данных.
  static ExtensionKey action() => ExtensionKey._(ExtensionIdentity.dataKey);

  /// Разбирает ключ, который производитель утверждает своим.
  static ExtensionKeyParse parseOwn(String key) {
    if (!key.startsWith('${ExtensionIdentity.vendorPrefix}/')) {
      return ForeignVendorKeyReject(
        '$key does not use the ${ExtensionIdentity.vendorPrefix} vendor prefix.',
      );
    }
    if (RegExp(r'(?:-v[0-9]+|/v[0-9]+)$').hasMatch(key)) {
      return VersionedDataKeyReject(
        '$key puts a version in the extension data key.',
      );
    }
    final ownKeyPattern = RegExp(
      r'^dev\.agentools/(?:[A-Za-z0-9]|[A-Za-z0-9][A-Za-z0-9._-]*[A-Za-z0-9])$',
    );
    if (!ownKeyPattern.hasMatch(key)) {
      return InvalidVendorKeyReject(
        '$key does not match the AgenTools vendor-key grammar.',
      );
    }
    if (key != ExtensionIdentity.dataKey) {
      return InvalidVendorKeyReject(
        '$key is not the declared AgenTools data key.',
      );
    }

    return ExtensionKeyParsed(ExtensionKey._(key));
  }
}

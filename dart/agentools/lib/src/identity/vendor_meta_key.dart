import 'extension_identity.dart';

/// Результат проверки собственного `_meta`-ключа ревизии (EXT-8).
sealed class VendorMetaKeyParse {
  const VendorMetaKeyParse();
}

final class VendorMetaKeyAccepted extends VendorMetaKeyParse {
  const VendorMetaKeyAccepted(this.key);

  final String key;
}

final class VendorMetaKeyRejected extends VendorMetaKeyParse {
  const VendorMetaKeyRejected(this.message);

  final String message;
}

/// Грамматика собственных `_meta`-ключей под префиксом `dev.agentools/`.
final class VendorMetaKey {
  const VendorMetaKey._();

  static final RegExp _ownMetaKeyPattern = RegExp(
    r'^dev\.agentools/(?:[A-Za-z0-9]|[A-Za-z0-9][A-Za-z0-9._-]*[A-Za-z0-9])$',
  );

  static final RegExp _reservedMcpPrefixPattern = RegExp(
    r'^[A-Za-z][A-Za-z0-9-]*\.(?:modelcontextprotocol|mcp)(?:[./])',
  );

  /// EXT-8: проверяет грамматику собственного `_meta`-ключа.
  static VendorMetaKeyParse parseOwn(String key) {
    if (_reservedMcpPrefixPattern.hasMatch(key)) {
      return VendorMetaKeyRejected('$key uses a reserved MCP vendor prefix.');
    }
    if (!key.startsWith('${ExtensionIdentity.vendorPrefix}/')) {
      return VendorMetaKeyRejected(
        '$key does not use the ${ExtensionIdentity.vendorPrefix} vendor prefix.',
      );
    }
    if (RegExp(r'(?:-v[0-9]+|/v[0-9]+)$').hasMatch(key)) {
      return VendorMetaKeyRejected(
        '$key puts a version in the vendor meta key.',
      );
    }
    if (!_ownMetaKeyPattern.hasMatch(key)) {
      return VendorMetaKeyRejected(
        '$key does not match the AgenTools vendor-key grammar.',
      );
    }

    return VendorMetaKeyAccepted(key);
  }

  /// Неизвестный ключ под нашим префиксом не является ошибкой разбора документа.
  static bool isOwnVendorPrefix(String key) =>
      key.startsWith('${ExtensionIdentity.vendorPrefix}/');
}

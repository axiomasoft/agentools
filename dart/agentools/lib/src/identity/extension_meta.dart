import 'extension_identity.dart';
import 'vendor_meta_key.dart';

/// Чтение данных профиля из `_meta` без отказа на лишних ключах (EXT-10, §J9).
final class ExtensionMeta {
  const ExtensionMeta._();

  /// EXT-7, EXT-10: читает только объявленный ключ данных.
  static Map<String, Object?>? actionData(Map<String, Object?> meta) {
    final data = meta[ExtensionIdentity.dataKey];
    if (data is! Map) {
      return null;
    }

    final actionData = <String, Object?>{};
    for (final entry in data.entries) {
      if (entry.key is! String) {
        return null;
      }
      actionData[entry.key as String] = entry.value;
    }

    return actionData;
  }

  /// Структурная половина must-ignore: лишний ключ верхнего уровня не отвергает документ.
  static bool acceptsTopLevelDocument(Map<String, Object?> document) => true;

  /// Неизвестный ключ под нашим префиксом игнорируется, а не отвергается.
  static Iterable<String> ignorableOwnVendorKeys(Map<String, Object?> meta) {
    return meta.keys.where(
      (key) =>
          key != ExtensionIdentity.dataKey && VendorMetaKey.isOwnVendorPrefix(key),
    );
  }
}

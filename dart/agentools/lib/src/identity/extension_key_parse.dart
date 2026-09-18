import 'extension_key.dart';

/// Результат разбора ключа, который производитель называет данными AgenTools.
sealed class ExtensionKeyParse {
  const ExtensionKeyParse();
}

/// Успешный разбор объявленного ключа данных.
final class ExtensionKeyParsed extends ExtensionKeyParse {
  const ExtensionKeyParsed(this.key);

  final ExtensionKey key;
}

/// Отказ разбора с именованной диагностикой.
sealed class ExtensionKeyReject extends ExtensionKeyParse {
  const ExtensionKeyReject(this.message);

  final String message;
}

/// EXT-10: ключ с чужим вендорным префиксом.
final class ForeignVendorKeyReject extends ExtensionKeyReject {
  const ForeignVendorKeyReject(super.message);
}

/// EXT-6: версия внутри имени ключа данных.
final class VersionedDataKeyReject extends ExtensionKeyReject {
  const VersionedDataKeyReject(super.message);
}

/// EXT-8 / EXT-7: префикс свой, но грамматика или имя ключа неверны.
final class InvalidVendorKeyReject extends ExtensionKeyReject {
  const InvalidVendorKeyReject(super.message);
}

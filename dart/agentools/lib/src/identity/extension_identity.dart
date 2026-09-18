/// Носитель идентичности расширения AgenTools — единственный источник констант ревизии.
final class ExtensionIdentity {
  const ExtensionIdentity._();

  /// EXT-11: вендорный префикс ревизии.
  static const vendorPrefix = 'dev.agentools';

  /// EXT-7: единственный ключ данных расширения.
  static const dataKey = '$vendorPrefix/action';

  /// EXT-4: идентификатор расширения ревизии `2026-08`.
  static const identifier = '$vendorPrefix/core';

  /// EXT-5: идентификатор ломающего преемника.
  static const breakingIdentifier = '$vendorPrefix/core-v2';

  /// EXT-14: ревизия спеки, которую реализует этот пакет.
  static const specRevision = '2026-08';

  /// EXT-1: категория расширения по SEP-2133.
  static const category = 'Unofficial per SEP-2133';

  /// EXT-11: база `$id` схем ревизии.
  static const schemaIdBase = 'https://agentools.dev/schema/2026-08/';
}

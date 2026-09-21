import 'extension_identity.dart';

/// Прочитанное объявление расширения из карты `capabilities.extensions`.
final class ExtensionAnnouncement {
  const ExtensionAnnouncement(this.specRevision);

  final String? specRevision;

  /// EXT-13, EXT-14: локальное объявление текущей ревизии.
  static Map<String, Map<String, String>> local() => {
    ExtensionIdentity.identifier: {
      'specRevision': ExtensionIdentity.specRevision,
    },
  };

  /// EXT-14: неизвестная или неполная запись трактуется как неизвестная ревизия.
  static ExtensionAnnouncement fromExtensions(Map<String, Object?> extensions) {
    final settings = extensions[ExtensionIdentity.identifier];
    if (settings is! Map) {
      return const ExtensionAnnouncement(null);
    }
    final specRevision = settings['specRevision'];
    if (specRevision is! String) {
      return const ExtensionAnnouncement(null);
    }

    return ExtensionAnnouncement(specRevision);
  }

  /// EXT-14: поддержка объявлена только при совпадении ревизии.
  bool supportsCurrentRevision() =>
      specRevision == ExtensionIdentity.specRevision;
}

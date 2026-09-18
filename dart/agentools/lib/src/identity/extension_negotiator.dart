import 'extension_announcement.dart';
import 'negotiation_mode.dart';
import 'negotiation_result.dart';
import 'tool_error.dart';

/// Согласование поддержки расширения AgenTools (EXT-13..EXT-16).
final class ExtensionNegotiator {
  const ExtensionNegotiator();

  /// EXT-15: по умолчанию — возврат к ядру; отказ только если расширение обязательно.
  NegotiationResult negotiate(
    Map<String, Object?> counterpartyExtensions, {
    required bool extensionRequired,
  }) {
    final announcement = ExtensionAnnouncement.fromExtensions(
      counterpartyExtensions,
    );
    if (announcement.supportsCurrentRevision()) {
      return NegotiationResult(NegotiationMode.agentools);
    }
    if (!extensionRequired) {
      return NegotiationResult(NegotiationMode.coreProtocol);
    }

    return NegotiationResult(
      NegotiationMode.rejected,
      error: ToolError(
        code: ToolErrorCodes.extensionUnsupported,
        category: 'precondition',
        retryable: false,
        title:
            'The counterparty has not declared the required AgenTools extension.',
      ),
    );
  }
}

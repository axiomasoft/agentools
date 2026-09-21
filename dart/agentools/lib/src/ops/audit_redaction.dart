import '../persistence/event_store_failure.dart';

/// Redacts audit documents before AuditSink export. Tokens and PII never leave.
final class AuditRedaction {
  static const _allow = {
    'tool_name',
    'side_effect',
    'verdict',
    'limit_name',
    'limit_value',
    'observed',
    'scenario_id',
    'outcome',
    'step_id',
    'tokens_in',
    'tokens_out',
    'amount_minor',
    'currency',
    'price_table_version',
    'trace',
  };

  static const _deny = {
    'arguments',
    'args',
    'prompt',
    'prompts',
    'token',
    'email',
    'pii',
    'password',
  };

  void assertPayload(Map<String, dynamic> payload) {
    for (final entry in payload.entries) {
      final name = entry.key;
      final allowed =
          _allow.contains(name) ||
          name.endsWith('_id') ||
          name.endsWith('_digest');
      if (!allowed || _deny.contains(name)) {
        throw EventStoreFailure.auditForbiddenField(name);
      }
      if (name == 'amount_minor' && entry.value is double) {
        throw EventStoreFailure.auditForbiddenField(name);
      }
    }
  }

  Map<String, dynamic> redactDocument(Map<String, dynamic> document) {
    final payload = document['payload'];
    final source = payload is Map
        ? Map<String, dynamic>.from(payload)
        : <String, dynamic>{};
    final clean = <String, dynamic>{};
    for (final entry in source.entries) {
      if (!_deny.contains(entry.key)) {
        clean[entry.key] = entry.value;
      }
    }
    assertPayload(clean);
    return {...document, 'payload': clean};
  }
}

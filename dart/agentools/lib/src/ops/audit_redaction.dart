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

  void assertPayload(Map<String, dynamic> payload, [String? kind]) {
    for (final entry in payload.entries) {
      final name = entry.key;
      final allowed =
          _allow.contains(name) ||
          name.endsWith('_id') ||
          name.endsWith('_digest');
      if (!allowed || _deny.contains(name)) {
        throw EventStoreFailure.auditForbiddenField(name);
      }
      final value = entry.value;
      if (name == 'trace') {
        if (value is! Map ||
            !value.containsKey('trace_id') ||
            value.keys.any((k) => k != 'trace_id' && k != 'span_id')) {
          throw EventStoreFailure.auditForbiddenField(name);
        }
        for (final field in value.entries) {
          final length = field.key == 'trace_id' ? 32 : 16;
          if (field.value is! String ||
              (field.value as String).length != length ||
              !RegExp('^[0-9a-f]{$length}\$').hasMatch(field.value as String)) {
            throw EventStoreFailure.auditForbiddenField('trace.${field.key}');
          }
        }
        continue;
      }
      final numeric = const {
        'tokens_in',
        'tokens_out',
        'amount_minor',
        'limit_value',
        'observed',
      }.contains(name);
      if (numeric
          ? (value is! int ||
                value.abs() > 9007199254740991 ||
                (name != 'amount_minor' && value < 0))
          : (value is! String || value.isEmpty)) {
        throw EventStoreFailure.auditForbiddenField(name);
      }
    }
    final required = switch (kind) {
      'audit.policy_evaluated' => ['tool_name', 'side_effect', 'verdict'],
      'audit.cost_recorded' => [
        'tokens_in',
        'tokens_out',
        'amount_minor',
        'currency',
        'price_table_version',
      ],
      'audit.limit_exceeded' => ['limit_name', 'limit_value', 'observed'],
      'audit.scenario_run_finished' => ['scenario_id', 'outcome'],
      'audit.compensation_executed' => ['scenario_id', 'step_id'],
      null => <String>[],
      _ => throw EventStoreFailure.auditForbiddenField('kind'),
    };
    for (final field in required) {
      if (!payload.containsKey(field)) {
        throw EventStoreFailure.auditForbiddenField(field);
      }
    }
    const enums = {
      'side_effect': ['read', 'draft', 'write', 'irreversible'],
      'verdict': ['allow', 'ask', 'deny'],
      'outcome': ['COMPLETED', 'FAILED', 'COMPENSATED', 'COMPENSATION_FAILED'],
    };
    for (final entry in enums.entries) {
      if (payload.containsKey(entry.key) &&
          !entry.value.contains(payload[entry.key]) &&
          !(entry.key == 'side_effect' &&
              RegExp(
                r'^[a-z][a-z0-9]*\.[a-z0-9_]+$',
              ).hasMatch(payload[entry.key] as String))) {
        throw EventStoreFailure.auditForbiddenField(entry.key);
      }
    }
    if (payload.containsKey('currency') &&
        ((payload['currency'] as String).length != 3 ||
            !RegExp(r'^[A-Z]{3}$').hasMatch(payload['currency'] as String))) {
      throw EventStoreFailure.auditForbiddenField('currency');
    }
    if (kind == 'audit.limit_exceeded' &&
        (payload['observed'] as int) <= (payload['limit_value'] as int)) {
      throw EventStoreFailure.auditForbiddenField('observed');
    }
    if (payload['outcome'] == 'COMPENSATION_FAILED' &&
        !payload.containsKey('stopped_at_step_id')) {
      throw EventStoreFailure.auditForbiddenField('stopped_at_step_id');
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
    assertPayload(clean, document['kind'] as String?);
    return {...document, 'payload': clean};
  }
}

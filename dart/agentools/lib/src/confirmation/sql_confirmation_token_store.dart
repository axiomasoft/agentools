import '../contracts/confirmation_token_store.dart';
import 'confirmation_failure.dart';
import 'token_consume_admission.dart';
import 'token_envelope.dart';

abstract interface class SqlExecutor {
  SqlQueryResult execute(String sql, Map<String, Object?> params);
}

final class SqlQueryResult {
  const SqlQueryResult({required this.rows, required this.affectedRows});

  final List<Map<String, Object?>> rows;
  final int affectedRows;
}

final class SqlConfirmationTokenStore implements ConfirmationTokenStore {
  SqlConfirmationTokenStore(this._executor);

  final SqlExecutor _executor;

  static const consumeSql = '''
INSERT INTO confirmation_consumed_tokens (
  tenant_id, token_id, token_digest, purpose, outcome, expires_at, presented_at
) VALUES (
  @tenant_id, @token_id, decode(@token_digest, 'hex'), @purpose, 'presented', @expires_at, @now
)
ON CONFLICT (tenant_id, token_id) DO NOTHING
RETURNING token_id
''';

  static const selectDigestSql = '''
SELECT encode(token_digest, 'hex') AS token_digest
FROM confirmation_consumed_tokens
WHERE tenant_id = @tenant_id AND token_id = @token_id
''';

  static const refineSql = '''
UPDATE confirmation_consumed_tokens
SET outcome = @outcome
WHERE tenant_id = @tenant_id AND token_id = @token_id AND outcome = 'presented'
''';

  static const cleanupSql = '''
DELETE FROM confirmation_consumed_tokens
WHERE expires_at + make_interval(secs => @retention_seconds) < @now
''';

  @override
  TokenConsumeAdmission consume({
    required String tenantId,
    required String tokenId,
    required String tokenDigestHex,
    required String purpose,
    required DateTime expiresAt,
    required DateTime now,
  }) {
    final inserted = _executor.execute(consumeSql, {
      'tenant_id': tenantId,
      'token_id': tokenId,
      'token_digest': tokenDigestHex,
      'purpose': purpose,
      'expires_at': expiresAt.toUtc().toIso8601String(),
      'now': now.toUtc().toIso8601String(),
    });
    if (inserted.rows.isNotEmpty &&
        inserted.rows.first['token_id'] == tokenId) {
      return TokenConsumeAdmission.accepted;
    }
    final existing = _executor.execute(selectDigestSql, {
      'tenant_id': tenantId,
      'token_id': tokenId,
    });
    if (existing.rows.isNotEmpty) {
      final digest = existing.rows.first['token_digest']
          ?.toString()
          .toLowerCase();
      if (digest != null && digest != tokenDigestHex.toLowerCase()) {
        throw ConfirmationFailure.collision();
      }
    }
    throw ConfirmationFailure.spent();
  }

  @override
  void refineOutcome({
    required String tenantId,
    required String tokenId,
    required String outcome,
  }) {
    if (!{
      'authorized',
      'authorization_denied',
      'policy_denied',
    }.contains(outcome)) {
      throw ArgumentError('Invalid consume outcome.');
    }
    _executor.execute(refineSql, {
      'tenant_id': tenantId,
      'token_id': tokenId,
      'outcome': outcome,
    });
  }

  @override
  int cleanup({required DateTime now, required Duration retention}) {
    if (retention.inSeconds < TokenEnvelope.minRetentionSeconds) {
      throw ArgumentError(
        'Confirmation token retention must cover the maximum clock skew.',
      );
    }
    final result = _executor.execute(cleanupSql, {
      'retention_seconds': retention.inSeconds,
      'now': now.toUtc().toIso8601String(),
    });
    return result.affectedRows;
  }
}

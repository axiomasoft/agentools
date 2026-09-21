import '../contracts/confirmation_token_store.dart';
import 'confirmation_failure.dart';
import 'token_consume_admission.dart';
import 'token_envelope.dart';

/// Testkit/reference only. Not a production constructor.
final class InMemoryConfirmationTokenStore implements ConfirmationTokenStore {
  final Map<String, _Row> _rows = {};

  @override
  TokenConsumeAdmission consume({
    required String tenantId,
    required String tokenId,
    required String tokenDigestHex,
    required String purpose,
    required DateTime expiresAt,
    required DateTime now,
  }) {
    final key = '$tenantId${String.fromCharCode(0)}$tokenId';
    final existing = _rows[key];
    if (existing == null) {
      _rows[key] = _Row(
        digest: tokenDigestHex,
        purpose: purpose,
        outcome: 'presented',
        expiresAt: expiresAt,
        presentedAt: now,
      );
      return TokenConsumeAdmission.accepted;
    }
    if (existing.digest != tokenDigestHex) {
      throw ConfirmationFailure.collision();
    }
    throw ConfirmationFailure.spent();
  }

  @override
  void refineOutcome({
    required String tenantId,
    required String tokenId,
    required String outcome,
  }) {
    final key = '$tenantId${String.fromCharCode(0)}$tokenId';
    final row = _rows[key];
    if (row == null || row.outcome != 'presented') {
      return;
    }
    if (!{
      'authorized',
      'authorization_denied',
      'policy_denied',
    }.contains(outcome)) {
      throw ArgumentError('Invalid consume outcome.');
    }
    _rows[key] = row.copyWith(outcome: outcome);
  }

  @override
  int cleanup({required DateTime now, required Duration retention}) {
    if (retention.inSeconds < TokenEnvelope.minRetentionSeconds) {
      throw ArgumentError(
        'Confirmation token retention must cover the maximum clock skew.',
      );
    }
    var removed = 0;
    for (final entry in _rows.entries.toList()) {
      if (entry.value.expiresAt.add(retention).isBefore(now)) {
        _rows.remove(entry.key);
        removed++;
      }
    }
    return removed;
  }
}

final class _Row {
  const _Row({
    required this.digest,
    required this.purpose,
    required this.outcome,
    required this.expiresAt,
    required this.presentedAt,
  });

  final String digest;
  final String purpose;
  final String outcome;
  final DateTime expiresAt;
  final DateTime presentedAt;

  _Row copyWith({String? outcome}) => _Row(
    digest: digest,
    purpose: purpose,
    outcome: outcome ?? this.outcome,
    expiresAt: expiresAt,
    presentedAt: presentedAt,
  );
}

import 'idempotency_key.dart';

/// The unique deduplication scope is exactly tenant, tool name, and caller key.
final class IdempotencyScope {
  IdempotencyScope({
    required this.tenantId,
    required this.toolName,
    required this.key,
  }) {
    if (tenantId.isEmpty || toolName.isEmpty) {
      throw ArgumentError('Tenant id and tool name must not be empty.');
    }
  }

  final String tenantId;
  final String toolName;
  final IdempotencyKey key;

  String storageKey() => '$tenantId\u0000$toolName\u0000${key.value}';
}

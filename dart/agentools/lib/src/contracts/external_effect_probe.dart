import '../idempotency/stored_tool_result.dart';

/// Downstream read-before-write probe. Absence degrades to at-most-once.
abstract interface class ExternalEffectProbe {
  StoredToolResult? find(String tool, String idempotencyKey);
}

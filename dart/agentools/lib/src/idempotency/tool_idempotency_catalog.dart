import 'tool_idempotency_capability.dart';

final class ToolIdempotencyCatalog {
  ToolIdempotencyCatalog(
    this._capabilities, [
    Map<String, String> sideEffects = const {},
  ]) : _sideEffects = sideEffects;

  final Map<String, ToolIdempotencyCapability> _capabilities;
  final Map<String, String> _sideEffects;

  ToolIdempotencyCapability capability(String tool) {
    return _capabilities[tool] ?? ToolIdempotencyCapability.unknown;
  }

  bool isHighRisk(String tool) {
    final effect = _sideEffects[tool] ?? 'write';
    return effect != 'read' && effect != 'draft';
  }
}

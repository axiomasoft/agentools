/// All four MCP annotation hints are explicit in an AgenTools descriptor.
final class ToolAnnotations {
  const ToolAnnotations({
    required this.readOnlyHint,
    required this.destructiveHint,
    required this.idempotentHint,
    required this.openWorldHint,
  });

  final bool readOnlyHint;
  final bool destructiveHint;
  final bool idempotentHint;
  final bool openWorldHint;

  Map<String, bool> toJson() => {
    'readOnlyHint': readOnlyHint,
    'destructiveHint': destructiveHint,
    'idempotentHint': idempotentHint,
    'openWorldHint': openWorldHint,
  };
}

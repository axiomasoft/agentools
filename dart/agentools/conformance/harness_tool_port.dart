import 'package:agentools/agentools.dart';

/// Records scenario tool invocations through harness probes.
final class HarnessToolPort implements ToolPort {
  HarnessToolPort(this._probe, this._vectorId);

  final void Function(Map<String, dynamic> message) _probe;
  final String _vectorId;

  @override
  ToolExecutionResult call(
    String tool,
    Map<String, dynamic> arguments,
    String idempotencyKey,
  ) {
    _probe({
      'type': 'probe_call',
      'vector_id': _vectorId,
      'probe_id': 'scenario-$tool-$idempotencyKey',
      'probe_kind': 'tool_boundary',
      'operation': {'tool': tool, 'phase': 'prepare', 'args': arguments},
    });
    return ToolExecutionResult.success({'ok': true});
  }
}

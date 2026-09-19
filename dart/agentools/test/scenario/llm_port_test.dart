import 'package:agentools/agentools.dart';
import 'package:test/test.dart';

/// D8 / SCN-30: conformance and unit tests use a scripted LLM, never the network.
final class ScriptLlmPort implements LlmPort {
  ScriptLlmPort(this._responses);

  final List<Map<String, dynamic>> _responses;
  var _index = 0;

  @override
  Map<String, dynamic> respond(Map<String, dynamic> input) {
    if (_index >= _responses.length) {
      throw StateError('Scripted LLM responses exhausted.');
    }
    return _responses[_index++];
  }
}

void main() {
  test('scripted LLM port returns deterministic responses', () {
    final llm = ScriptLlmPort([
      {'tool': 'demo_probe', 'args': {'value': 'scenario-010'}},
      {'tool': 'demo_probe', 'args': {'value': 'scenario-010'}},
    ]);

    final first = llm.respond({'prompt': 'run'});
    final second = llm.respond({'prompt': 'again'});

    expect(first['tool'], 'demo_probe');
    expect(second['args'], {'value': 'scenario-010'});
    expect(first, equals(second));
  });
}

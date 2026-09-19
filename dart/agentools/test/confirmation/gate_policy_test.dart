import 'package:agentools/agentools.dart';
import 'package:test/test.dart';

void main() {
  // CNF-2, CNF-4, CNF-6, CNF-7; deny dominates every scope and policy cannot weaken write.
  test('uses the closed precedence over the effect baseline', () {
    final policy = GatePolicy();

    expect(
      policy.decide(ActionProfile(sideEffect: 'write')),
      PolicyVerdict.ask,
    );
    expect(
      policy.decide(
        ActionProfile(sideEffect: 'write'),
        [PolicyVerdict.allow],
      ),
      PolicyVerdict.ask,
    );
    expect(
      policy.decide(
        ActionProfile(sideEffect: 'read'),
        [PolicyVerdict.ask, PolicyVerdict.deny],
      ),
      PolicyVerdict.deny,
    );
  });
}

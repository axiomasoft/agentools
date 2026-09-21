import '../tool/action_profile.dart';
import 'policy_verdict.dart';

/// Policy-only layer: decides whether a gate is required, never handles tokens.
final class GatePolicy {
  PolicyVerdict decide(
    ActionProfile profile, [
    List<PolicyVerdict> applicable = const [],
  ]) {
    final baseline = profile.requiresConfirmation()
        ? PolicyVerdict.ask
        : PolicyVerdict.allow;
    final verdicts = [...applicable, baseline];
    if (verdicts.contains(PolicyVerdict.deny)) {
      return PolicyVerdict.deny;
    }
    if (verdicts.contains(PolicyVerdict.ask)) {
      return PolicyVerdict.ask;
    }
    return PolicyVerdict.allow;
  }
}

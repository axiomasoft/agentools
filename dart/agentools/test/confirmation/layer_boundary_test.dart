import 'dart:io';

import 'package:agentools/agentools.dart';
import 'package:test/test.dart';

void main() {
  // CNF-6 boundary: policy and protocol layers stay in separate modules.
  test('keeps policy and protocol mechanics in separate layers', () {
    final policySource = File(
      'lib/src/confirmation/gate_policy.dart',
    ).readAsStringSync();
    final protocolSource = File(
      'lib/src/confirmation/confirmation_protocol.dart',
    ).readAsStringSync();

    expect(policySource.toLowerCase(), isNot(contains('tokensigner')));
    expect(policySource.toLowerCase(), isNot(contains('prepare(')));
    expect(policySource.toLowerCase(), isNot(contains('commit(')));
    expect(protocolSource.toLowerCase(), isNot(contains('policyverdict')));
    expect(protocolSource.toLowerCase(), isNot(contains('gatepolicy')));
  });

  // Cross-language anchor: token payload keys match the PHP golden reference.
  test('matches PHP token coverage fields', () {
    final phpSource = File(
      '../../php/src/Confirmation/ConfirmationToken.php',
    ).readAsStringSync();
    final phpKeys = RegExp(r"'([a-z_]+)'\s*=>")
        .allMatches(phpSource)
        .map((match) => match.group(1)!)
        .where(
          (key) => [
            'action_id',
            'args_fingerprint',
            'descriptor_digest',
            'expires_at',
            'principal',
            'proposal_id',
            'proposal_version',
            'tool_name',
          ].contains(key),
        )
        .toSet();

    expect(phpKeys, ConfirmationToken.coveredPayloadKeys.toSet());
  });
}

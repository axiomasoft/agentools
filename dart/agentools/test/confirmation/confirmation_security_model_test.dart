import 'package:agentools/agentools.dart';
import 'package:test/test.dart';

void main() {
  test(
    'approval evidence rejects proposer as approver when separation required',
    () {
      expect(
        () => ApprovalEvidence(
          tenantId: 'tenant-a',
          proposalId: 'p-1',
          proposalVersion: 1,
          actionId: 'act-1',
          toolName: 'tool',
          argsFingerprint: ArgumentsFingerprint('sha-256:${'c' * 64}'),
          descriptorDigest: DescriptorDigest.fromValue('sha-256:${'a' * 64}'),
          policyId: 'pol',
          policyRevision: '1',
          requirement: ApprovalRequirement(
            mode: 'quorum',
            quorum: 1,
            separateFromProposer: true,
          ),
          proposerId: 'user-a',
          commitPrincipal: 'user-b',
          approverIds: ['user-a'],
          expiresAt: DateTime.now().add(const Duration(hours: 1)),
        ),
        throwsArgumentError,
      );
    },
  );

  test('authenticated actor requires tenant', () {
    expect(
      () => AuthenticatedActor(id: 'user-a', tenantId: ''),
      throwsArgumentError,
    );
  });
}

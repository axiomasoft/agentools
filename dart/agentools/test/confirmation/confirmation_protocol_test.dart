import 'package:agentools/agentools.dart';
import 'package:test/test.dart';

void main() {
  // CNF-18; arguments shown at prepare cannot be swapped at commit.
  test('rejects changed arguments', () {
    final protocol = _protocol();
    final token = protocol.prepare(_proposal(), _now());

    expect(
      () => protocol.commit(
        token: token.value,
        actualArguments: _fingerprint('changed'),
        currentDescriptor: _digest(),
        principal: 'approver-1',
        now: _now(),
        authorizer: _authorized(),
      ),
      throwsA(
        isA<ConfirmationFailure>().having(
          (error) => error.error.code,
          'code',
          ConfirmationErrorCodes.argsMismatch,
        ),
      ),
    );
  });

  // CNF-19; expiry is a structured tool-execution error.
  test('rejects expired tokens', () {
    final protocol = _protocol();
    final token = protocol.prepare(_proposal(expiry: Duration(hours: 1)), _now());

    expect(
      () => protocol.commit(
        token: token.value,
        actualArguments: _fingerprint('prepared'),
        currentDescriptor: _digest(),
        principal: 'approver-1',
        now: _now().add(const Duration(hours: 2)),
        authorizer: _authorized(),
      ),
      throwsA(
        isA<ConfirmationFailure>().having(
          (error) => error.error.code,
          'code',
          ConfirmationErrorCodes.expired,
        ),
      ),
    );
  });

  // CNF-20; the same token cannot execute the action twice.
  test('rejects a presented token', () {
    final protocol = _protocol();
    final token = protocol.prepare(_proposal(), _now());
    protocol.commit(
      token: token.value,
      actualArguments: _fingerprint('prepared'),
      currentDescriptor: _digest(),
      principal: 'approver-1',
      now: _now(),
      authorizer: _authorized(),
    );

    expect(
      () => protocol.commit(
        token: token.value,
        actualArguments: _fingerprint('prepared'),
        currentDescriptor: _digest(),
        principal: 'approver-1',
        now: _now(),
        authorizer: _authorized(),
      ),
      throwsA(
        isA<ConfirmationFailure>().having(
          (error) => error.error.code,
          'code',
          ConfirmationErrorCodes.tokenSpent,
        ),
      ),
    );
  });

  // CNF-21; a token is covered by the only principal allowed to commit.
  test('rejects a foreign principal', () {
    final protocol = _protocol();
    final token = protocol.prepare(_proposal(), _now());

    expect(
      () => protocol.commit(
        token: token.value,
        actualArguments: _fingerprint('prepared'),
        currentDescriptor: _digest(),
        principal: 'other-user',
        now: _now(),
        authorizer: _authorized(),
      ),
      throwsA(
        isA<ConfirmationFailure>().having(
          (error) => error.error.code,
          'code',
          ConfirmationErrorCodes.principalMismatch,
        ),
      ),
    );
  });

  // CNF-33, CNF-34; dual control compares principal ids, not roles.
  test('rejects the proposer in dual control mode', () {
    final protocol = _protocol();
    final proposal = _proposal(principal: 'proposer-1', proposerId: 'proposer-1');
    final token = protocol.prepare(proposal, _now());

    expect(
      () => protocol.commit(
        token: token.value,
        actualArguments: _fingerprint('prepared'),
        currentDescriptor: _digest(),
        principal: 'proposer-1',
        now: _now(),
        authorizer: _authorized(),
        proposerId: 'proposer-1',
        dualControl: true,
      ),
      throwsA(isA<StateError>()),
    );
  });

  // CNF-35, CNF-36; permission is evaluated again at commit, not cached from prepare.
  test('reauthorizes at commit', () {
    final protocol = _protocol();
    final token = protocol.prepare(_proposal(), _now());

    expect(
      () => protocol.commit(
        token: token.value,
        actualArguments: _fingerprint('prepared'),
        currentDescriptor: _digest(),
        principal: 'approver-1',
        now: _now(),
        authorizer: _DeniedAuthorizer(),
      ),
      throwsA(isA<StateError>()),
    );
  });

  // CNF-29; a proposal without a future expires_at deadline cannot receive a token.
  test('rejects proposal without valid TTL on prepare', () {
    final protocol = _protocol();

    expect(
      () => protocol.prepare(
        _proposal(expiry: const Duration(minutes: -1)),
        _now(),
      ),
      throwsA(isA<ArgumentError>()),
    );
  });

  // CNF-26, CNF-28; an edit supersedes the immutable proposal instead of changing it.
  test('an edit supersedes the prior proposal', () {
    final original = _proposal();
    final successor = _editedProposal();
    final payloads = ConfirmationJournalSupport.editJournalPayloads(
      original,
      successor,
    );
    final token = _protocol().prepare(successor, _now());

    expect(payloads[0]['state'], 'pending');
    expect(payloads[1]['state'], 'superseded');
    expect(payloads[1]['superseded_by'], 'prop-2');
    expect(payloads[2]['proposal_version'], 2);
    expect(payloads[0]['proposal_id'], isNot(payloads[2]['proposal_id']));
    expect(token.value, isNotEmpty);
  });

  // CNF-37, CNF-39; audit payload carries the same fingerprint at decision and commit.
  test('decision audit carries matched approval fingerprints', () {
    final fingerprint = _fingerprint('prepared');
    final audit = DecisionAudit(
      proposalId: 'prop-1',
      token: 'opaque',
      decision: 'approve',
      actorId: 'approver-1',
      actorRole: 'finance_approver',
      decidedAt: _now(),
      reason: '',
      argsHashAtDecision: fingerprint,
      argsHashAtCommit: fingerprint,
    );

    expect(
      audit.toPayload()['args_hash_at_decision'],
      fingerprint.value,
    );
    expect(
      audit.toPayload()['args_hash_at_commit'],
      fingerprint.value,
    );
  });

  // CNF-38; mismatched decision and commit hashes deny commit even when the token matches.
  test('rejects commit when decision audit hashes mismatch', () {
    final protocol = _protocol();
    final token = protocol.prepare(_proposal(), _now());
    final decisionHash = _fingerprint('prepared');
    final commitHash = _fingerprint('changed');
    final audit = DecisionAudit(
      proposalId: 'prop-1',
      token: token.value,
      decision: 'approve',
      actorId: 'approver-1',
      actorRole: 'finance_approver',
      decidedAt: _now(),
      reason: '',
      argsHashAtDecision: decisionHash,
      argsHashAtCommit: commitHash,
    );

    expect(
      () => protocol.commit(
        token: token.value,
        actualArguments: _fingerprint('prepared'),
        currentDescriptor: _digest(),
        principal: 'approver-1',
        now: _now(),
        authorizer: _authorized(),
        decision: audit,
      ),
      throwsA(
        isA<ConfirmationFailure>().having(
          (error) => error.error.code,
          'code',
          ConfirmationErrorCodes.argsMismatch,
        ),
      ),
    );
  });
}

ConfirmationProtocol _protocol() {
  return ConfirmationProtocol(HmacTokenSigner('test-secret'));
}

Proposal _proposal({
  Duration expiry = const Duration(hours: 1),
  String principal = 'approver-1',
  String proposerId = 'proposer-1',
}) {
  return Proposal(
    proposalId: 'prop-1',
    proposalVersion: 1,
    actionId: 'action-1',
    toolName: 'fina_payment_send',
    proposerId: proposerId,
    principal: principal,
    argsFingerprint: _fingerprint('prepared'),
    descriptorDigest: _digest(),
    expiresAt: _now().add(expiry),
    preview: const {
      'summary': 'Payment',
      'changes': <dynamic>[],
    },
  );
}

Proposal _editedProposal() {
  return Proposal(
    proposalId: 'prop-2',
    proposalVersion: 2,
    actionId: 'action-1',
    toolName: 'fina_payment_send',
    proposerId: 'proposer-1',
    principal: 'approver-1',
    argsFingerprint: _fingerprint('edited'),
    descriptorDigest: _digest(),
    expiresAt: _now().add(const Duration(hours: 1)),
    preview: const {
      'summary': 'Payment edited',
      'changes': <dynamic>[],
    },
  );
}

ArgumentsFingerprint _fingerprint(String value) {
  return ArgumentsFingerprint.fromJcs(value);
}

DescriptorDigest _digest() {
  return DescriptorDigest.fromValue('sha-256:${'a' * 64}');
}

DateTime _now() {
  return DateTime.parse('2026-08-04T12:00:00+00:00');
}

CommitAuthorizer _authorized() => _AllowAllAuthorizer();

final class _AllowAllAuthorizer implements CommitAuthorizer {
  @override
  bool isAllowed(String principal, String toolName) => true;
}

final class _DeniedAuthorizer implements CommitAuthorizer {
  @override
  bool isAllowed(String principal, String toolName) => false;
}

import 'dart:convert';
import 'dart:io';

import 'package:agentools/agentools.dart';
import 'package:agentools/agentools_testkit.dart';
import 'package:test/test.dart';

const _tenant = 'tenant-a';

void main() {
  test('new protocol instance shares store and denies replay', () {
    final store = InMemoryConfirmationTokenStore();
    final keys = StaticTokenKeyProvider(
      SigningKey(
        id: KeyId('k1'),
        algorithm: 'HS256',
        secret: utf8.encode('s' * 32),
      ),
    );
    final first = _protocol(keys, store);
    final token = first.prepare(_proposal(), _now(), _tenant);
    first.commit(
      token: token.value,
      actualArguments: _fingerprint('prepared'),
      currentDescriptor: _digest(),
      principal: 'approver-1',
      now: _now(),
      authorizer: _authorized(),
    );

    final second = _protocol(keys, store);
    expect(
      () => second.commit(
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

  test('authorization denied token cannot be retried after later grant', () {
    final store = InMemoryConfirmationTokenStore();
    final keys = StaticTokenKeyProvider(
      SigningKey(
        id: KeyId('k1'),
        algorithm: 'HS256',
        secret: utf8.encode('s' * 32),
      ),
    );
    final protocol = _protocol(keys, store);
    final token = protocol.prepare(_proposal(), _now(), _tenant);
    expect(
      () => protocol.commit(
        token: token.value,
        actualArguments: _fingerprint('prepared'),
        currentDescriptor: _digest(),
        principal: 'approver-1',
        now: _now(),
        authorizer: _DeniedAuthorizer(),
      ),
      throwsA(
        isA<ConfirmationFailure>().having(
          (error) => error.error.code,
          'code',
          ConfirmationErrorCodes.authorizationDenied,
        ),
      ),
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

  test('protocol does not declare process local spent set', () {
    final source = File(
      'lib/src/confirmation/confirmation_protocol.dart',
    ).readAsStringSync();
    expect(source.contains('_spentTokens'), isFalse);
  });
}

ConfirmationProtocol _protocol(
  StaticTokenKeyProvider keys,
  InMemoryConfirmationTokenStore store,
) {
  return ConfirmationProtocol(
    keys: keys,
    store: store,
    issuer: 'agentools',
    audience: 'confirmation',
  );
}

Proposal _proposal() {
  return Proposal(
    proposalId: 'prop-1',
    proposalVersion: 1,
    actionId: 'action-1',
    toolName: 'fina_payment_send',
    proposerId: 'proposer-1',
    principal: 'approver-1',
    argsFingerprint: _fingerprint('prepared'),
    descriptorDigest: _digest(),
    expiresAt: _now().add(const Duration(hours: 1)),
    preview: const {'summary': 'Payment', 'changes': <dynamic>[]},
  );
}

ArgumentsFingerprint _fingerprint(String value) =>
    ArgumentsFingerprint.fromJcs(value);

DescriptorDigest _digest() => DescriptorDigest.fromValue('sha-256:${'a' * 64}');

DateTime _now() => DateTime.parse('2026-08-04T12:00:00+00:00');

CommitAuthorizer _authorized() => _AllowAllAuthorizer();

final class _AllowAllAuthorizer implements CommitAuthorizer {
  @override
  bool isAllowed(String principal, String toolName) => true;
}

final class _DeniedAuthorizer implements CommitAuthorizer {
  @override
  bool isAllowed(String principal, String toolName) => false;
}

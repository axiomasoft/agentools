import 'dart:convert';
import 'dart:io';

import 'package:agentools/agentools.dart';
import 'package:test/test.dart';

const _audience = 'agentools-runtime';

CredentialGuard _guard() => CredentialGuard(
  resourceAudience: _audience,
  trustedIssuers: const ['issuer-trusted'],
);

Map<String, dynamic> _credential([Map<String, dynamic> override = const {}]) =>
    {
      'kind': 'access_token',
      'source': 'trusted_credential',
      'issuer': 'issuer-trusted',
      'audience': _audience,
      'integrity': 'verified',
      'principal_id': 'user-7',
      'scopes': ['demo.read'],
    }..addAll(override);

void _expectForeignTokenFailure(
  ToolExecutionResult? result,
  Map<String, String> context,
) {
  expect(result, isNotNull);
  expect(result!.isError, isTrue);
  expect(result.error!.code, OpsErrorCodes.foreignAccessToken);
  expect(result.error!.category, 'permission');
  expect(result.error!.retryable, isFalse);
  expect(result.error!.context, context);
}

Map<String, dynamic> _vector(String revision, String id) {
  final file = File('../../fixtures/$revision/vectors/l1/$id.json');
  return jsonDecode(file.readAsStringSync()) as Map<String, dynamic>;
}

void main() {
  // SEC-2; a credential issued for our resource by a trusted issuer passes.
  test('accepts tokens issued for us', () {
    expect(_guard().validateAccessToken(_credential()), isNull);
  });

  // SEC-1, SEC-2, SEC-5, SEC-6; each rejection names reason, rule and field.
  final rejected = <String, (Map<String, dynamic>, String, String, String)>{
    'confirmation token as access token': (
      {'kind': 'confirmation_token'},
      'credential_role_confusion',
      'SEC-6',
      'kind',
    ),
    'unknown credential kind': (
      {'kind': 'api_key'},
      'unsupported_credential_kind',
      'SEC-1',
      'kind',
    ),
    'missing kind': (
      {'kind': null},
      'unsupported_credential_kind',
      'SEC-1',
      'kind',
    ),
    'token taken from an argument': (
      {'source': 'argument'},
      'data_sourced_access_token',
      'SEC-5',
      'source',
    ),
    'token taken from model output': (
      {'source': 'model_output'},
      'data_sourced_access_token',
      'SEC-5',
      'source',
    ),
    'token taken from a tool result': (
      {'source': 'tool_result'},
      'data_sourced_access_token',
      'SEC-5',
      'source',
    ),
    'missing source': (
      {'source': null},
      'data_sourced_access_token',
      'SEC-5',
      'source',
    ),
    'foreign audience': (
      {'audience': 'downstream-api'},
      'foreign_access_token',
      'SEC-1',
      'audience',
    ),
    'missing audience': (
      {'audience': null},
      'foreign_access_token',
      'SEC-1',
      'audience',
    ),
    'untrusted issuer': (
      {'issuer': 'issuer-rogue'},
      'untrusted_issuer',
      'SEC-2',
      'issuer',
    ),
    'non-string issuer': (
      {
        'issuer': ['issuer-trusted'],
      },
      'untrusted_issuer',
      'SEC-2',
      'issuer',
    ),
    'unverified integrity': (
      {'integrity': 'unverified'},
      'unverified_access_token',
      'SEC-2',
      'integrity',
    ),
    'missing integrity': (
      {'integrity': null},
      'unverified_access_token',
      'SEC-2',
      'integrity',
    ),
  };
  rejected.forEach((name, row) {
    final (override, reason, rule, field) = row;
    test('rejects $name', () {
      _expectForeignTokenFailure(
        _guard().validateAccessToken(_credential(override)),
        {'reason': reason, 'security_rule': rule, 'credential_field': field},
      );
    });
  });

  // SEC-2 item 3; there is no built-in trusted issuer.
  test('has no implicitly trusted issuer', () {
    final guard = CredentialGuard(
      resourceAudience: _audience,
      trustedIssuers: const [],
    );
    _expectForeignTokenFailure(guard.validateAccessToken(_credential()), {
      'reason': 'untrusted_issuer',
      'security_rule': 'SEC-2',
      'credential_field': 'issuer',
    });
  });

  // SEC-2 item 1; the audience is the declared identifier of our resource.
  test('compares audience with the declared resource', () {
    final guard = CredentialGuard(
      resourceAudience: 'agentools',
      trustedIssuers: const ['issuer-trusted'],
    );
    _expectForeignTokenFailure(guard.validateAccessToken(_credential()), {
      'reason': 'foreign_access_token',
      'security_rule': 'SEC-1',
      'credential_field': 'audience',
    });
  });

  test('rejects an empty resource audience', () {
    expect(
      () => CredentialGuard(
        resourceAudience: '',
        trustedIssuers: const ['issuer-trusted'],
      ),
      throwsArgumentError,
    );
  });

  test('rejects an empty trusted issuer', () {
    expect(
      () => CredentialGuard(
        resourceAudience: _audience,
        trustedIssuers: const ['issuer-trusted', ''],
      ),
      throwsArgumentError,
    );
  });

  // SEC-5; tokens smuggled through arguments are rejected.
  test('rejects tokens embedded in arguments', () {
    _expectForeignTokenFailure(
      _guard().rejectTokensInArguments({
        'token': {'kind': 'access_token', 'audience': _audience},
      }),
      {
        'reason': 'data_sourced_access_token',
        'security_rule': 'SEC-5',
        'argument_pointer': '/token',
      },
    );
  });

  // SEC-5; nesting inside maps and lists does not hide a smuggled token.
  test('rejects tokens nested deep in arguments', () {
    _expectForeignTokenFailure(
      _guard().rejectTokensInArguments({
        'payload': {
          'items': [
            {'note': 'x'},
            {
              'auth': {'kind': 'access_token'},
            },
          ],
        },
      }),
      {
        'reason': 'data_sourced_access_token',
        'security_rule': 'SEC-5',
        'argument_pointer': '/payload/items/1/auth',
      },
    );
  });

  test('rejects a token passed as the whole argument object', () {
    _expectForeignTokenFailure(
      _guard().rejectTokensInArguments({'kind': 'access_token'}),
      {
        'reason': 'data_sourced_access_token',
        'security_rule': 'SEC-5',
        'argument_pointer': '',
      },
    );
  });

  test('escapes argument pointer tokens per RFC 6901', () {
    final result = _guard().rejectTokensInArguments({
      'a/b': {
        'c~d': {'kind': 'access_token'},
      },
    });
    expect(result!.error!.context!['argument_pointer'], '/a~1b/c~0d');
  });

  // SEC-5; structures deeper than the inspection limit fail closed.
  test('rejects arguments nested beyond the inspection limit', () {
    Map<String, dynamic> arguments = {'leaf': 'value'};
    for (var i = 0; i < 64; i++) {
      arguments = {'n': arguments};
    }
    final result = _guard().rejectTokensInArguments(arguments);
    expect(result!.error!.context!['reason'], 'argument_depth_exceeded');
    expect(result.error!.context!['argument_pointer'], '/n' * 64);
  });

  test('accepts arguments at the inspection limit', () {
    Map<String, dynamic> arguments = {'leaf': 'value'};
    for (var i = 0; i < 63; i++) {
      arguments = {'n': arguments};
    }
    expect(_guard().rejectTokensInArguments(arguments), isNull);
  });

  test('terminates on cyclic arguments', () {
    final cyclic = <String, dynamic>{};
    cyclic['self'] = cyclic;
    final result = _guard().rejectTokensInArguments(cyclic);
    expect(result!.error!.context!['reason'], 'argument_depth_exceeded');
  });

  test('accepts arguments without tokens', () {
    expect(
      _guard().rejectTokensInArguments({
        'kind': 'invoice',
        'lines': [
          {'kind': 'confirmation'},
          {'amount': 10},
        ],
      }),
      isNull,
    );
  });

  // SEC-6; confirmation tokens cannot be used as access tokens.
  test('rejects confirmation token as access token', () {
    _expectForeignTokenFailure(
      _guard().rejectConfirmationTokenAsAccess('confirmation_token'),
      {'reason': 'credential_role_confusion', 'security_rule': 'SEC-6'},
    );
    expect(_guard().rejectConfirmationTokenAsAccess('access_token'), isNull);
  });

  // Unit replay of credential vectors, independent of the answer catalog.
  for (final revision in ['2026-08', '2026-09']) {
    for (final number in [
      '001',
      '002',
      '003',
      '005',
      '006',
      '101',
      '102',
      '103',
      '105',
      '106',
    ]) {
      final id = 'security-$number';
      test('guard verdict matches $revision/$id', () {
        final vector = _vector(revision, id);
        final given = vector['given'] as Map<String, dynamic>;
        final state = given['driver_state'] as Map<String, dynamic>;
        final credential =
            (state['credentials'] as List<dynamic>).first
                as Map<String, dynamic>;
        final expectation = vector['expect'] as Map<String, dynamic>;
        final result = _guard().validateAccessToken(credential);
        if (expectation['final_state'] != 'failed') {
          expect(result, isNull);
          return;
        }
        final event =
            (expectation['events'] as List<dynamic>).first
                as Map<String, dynamic>;
        final payload = event['payload'] as Map<String, dynamic>;
        final context = result!.error!.context!;
        expect(context['reason'], payload['reason']);
        expect(
          '/given/driver_state/credentials/0/${context['credential_field']}',
          payload['stimulus_pointer'],
        );
        if (payload['security_rule'] != 'SEC-3') {
          // SEC-3 vectors annotate the error-shape rule under test.
          expect(context['security_rule'], payload['security_rule']);
        }
      });
    }
  }
}

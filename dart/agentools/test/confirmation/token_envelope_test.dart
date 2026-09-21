import 'dart:convert';
import 'dart:io';

import 'package:agentools/agentools.dart';
import 'package:crypto/crypto.dart';
import 'package:test/test.dart';

const iss = 'agentools';
const aud = 'confirmation';
const canonical =
    '{"action_id":"act-1","alg":"HS256","args_fingerprint":"fp","aud":"confirmation","descriptor_digest":"dd","expires_at":"2026-09-20T12:00:00+00:00","iat":"2026-09-20T10:00:00+00:00","iss":"agentools","jti":"00000000000000000000000000000001","kid":"k1","nbf":"2026-09-20T10:00:00+00:00","principal":"user-a","proposal_id":"p-1","proposal_version":1,"purpose":"confirmation","tenant_id":"tenant-a","tool_name":"tool","v":1}';

void main() {
  final now = DateTime.parse('2026-09-20T11:00:00+00:00');

  test('canonical bytes match PHP fixture', () {
    expect(TokenEnvelope.canonicalJson(claims()), canonical);
  });

  test('happy round trip', () {
    final issued = TokenEnvelope.issue(claims(), signing());
    final parsed = TokenEnvelope.parse(
      token: issued.value,
      keys: FakeKeys(),
      now: now,
      expectedIss: iss,
      expectedAud: aud,
    );
    expect(parsed.value, issued.value);
    expect(parsed.jti, '00000000000000000000000000000001');
  });

  test('one-bit MAC mutation is rejected', () {
    final issued = TokenEnvelope.issue(claims(), signing()).value;
    final rest = issued.substring(TokenEnvelope.prefix.length);
    final parts = rest.split('.');
    var macB64 = parts[1];
    while (macB64.length % 4 != 0) {
      macB64 += '=';
    }
    final mac = base64Url.decode(macB64);
    mac[0] = mac[0] ^ 1;
    final mutated =
        '${TokenEnvelope.prefix}${parts[0]}.${base64Url.encode(mac).replaceAll('=', '')}';
    expect(
      () => TokenEnvelope.parse(
        token: mutated,
        keys: FakeKeys(),
        now: now,
        expectedIss: iss,
        expectedAud: aud,
      ),
      throwsA(isA<ConfirmationFailure>()),
    );
  });

  test('malformed UTF-8 is normalized to token-invalid', () {
    final malformed =
        '${TokenEnvelope.prefix}${base64Url.encode([0xff]).replaceAll('=', '')}.${base64Url.encode([0]).replaceAll('=', '')}';

    expectTokenInvalid(
      () => TokenEnvelope.parse(
        token: malformed,
        keys: FakeKeys(),
        now: now,
        expectedIss: iss,
        expectedAud: aud,
      ),
    );
  });

  test('malformed authenticated time is normalized to token-invalid', () {
    final malformed = signRaw(
      TokenEnvelope.canonicalJson(claims({'nbf': 'not-a-time'})),
    );

    expectTokenInvalid(
      () => TokenEnvelope.parse(
        token: malformed,
        keys: FakeKeys(),
        now: now,
        expectedIss: iss,
        expectedAud: aud,
      ),
    );
  });

  test('authenticated lifetime above the hard maximum is rejected', () {
    final malformed = signRaw(
      TokenEnvelope.canonicalJson(
        claims({'expires_at': '2026-09-28T10:00:01+00:00'}),
      ),
    );

    expectTokenInvalid(
      () => TokenEnvelope.parse(
        token: malformed,
        keys: FakeKeys(),
        now: now,
        expectedIss: iss,
        expectedAud: aud,
      ),
    );
  });

  test('manifest parse and verify cases', () {
    final root = Directory.current.path.endsWith('agentools')
        ? Directory.current.uri.resolve('../../').toFilePath()
        : Directory.current.path;
    final raw = File(
      '$root/fixtures/hardening/token-replay-v1.json',
    ).readAsStringSync();
    final manifest = jsonDecode(raw) as Map<String, dynamic>;
    final cases = (manifest['cases'] as List).cast<Map<String, dynamic>>();
    for (final caseRow in cases) {
      if (caseRow['phase'] != 'parse' && caseRow['phase'] != 'verify') {
        continue;
      }
      expect(
        () => exercise(caseRow['scenario'] as String, FakeKeys(), now),
        throwsA(
          isA<ConfirmationFailure>().having(
            (e) => e.error.code,
            'code',
            caseRow['semantic_code'],
          ),
        ),
        reason: caseRow['id'] as String,
      );
    }
  });
}

void expectTokenInvalid(void Function() callback) {
  expect(
    callback,
    throwsA(
      isA<ConfirmationFailure>().having(
        (failure) => failure.error.code,
        'code',
        ConfirmationErrorCodes.tokenInvalid,
      ),
    ),
  );
}

Map<String, Object> claims([Map<String, Object> overrides = const {}]) => {
  'v': 1,
  'alg': 'HS256',
  'kid': 'k1',
  'iss': iss,
  'aud': aud,
  'jti': '00000000000000000000000000000001',
  'tenant_id': 'tenant-a',
  'purpose': 'confirmation',
  'action_id': 'act-1',
  'args_fingerprint': 'fp',
  'descriptor_digest': 'dd',
  'expires_at': '2026-09-20T12:00:00+00:00',
  'nbf': '2026-09-20T10:00:00+00:00',
  'iat': '2026-09-20T10:00:00+00:00',
  'principal': 'user-a',
  'proposal_id': 'p-1',
  'proposal_version': 1,
  'tool_name': 'tool',
  ...overrides,
};

SigningKey signing() => SigningKey(
  id: KeyId('k1'),
  algorithm: 'HS256',
  secret: utf8.encode('k' * 32),
);

String signRaw(String signingInput) {
  final mac = Hmac(
    sha256,
    utf8.encode('k' * 32),
  ).convert(utf8.encode(signingInput)).bytes;
  return '${TokenEnvelope.prefix}${base64Url.encode(utf8.encode(signingInput)).replaceAll('=', '')}.${base64Url.encode(mac).replaceAll('=', '')}';
}

void exercise(String scenario, TokenKeyProvider keys, DateTime now) {
  final token = TokenEnvelope.issue(claims(), signing()).value;
  switch (scenario) {
    case 'oversize':
      TokenEnvelope.parse(
        token: 'x' * (TokenEnvelope.maxRawBytes + 1),
        keys: keys,
        now: now,
        expectedIss: iss,
        expectedAud: aud,
      );
    case 'extra_segment':
      TokenEnvelope.parse(
        token: '$token.extra',
        keys: keys,
        now: now,
        expectedIss: iss,
        expectedAud: aud,
      );
    case 'duplicate_json_name':
      TokenEnvelope.parse(
        token:
            '${TokenEnvelope.prefix}${base64Url.encode(utf8.encode('{"v":1,"v":2}')).replaceAll('=', '')}.${base64Url.encode(utf8.encode('sig')).replaceAll('=', '')}',
        keys: keys,
        now: now,
        expectedIss: iss,
        expectedAud: aud,
      );
    case 'unknown_version':
      TokenEnvelope.parse(
        token: signRaw(TokenEnvelope.canonicalJson(claims({'v': 2}))),
        keys: keys,
        now: now,
        expectedIss: iss,
        expectedAud: aud,
      );
    case 'old_two_segment_token':
      TokenEnvelope.parse(
        token: 'payload.sig',
        keys: keys,
        now: now,
        expectedIss: iss,
        expectedAud: aud,
      );
    case 'extra_member':
      final json = TokenEnvelope.canonicalJson(claims());
      TokenEnvelope.parse(
        token: signRaw('${json.substring(0, json.length - 1)},"extra":"x"}'),
        keys: keys,
        now: now,
        expectedIss: iss,
        expectedAud: aud,
      );
    case 'nested_member':
      final json = TokenEnvelope.canonicalJson(claims());
      TokenEnvelope.parse(
        token: signRaw(
          '${json.substring(0, json.length - 1)},"nested":{"a":1}}',
        ),
        keys: keys,
        now: now,
        expectedIss: iss,
        expectedAud: aud,
      );
    case 'missing_required_claim':
      final missing = claims()..remove('jti');
      TokenEnvelope.parse(
        token: signRaw(TokenEnvelope.canonicalJson(missing)),
        keys: keys,
        now: now,
        expectedIss: iss,
        expectedAud: aud,
      );
    case 'unknown_alg':
      TokenEnvelope.parse(
        token: signRaw(TokenEnvelope.canonicalJson(claims({'alg': 'RS256'}))),
        keys: keys,
        now: now,
        expectedIss: iss,
        expectedAud: aud,
      );
    case 'none_alg':
      TokenEnvelope.parse(
        token: signRaw(TokenEnvelope.canonicalJson(claims({'alg': 'none'}))),
        keys: keys,
        now: now,
        expectedIss: iss,
        expectedAud: aud,
      );
    case 'unknown_kid':
      TokenEnvelope.parse(
        token: signRaw(TokenEnvelope.canonicalJson(claims({'kid': 'other'}))),
        keys: keys,
        now: now,
        expectedIss: iss,
        expectedAud: aud,
      );
    case 'retired_kid':
      TokenEnvelope.parse(
        token: token,
        keys: FakeKeys(KeyState.retired),
        now: now,
        expectedIss: iss,
        expectedAud: aud,
      );
    case 'compromised_kid':
      TokenEnvelope.parse(
        token: token,
        keys: FakeKeys(KeyState.compromised),
        now: now,
        expectedIss: iss,
        expectedAud: aud,
      );
    case 'cross_tenant_key':
      TokenEnvelope.parse(
        token: signRaw(
          TokenEnvelope.canonicalJson(claims({'tenant_id': 'tenant-b'})),
        ),
        keys: keys,
        now: now,
        expectedIss: iss,
        expectedAud: aud,
      );
    case 'purpose_mismatch':
      TokenEnvelope.parse(
        token: signRaw(
          TokenEnvelope.canonicalJson(claims({'purpose': 'event'})),
        ),
        keys: keys,
        now: now,
        expectedIss: iss,
        expectedAud: aud,
      );
    case 'iss_mismatch':
      TokenEnvelope.parse(
        token: token,
        keys: keys,
        now: now,
        expectedIss: 'other-iss',
        expectedAud: aud,
      );
    case 'aud_mismatch':
      TokenEnvelope.parse(
        token: token,
        keys: keys,
        now: now,
        expectedIss: iss,
        expectedAud: 'other-aud',
      );
    case 'nbf_skew':
      TokenEnvelope.parse(
        token: token,
        keys: keys,
        now: DateTime.parse('2026-09-20T09:00:00+00:00'),
        expectedIss: iss,
        expectedAud: aud,
      );
    case 'exp_skew':
      TokenEnvelope.parse(
        token: token,
        keys: keys,
        now: DateTime.parse('2026-09-20T13:00:00+00:00'),
        expectedIss: iss,
        expectedAud: aud,
      );
    case 'verify_only_cannot_sign':
      SigningKey(
        id: KeyId('k1'),
        algorithm: 'HS256',
        secret: utf8.encode('k' * 32),
        state: KeyState.verifyOnly,
      );
    default:
      fail('unhandled scenario $scenario');
  }
}

final class FakeKeys implements TokenKeyProvider {
  FakeKeys([this.state = KeyState.active]);

  final KeyState state;

  @override
  SigningKey signingKey(String tenantId, TokenPurpose purpose) {
    if (tenantId != 'tenant-a' || purpose != TokenPurpose.confirmation) {
      throw ConfirmationFailure.keyDenied();
    }
    return signing();
  }

  @override
  VerificationKey? verificationKey(
    String tenantId,
    TokenPurpose purpose,
    KeyId kid,
  ) {
    if (tenantId != 'tenant-a' ||
        purpose != TokenPurpose.confirmation ||
        kid.value != 'k1') {
      return null;
    }
    return VerificationKey(
      id: KeyId('k1'),
      algorithm: 'HS256',
      secret: utf8.encode('k' * 32),
      state: state,
    );
  }
}

import 'dart:convert';
import 'dart:math';

import 'package:crypto/crypto.dart';

import 'confirmation_failure.dart';
import 'key_id.dart';
import 'signing_key.dart';
import 'token_key_provider.dart';
import 'token_purpose.dart';

/// Bounded at1 confirmation-token codec. Dual-read of legacy two-segment tokens is forbidden.
final class TokenEnvelope {
  TokenEnvelope._({
    required this.value,
    required this.claims,
    required this.jti,
    required this.tenantId,
    required this.kid,
  });

  static const prefix = 'at1.';
  static const maxRawBytes = 8192;
  static const algorithm = 'HS256';
  static const version = 1;
  static const defaultSkewSeconds = 60;
  static const maxSkewSeconds = 300;
  static const defaultMaxLifetimeSeconds = 259200;
  static const hardMaxLifetimeSeconds = 604800;
  static const minRetentionSeconds = maxSkewSeconds;
  static const requiredClaims = [
    'v',
    'alg',
    'kid',
    'iss',
    'aud',
    'jti',
    'tenant_id',
    'purpose',
    'action_id',
    'args_fingerprint',
    'descriptor_digest',
    'expires_at',
    'nbf',
    'iat',
    'principal',
    'proposal_id',
    'proposal_version',
    'tool_name',
  ];

  final String value;
  final Map<String, Object> claims;
  final String jti;
  final String tenantId;
  final String kid;

  static TokenEnvelope issue(Map<String, Object> claims, SigningKey key) {
    _assertClosedClaims(claims);
    _assertTimeClaims(claims);
    if (claims['alg'] != key.algorithm || claims['kid'] != key.id.value) {
      throw ConfirmationFailure.keyDenied();
    }
    final signingInput = canonicalJson(claims);
    final mac = Hmac(
      sha256,
      key.secret,
    ).convert(utf8.encode(signingInput)).bytes;
    final value = '$prefix${_b64(utf8.encode(signingInput))}.${_b64(mac)}';
    if (value.length > maxRawBytes) {
      throw ConfirmationFailure.tokenInvalid();
    }
    return TokenEnvelope._(
      value: value,
      claims: claims,
      jti: _stringClaim(claims, 'jti'),
      tenantId: _stringClaim(claims, 'tenant_id'),
      kid: key.id.value,
    );
  }

  static TokenEnvelope parse({
    required String token,
    required TokenKeyProvider keys,
    required DateTime now,
    required String expectedIss,
    required String expectedAud,
    int skewSeconds = defaultSkewSeconds,
  }) {
    if (token.length > maxRawBytes || !token.startsWith(prefix)) {
      throw ConfirmationFailure.tokenInvalid();
    }
    final rest = token.substring(prefix.length);
    final parts = rest.split('.');
    if (parts.length != 2 || parts[0].isEmpty || parts[1].isEmpty) {
      throw ConfirmationFailure.tokenInvalid();
    }
    final signingInput = _decodeSigningInput(parts[0]);
    final mac = _unb64(parts[1]);
    late final Map<String, Object> claims;
    try {
      _rejectDuplicateNames(signingInput);
      final decoded = jsonDecode(signingInput);
      if (decoded is! Map) {
        throw ConfirmationFailure.tokenInvalid();
      }
      claims = {
        for (final entry in decoded.entries)
          entry.key.toString(): entry.value as Object,
      };
    } catch (_) {
      throw ConfirmationFailure.tokenInvalid();
    }
    if (canonicalJson(claims) != signingInput) {
      throw ConfirmationFailure.tokenInvalid();
    }
    _assertClosedClaims(claims);
    _assertTimeClaims(claims);
    late final TokenPurpose purpose;
    try {
      purpose = TokenPurpose.parse(_stringClaim(claims, 'purpose'));
    } catch (_) {
      throw ConfirmationFailure.tokenBindingMismatch();
    }
    final kid = KeyId(_stringClaim(claims, 'kid'));
    final tenantId = _stringClaim(claims, 'tenant_id');
    final key = keys.verificationKey(tenantId, purpose, kid);
    if (key == null ||
        !key.canVerify ||
        key.algorithm != _stringClaim(claims, 'alg')) {
      throw ConfirmationFailure.keyDenied();
    }
    final expected = Hmac(
      sha256,
      key.secret,
    ).convert(utf8.encode(signingInput)).bytes;
    if (!_constantTimeEquals(expected, mac)) {
      throw ConfirmationFailure.tokenInvalid();
    }
    if (_stringClaim(claims, 'iss') != expectedIss ||
        _stringClaim(claims, 'aud') != expectedAud) {
      throw ConfirmationFailure.tokenBindingMismatch();
    }
    final skew = skewSeconds.clamp(0, maxSkewSeconds);
    final nbf = _timeClaim(claims, 'nbf');
    final exp = _timeClaim(claims, 'expires_at');
    final nowUtc = now.toUtc();
    if (nowUtc.millisecondsSinceEpoch ~/ 1000 + skew <
        nbf.millisecondsSinceEpoch ~/ 1000) {
      throw ConfirmationFailure.notYetValid();
    }
    if (nowUtc.millisecondsSinceEpoch ~/ 1000 - skew >=
        exp.millisecondsSinceEpoch ~/ 1000) {
      throw ConfirmationFailure.expired();
    }
    return TokenEnvelope._(
      value: token,
      claims: claims,
      jti: _stringClaim(claims, 'jti'),
      tenantId: tenantId,
      kid: kid.value,
    );
  }

  static String newJti() {
    final random = Random.secure();
    final bytes = List<int>.generate(16, (_) => random.nextInt(256));
    return bytes.map((b) => b.toRadixString(16).padLeft(2, '0')).join();
  }

  static String canonicalJson(Map<String, Object> claims) {
    final keys = claims.keys.toList()..sort();
    final members = <String>[];
    for (final key in keys) {
      members.add('${_encodeString(key)}:${_encodeValue(claims[key]!, key)}');
    }
    return '{${members.join(',')}}';
  }

  static void _assertClosedClaims(Map<String, Object> claims) {
    if (claims.length != requiredClaims.length) {
      throw ConfirmationFailure.tokenInvalid();
    }
    for (final name in requiredClaims) {
      if (!claims.containsKey(name)) {
        throw ConfirmationFailure.tokenInvalid();
      }
    }
    if (claims['v'] != version) {
      throw ConfirmationFailure.tokenInvalid();
    }
    final alg = claims['alg'];
    if (alg is! String || alg == 'none' || alg != algorithm) {
      throw ConfirmationFailure.keyDenied();
    }
    for (final name in [
      'kid',
      'iss',
      'aud',
      'jti',
      'tenant_id',
      'purpose',
      'action_id',
      'args_fingerprint',
      'descriptor_digest',
      'expires_at',
      'nbf',
      'iat',
      'principal',
      'proposal_id',
      'tool_name',
    ]) {
      final value = claims[name];
      if (value is! String || value.isEmpty) {
        throw ConfirmationFailure.tokenInvalid();
      }
    }
    final versionField = claims['proposal_version'];
    if (versionField is! int || versionField < 1) {
      throw ConfirmationFailure.tokenInvalid();
    }
    try {
      TokenPurpose.parse(claims['purpose']! as String);
    } catch (_) {
      throw ConfirmationFailure.tokenBindingMismatch();
    }
    for (final value in claims.values) {
      if (value is Map || value is List) {
        throw ConfirmationFailure.tokenInvalid();
      }
    }
  }

  static String _stringClaim(Map<String, Object> claims, String name) {
    final value = claims[name];
    if (value is! String || value.isEmpty) {
      throw ConfirmationFailure.tokenInvalid();
    }
    return value;
  }

  static DateTime _timeClaim(Map<String, Object> claims, String name) {
    final value = _stringClaim(claims, name);
    if (!RegExp(
      r'^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$',
    ).hasMatch(value)) {
      throw ConfirmationFailure.tokenInvalid();
    }
    try {
      return DateTime.parse(value).toUtc();
    } catch (_) {
      throw ConfirmationFailure.tokenInvalid();
    }
  }

  static void _assertTimeClaims(Map<String, Object> claims) {
    final iat = _timeClaim(claims, 'iat');
    _timeClaim(claims, 'nbf');
    final expiresAt = _timeClaim(claims, 'expires_at');
    final lifetime = expiresAt.difference(iat).inSeconds;
    if (lifetime < 1 || lifetime > hardMaxLifetimeSeconds) {
      throw ConfirmationFailure.tokenInvalid();
    }
  }

  static String _encodeValue(Object value, String key) {
    if (value is String) {
      return _encodeString(value);
    }
    if (value is int) {
      return '$value';
    }
    throw ConfirmationFailure.tokenInvalid();
  }

  static String _encodeString(String value) {
    final out = StringBuffer('"');
    for (final unit in value.codeUnits) {
      if (unit == 0x22 || unit == 0x5c) {
        out.write('\\${String.fromCharCode(unit)}');
      } else if (unit < 0x20) {
        out.write('\\u${unit.toRadixString(16).padLeft(4, '0')}');
      } else {
        out.writeCharCode(unit);
      }
    }
    out.write('"');
    return out.toString();
  }

  static String _b64(List<int> raw) =>
      base64Url.encode(raw).replaceAll('=', '');

  static List<int> _unb64(String value) {
    final padding = '=' * ((4 - value.length % 4) % 4);
    try {
      return base64Url.decode('$value$padding');
    } catch (_) {
      throw ConfirmationFailure.tokenInvalid();
    }
  }

  static String _decodeSigningInput(String value) {
    try {
      return utf8.decode(_unb64(value));
    } on ConfirmationFailure {
      rethrow;
    } catch (_) {
      throw ConfirmationFailure.tokenInvalid();
    }
  }

  static bool _constantTimeEquals(List<int> a, List<int> b) {
    if (a.length != b.length) {
      return false;
    }
    var result = 0;
    for (var i = 0; i < a.length; i++) {
      result |= a[i] ^ b[i];
    }
    return result == 0;
  }

  static void _rejectDuplicateNames(String json) {
    var i = 0;
    final n = json.length;
    i = _skipWs(json, i, n);
    if (i >= n || json[i] != '{') {
      throw ConfirmationFailure.tokenInvalid();
    }
    i++;
    final keys = <String>{};
    i = _skipWs(json, i, n);
    if (i < n && json[i] == '}') {
      return;
    }
    while (i < n) {
      i = _skipWs(json, i, n);
      final read = _readJsonString(json, i, n);
      if (!keys.add(read.$1)) {
        throw ConfirmationFailure.tokenInvalid();
      }
      i = _skipWs(json, read.$2, n);
      if (i >= n || json[i] != ':') {
        throw ConfirmationFailure.tokenInvalid();
      }
      i++;
      i = _skipJsonValue(json, i, n);
      i = _skipWs(json, i, n);
      if (i < n && json[i] == ',') {
        i++;
        continue;
      }
      if (i < n && json[i] == '}') {
        return;
      }
      throw ConfirmationFailure.tokenInvalid();
    }
    throw ConfirmationFailure.tokenInvalid();
  }

  static int _skipWs(String json, int i, int n) {
    while (i < n && _isWs(json.codeUnitAt(i))) {
      i++;
    }
    return i;
  }

  static bool _isWs(int unit) =>
      unit == 0x20 || unit == 0x09 || unit == 0x0a || unit == 0x0d;

  static (String, int) _readJsonString(String json, int i, int n) {
    if (i >= n || json[i] != '"') {
      throw ConfirmationFailure.tokenInvalid();
    }
    i++;
    final out = StringBuffer();
    while (i < n) {
      final c = json[i];
      i++;
      if (c == '"') {
        return (out.toString(), i);
      }
      if (c == '\\') {
        if (i >= n) {
          throw ConfirmationFailure.tokenInvalid();
        }
        out.write('\\${json[i]}');
        i++;
        continue;
      }
      out.write(c);
    }
    throw ConfirmationFailure.tokenInvalid();
  }

  static int _skipJsonValue(String json, int i, int n) {
    i = _skipWs(json, i, n);
    if (i >= n) {
      throw ConfirmationFailure.tokenInvalid();
    }
    if (json[i] == '"') {
      return _readJsonString(json, i, n).$2;
    }
    if (json[i] == '{') {
      var depth = 0;
      while (i < n) {
        if (json[i] == '{') {
          depth++;
          i++;
        } else if (json[i] == '}') {
          depth--;
          i++;
          if (depth == 0) {
            return i;
          }
        } else if (json[i] == '"') {
          i = _readJsonString(json, i, n).$2;
        } else {
          i++;
        }
      }
      throw ConfirmationFailure.tokenInvalid();
    }
    if (json[i] == '[') {
      throw ConfirmationFailure.tokenInvalid();
    }
    while (i < n &&
        json[i] != ',' &&
        json[i] != '}' &&
        json[i] != ']' &&
        !_isWs(json.codeUnitAt(i))) {
      i++;
    }
    return i;
  }
}

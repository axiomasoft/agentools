import 'dart:convert';
import 'dart:io';

import 'package:agentools/agentools.dart';
import 'package:agentools/agentools_testkit.dart';

void main() {
  while (true) {
    final line = stdin.readLineSync();
    if (line == null) {
      break;
    }
    if (line.trim().isEmpty) {
      continue;
    }
    final request = jsonDecode(line) as Map<String, dynamic>;
    final result = evaluateCase(request);
    result['case_id'] = request['case_id'];
    stdout.writeln(jsonEncode(result));
  }
}

Map<String, Object?> evaluateCase(Map<String, dynamic> request) {
  final op = request['op'] as String? ?? '';
  final inject = request['inject'] as String? ?? '';
  switch (op) {
    case 'jcs':
      return evalJcs(request['json'] as String, inject);
    case 'expression':
      return evalExpression(request['source'] as String);
    case 'baggage':
      return evalBaggage(List<String>.from(request['ids'] as List));
    case 'token_parse':
      return evalToken(
        request['token'] as String,
        request['now'] as String? ?? '2026-09-20T11:00:00+00:00',
      );
    default:
      return {'ok': false, 'class': 'reject.unknown_op'};
  }
}

Map<String, Object?> evalJcs(String json, String inject) {
  try {
    if (inject == 'dart-skip-dupes') {
      final decoded = jsonDecode(json);
      final bytes = Canonicalizer().canonicalize(decoded);
      return {'ok': true, 'class': 'accept', 'bytes': bytes};
    }
    final bytes = Canonicalizer().canonicalizeJson(json);
    final again = Canonicalizer().canonicalizeJson(bytes);
    if (again != bytes) {
      return {'ok': false, 'class': 'reject.not_idempotent', 'bytes': bytes};
    }
    return {'ok': true, 'class': 'accept', 'bytes': bytes};
  } on CanonicalizationException catch (error) {
    final className = error.message.contains('duplicate')
        ? 'reject.duplicate_name'
        : 'reject.invalid_json';
    return {'ok': false, 'class': className};
  } on FormatException {
    return {'ok': false, 'class': 'reject.invalid_json'};
  }
}

Map<String, Object?> evalExpression(String source) {
  try {
    final value = ExpressionEvaluator().evaluate(source, {});
    return {'ok': true, 'class': 'accept', 'value': value};
  } on ExpressionException catch (error) {
    return {
      'ok': false,
      'class': 'reject.limit',
      'code': error.limitName ?? 'expression.invalid',
    };
  }
}

Map<String, Object?> evalBaggage(List<String> ids) {
  try {
    var baggage = BaggageRefs();
    for (final id in ids) {
      baggage = baggage.add(id);
    }
    return {'ok': true, 'class': 'accept', 'count': baggage.refs().length};
  } on BaggageLimitException {
    return {
      'ok': false,
      'class': 'reject.limit',
      'code': OpsErrorCodes.baggageLimitExceeded,
    };
  } on ArgumentError {
    return {'ok': false, 'class': 'reject.invalid_id'};
  }
}

Map<String, Object?> evalToken(String token, String now) {
  final keys = StaticTokenKeyProvider(
    SigningKey(
      id: KeyId('k1'),
      algorithm: 'HS256',
      secret: List<int>.filled(32, 0x6b),
    ),
  );
  try {
    TokenEnvelope.parse(
      token: token,
      keys: keys,
      now: DateTime.parse(now),
      expectedIss: 'agentools',
      expectedAud: 'confirmation',
    );
    return {'ok': true, 'class': 'accept'};
  } on ConfirmationFailure catch (failure) {
    return {
      'ok': false,
      'class': 'reject.token',
      'code': failure.error.code,
    };
  } on FormatException {
    return {
      'ok': false,
      'class': 'reject.token',
      'code': ConfirmationErrorCodes.tokenInvalid,
    };
  } catch (_) {
    return {
      'ok': false,
      'class': 'reject.token',
      'code': ConfirmationErrorCodes.tokenInvalid,
    };
  }
}

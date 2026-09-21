import 'dart:convert';
import 'dart:io';

import 'package:agentools/agentools.dart';
import 'package:agentools/agentools_testkit.dart';
import 'package:benchmark_harness/benchmark_harness.dart';

/// One-call exercise: default [BenchmarkBase.exercise] repeats [run] ten times.
/// P8.5 times a single call; [measure] is never used.
final class OneCallWorkload extends BenchmarkBase {
  OneCallWorkload(this._body)
    : super('p8.5-one-call', emitter: const _SilentEmitter());

  final void Function() _body;

  @override
  void run() => _body();

  @override
  void exercise() => run();
}

final class _SilentEmitter implements ScoreEmitter {
  const _SilentEmitter();

  @override
  void emit(String testName, double value) {}
}

final class _DummyTools implements ToolPort {
  @override
  ToolExecutionResult call(
    String tool,
    Map<String, dynamic> arguments,
    String idempotencyKey,
  ) {
    return ToolExecutionResult.success({'ok': true});
  }
}

Map<String, dynamic> descriptorDocument() {
  return {
    'name': 'fina_payment_send',
    'title': 'Отправить платёж',
    'description':
        'Purpose: Отправляет платёж во внешнюю систему.\nCall when: Подтверждённый платёж готов к отправке.\nRequired arguments: amount_minor\nExample: fina_payment_send({"amount_minor":12500})',
    'inputSchema': {
      'type': 'object',
      'properties': {
        'amount_minor': {'type': 'integer'},
      },
    },
    'outputSchema': {
      'type': 'object',
      'properties': {
        'payment_id': {'type': 'string'},
      },
    },
    'annotations': {
      'readOnlyHint': false,
      'destructiveHint': true,
      'idempotentHint': true,
      'openWorldHint': true,
    },
    '_meta': {
      'dev.agentools/action': {
        'side_effect': 'irreversible',
        'confirmation': 'double_confirm',
        'idempotent_commit': true,
        'permissions_hint': ['fina.payment.send'],
        'content_origin': 'untrusted_external',
      },
    },
  };
}

Map<String, Object?> constants() {
  return {
    'ok': true,
    'class': 'accept',
    'constants': {
      'expression.max_depth': 32,
      'expression.max_length': 512,
      'expression.max_collection_size': 1000,
      'expression.max_visited_nodes': 10000,
      'scenario.max_steps': 64,
      'baggage.max_items': 64,
      'baggage.max_bytes': 8192,
      'token.max_raw_bytes': TokenEnvelope.maxRawBytes,
      'token.default_skew_seconds': TokenEnvelope.defaultSkewSeconds,
      'token.default_max_lifetime_seconds':
          TokenEnvelope.defaultMaxLifetimeSeconds,
      'token.min_retention_seconds': TokenEnvelope.minRetentionSeconds,
      'token.max_skew_seconds': TokenEnvelope.maxSkewSeconds,
      'token.json_decode_depth': null,
      'canonicalizer.json_decode_depth': null,
      'event.default_page': InMemoryEventStore.defaultPage,
      'event.max_page': InMemoryEventStore.maxPage,
      'event.max_batch': InMemoryEventStore.maxBatch,
      'lease.default_seconds': LeaseDuration.defaultSeconds,
      'lease.max_seconds': LeaseDuration.maxSeconds,
    },
  };
}

Map<String, Object?> evalExpression(
  String source,
  Map<String, dynamic> context,
  Map<String, dynamic> request,
) {
  try {
    final limits = ExpressionLimits(
      maxDepth: request['max_depth'] as int? ?? 32,
      maxLength: request['max_length'] as int? ?? 512,
      maxCollectionSize: request['max_collection'] as int? ?? 1000,
      maxVisitedNodes: request['max_visited'] as int? ?? 10000,
    );
    final value = ExpressionEvaluator(limits: limits).evaluate(source, context);
    return {'ok': true, 'class': 'accept', 'value': value};
  } on ExpressionException catch (error) {
    return {
      'ok': false,
      'class': 'reject.limit',
      'code': error.limitName ?? 'expression.invalid',
    };
  } catch (_) {
    return {'ok': false, 'class': 'reject.invalid_expression'};
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
    return {'ok': false, 'class': 'reject.limit', 'code': 'baggage'};
  } on ArgumentError {
    return {'ok': false, 'class': 'reject.invalid_id'};
  }
}

Map<String, Object?> evalToken(String token) {
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
      now: DateTime.parse('2026-09-20T11:00:00+00:00'),
      expectedIss: 'agentools',
      expectedAud: 'confirmation',
    );
    return {'ok': true, 'class': 'accept'};
  } on ConfirmationFailure catch (failure) {
    final oversize = token.length > TokenEnvelope.maxRawBytes;
    return {
      'ok': false,
      'class': oversize ? 'reject.limit' : 'reject.token',
      'code': failure.error.code,
    };
  } catch (_) {
    final oversize = token.length > TokenEnvelope.maxRawBytes;
    return {'ok': false, 'class': oversize ? 'reject.limit' : 'reject.token'};
  }
}

Map<String, Object?> evalJcs(String json) {
  try {
    final bytes = Canonicalizer().canonicalizeJson(json);
    return {
      'ok': true,
      'class': 'accept',
      'bytes': bytes,
      'output_bytes': bytes.length,
    };
  } catch (_) {
    return {'ok': false, 'class': 'reject.invalid_json'};
  }
}

Map<String, Object?> evalDescriptor() {
  try {
    final descriptor = ToolDescriptor.fromMap(descriptorDocument());
    final digest = descriptor.digest(Canonicalizer());
    return {'ok': true, 'class': 'accept', 'digest': digest.value};
  } catch (error) {
    return {
      'ok': false,
      'class': 'reject.descriptor',
      'error': error.toString(),
    };
  }
}

Map<String, Object?> evalEventPage(int limit) {
  try {
    InMemoryEventStore().load('t', 'a', limit: limit);
    return {'ok': true, 'class': 'accept', 'limit': limit};
  } on EventStoreFailure {
    return {'ok': false, 'class': 'reject.limit', 'code': 'page'};
  }
}

Map<String, Object?> evalLease(int seconds) {
  try {
    LeaseDuration.assertValid(Duration(seconds: seconds));
    return {'ok': true, 'class': 'accept', 'seconds': seconds};
  } catch (_) {
    return {'ok': false, 'class': 'reject.limit', 'code': 'lease'};
  }
}

Map<String, Object?> evalScenarioSteps(int count) {
  final steps = <Map<String, Object?>>[
    for (var i = 0; i < count; i++)
      {'id': 's$i', 'tool': 'demo_tool', 'input_map': <String, Object?>{}},
  ];
  try {
    ScenarioRunner().run(
      {'spec_rev': '2026-08', 'scenario_id': 'bench', 'steps': steps},
      <String, dynamic>{},
      _DummyTools(),
    );
    return {'ok': true, 'class': 'accept', 'count': count};
  } on ExpressionException catch (error) {
    return {
      'ok': false,
      'class': 'reject.limit',
      'code': error.limitName ?? 'scenario.max_steps',
    };
  } catch (error) {
    return {'ok': false, 'class': 'reject.invalid', 'error': error.toString()};
  }
}

Map<String, Object?> evalEventFold(int events) {
  InMemoryEventStore().load('tenant', 'action');
  final bytes = Canonicalizer().canonicalize({
    'events': events,
    'k': [for (var i = 0; i <= events.clamp(0, 8); i++) i],
  });
  return {
    'ok': true,
    'class': 'accept',
    'output_bytes': bytes.length,
    'count': events,
  };
}

Map<String, Object?> dispatch(Map<String, dynamic> request) {
  final op = request['op'] as String? ?? '';
  switch (op) {
    case 'constants':
      return constants();
    case 'expression':
      final context = request['context'];
      return evalExpression(
        request['source'] as String? ?? '',
        context is Map<String, dynamic> ? context : <String, dynamic>{},
        request,
      );
    case 'baggage':
      final ids = request['ids'];
      return evalBaggage([
        if (ids is List) ...ids.map((value) => value.toString()),
      ]);
    case 'token_parse':
      return evalToken(request['token'] as String? ?? '');
    case 'jcs':
      return evalJcs(request['json'] as String? ?? '');
    case 'descriptor':
      return evalDescriptor();
    case 'event_page':
      return evalEventPage(
        request['limit'] as int? ?? InMemoryEventStore.defaultPage,
      );
    case 'lease':
      return evalLease(
        request['seconds'] as int? ?? LeaseDuration.defaultSeconds,
      );
    case 'scenario_steps':
      return evalScenarioSteps(request['count'] as int? ?? 1);
    case 'event_fold':
      return evalEventFold(request['events'] as int? ?? 1);
    case 'timed':
      return timed(request);
    default:
      return {'ok': false, 'class': 'reject.unknown_op'};
  }
}

Map<String, Object?> timed(Map<String, dynamic> request) {
  final innerRaw = request['inner'];
  if (innerRaw is! Map<String, dynamic>) {
    return {'ok': false, 'class': 'reject.unknown_op'};
  }
  final warmup = (request['warmup'] as int?) ?? 0;
  final samples = (request['samples'] as int?) ?? 1;
  for (var i = 0; i < warmup; i++) {
    dispatch(innerRaw);
  }
  final raw = <int>[];
  Map<String, Object?> last = {'ok': false, 'class': 'reject'};
  final workload = OneCallWorkload(() {
    last = dispatch(innerRaw);
  });
  for (var i = 0; i < samples; i++) {
    final watch = Stopwatch()..start();
    workload.exercise();
    watch.stop();
    raw.add(watch.elapsedMicroseconds * 1000);
  }
  return {
    'ok': last['ok'] == true,
    'class': last['class'] ?? 'reject',
    'inner': last,
    'raw_ns': raw,
    'one_call': true,
    'benchmark_harness': '2.4.0',
    'exercise_semantics': 'one-call',
  };
}

void main(List<String> args) {
  final raw = args.isNotEmpty ? args.first : stdin.readLineSync();
  if (raw == null || raw.trim().isEmpty) {
    stderr.writeln('empty request');
    exit(2);
  }
  final request = jsonDecode(raw) as Map<String, dynamic>;
  stdout.writeln(jsonEncode(dispatch(request)));
}

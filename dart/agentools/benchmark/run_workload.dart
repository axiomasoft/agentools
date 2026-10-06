import 'dart:convert';
import 'dart:io';

import 'package:agentools/agentools.dart';
import 'package:agentools/agentools_testkit.dart';
import 'package:benchmark_harness/benchmark_harness.dart';
import 'package:crypto/crypto.dart';

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

Map<String, Object?> internalFailure(Object error) => {
  'ok': false,
  'class': 'error.internal',
  'diagnostic_class': error.runtimeType.toString(),
};

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
    return error.limitName == null
        ? {'ok': false, 'class': 'reject.syntax', 'code': 'expression.syntax'}
        : {'ok': false, 'class': 'reject.limit', 'code': error.limitName};
  } catch (error) {
    return internalFailure(error);
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
    return {'ok': false, 'class': 'reject.claim', 'code': 'baggage.id'};
  } catch (error) {
    return internalFailure(error);
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
    if (oversize) {
      return {'ok': false, 'class': 'reject.limit', 'code': failure.error.code};
    }
    if (!token.startsWith('at1.') || '.'.allMatches(token).length != 2) {
      return {
        'ok': false,
        'class': 'reject.syntax',
        'code': failure.error.code,
      };
    }
    final code = failure.error.code;
    final classification =
        code.contains('key_denied') ||
            code.contains('binding_mismatch') ||
            code.contains('token_invalid')
        ? 'reject.signature'
        : 'reject.claim';
    return {'ok': false, 'class': classification, 'code': code};
  } catch (error) {
    return internalFailure(error);
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
  } on FormatException {
    return {'ok': false, 'class': 'reject.syntax', 'code': 'json.syntax'};
  } on ArgumentError {
    return {'ok': false, 'class': 'reject.syntax', 'code': 'json.syntax'};
  } catch (error) {
    return internalFailure(error);
  }
}

Map<String, Object?> evalDescriptor() {
  try {
    final descriptor = ToolDescriptor.fromMap(descriptorDocument());
    final digest = descriptor.digest(Canonicalizer());
    return {'ok': true, 'class': 'accept', 'digest': digest.value};
  } on ArgumentError {
    return {'ok': false, 'class': 'reject.claim', 'code': 'descriptor.claim'};
  } catch (error) {
    return internalFailure(error);
  }
}

Map<String, Object?> evalEventPage(int limit) {
  try {
    InMemoryEventStore().load('t', 'a', limit: limit);
    return {'ok': true, 'class': 'accept', 'limit': limit};
  } on EventStoreFailure {
    return {'ok': false, 'class': 'reject.limit', 'code': 'page'};
  } catch (error) {
    return internalFailure(error);
  }
}

Map<String, Object?> evalLease(int seconds) {
  try {
    LeaseDuration.assertValid(Duration(seconds: seconds));
    return {'ok': true, 'class': 'accept', 'seconds': seconds};
  } on ArgumentError {
    return {'ok': false, 'class': 'reject.limit', 'code': 'lease'};
  } catch (error) {
    return internalFailure(error);
  }
}

Map<String, Object?> evalScenarioSteps(int count) {
  final steps = <Map<String, Object?>>[
    for (var i = 0; i < count; i++)
      {'id': 's$i', 'tool': 'demo_tool', 'input_map': <String, Object?>{}},
  ];
  try {
    ScenarioRunner().run(
      {'spec_rev': '2026-09', 'scenario_id': 'bench', 'steps': steps},
      <String, dynamic>{},
      _DummyTools(),
      IdempotencyKey('at1:benchmark-run'),
    );
    return {'ok': true, 'class': 'accept', 'count': count};
  } on ExpressionException catch (error) {
    return {
      'ok': false,
      'class': 'reject.limit',
      'code': error.limitName ?? 'scenario.max_steps',
    };
  } on ArgumentError {
    return {'ok': false, 'class': 'reject.claim', 'code': 'scenario.claim'};
  } catch (error) {
    return internalFailure(error);
  }
}

Map<String, Object?> evalEventFold(int events, {bool injectInternal = false}) {
  try {
    if (injectInternal) {
      throw StateError('injected benchmark failure');
    }
    if (events < 1) {
      return {'ok': false, 'class': 'reject.claim', 'code': 'events.minimum'};
    }

    final store = InMemoryEventStore();
    final actor = Actor(type: 'system', id: 'resource-gate');
    final pending = [
      for (var index = 1; index <= events; index++)
        UncommittedEvent(
          eventId: 'benchid-${index.toString().padLeft(8, '0')}',
          kind: index == 1 ? 'action_created' : 'benchmark_tick',
          eventVersion: 1,
          specRevision: '2026-09',
          actor: actor,
          timestamp: '2026-09-22T00:00:00+00:00',
          payload: index == 1 ? {} : {'index': index},
        ),
    ];

    final appendWatch = Stopwatch()..start();
    var expected = 0;
    for (
      var offset = 0;
      offset < pending.length;
      offset += InMemoryEventStore.maxBatch
    ) {
      final end = (offset + InMemoryEventStore.maxBatch).clamp(
        0,
        pending.length,
      );
      final result = store.append(
        'tenant',
        'benchmark-action',
        expected,
        pending.sublist(offset, end),
      );
      expected = result.lastSeq;
    }
    appendWatch.stop();

    final loadWatch = Stopwatch()..start();
    final loaded = <ActionEvent>[];
    var after = 0;
    bool hasMore;
    do {
      final page = store.load(
        'tenant',
        'benchmark-action',
        afterSeq: after,
        limit: InMemoryEventStore.maxPage,
      );
      loaded.addAll(page.events);
      after = page.lastSeq;
      hasMore = page.hasMore;
    } while (hasMore);
    loadWatch.stop();

    final foldWatch = Stopwatch()..start();
    final state = ActionReducer(specRevision: '2026-09').fold(loaded);
    foldWatch.stop();
    final stateDocument = <String, Object?>{
      'action_id': state.actionId,
      'status': state.status?.value,
      'seq': state.seq,
      'epoch': state.epoch,
      'inputs_count': state.inputs.length,
      'effects_count': state.effects.length,
      'cancellation_requested': state.cancellationRequested,
    };
    final bytes = Canonicalizer().canonicalize(stateDocument);
    return {
      'ok': true,
      'class': 'accept',
      'appended_count': expected,
      'loaded_count': loaded.length,
      'folded_count': state.seq,
      'final_seq': state.seq,
      'digest': sha256.convert(utf8.encode(bytes)).toString(),
      'output_bytes': utf8.encode(bytes).length,
      'timing_ns': {
        'append': appendWatch.elapsedMicroseconds * 1000,
        'load': loadWatch.elapsedMicroseconds * 1000,
        'fold': foldWatch.elapsedMicroseconds * 1000,
      },
    };
  } catch (error) {
    return internalFailure(error);
  }
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
      return evalEventFold(
        request['events'] as int? ?? 1,
        injectInternal: request['inject_internal'] == true,
      );
    case 'timed':
      return timed(request);
    default:
      return {
        'ok': false,
        'class': 'reject.unsupported',
        'code': 'operation.unsupported',
      };
  }
}

Map<String, Object?> timed(Map<String, dynamic> request) {
  final innerRaw = request['inner'];
  if (innerRaw is! Map<String, dynamic>) {
    return {'ok': false, 'class': 'reject.claim', 'code': 'timed.inner'};
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
  try {
    final decoded = jsonDecode(raw);
    if (decoded is! Map<String, dynamic>) {
      stdout.writeln(
        jsonEncode({
          'ok': false,
          'class': 'reject.claim',
          'code': 'request.object',
        }),
      );
      return;
    }
    stdout.writeln(jsonEncode(dispatch(decoded)));
  } on FormatException {
    stdout.writeln(
      jsonEncode({
        'ok': false,
        'class': 'reject.syntax',
        'code': 'request.json',
      }),
    );
  } catch (error) {
    stdout.writeln(jsonEncode(internalFailure(error)));
  }
}

import 'dart:convert';
import 'dart:io';
import 'package:agentools/platform_usage.dart';

Map<String, Object?> fixture(String name) =>
    (jsonDecode(
          File(
            '../../fixtures/platform/v1/usage/$name.json',
          ).readAsStringSync(),
        )
        as Map<String, Object?>);
Map<String, Object?> object(Object? value) => value as Map<String, Object?>;
void check(bool condition) {
  if (!condition) throw StateError('Usage contract assertion failed');
}

void rejects(void Function() run) {
  try {
    run();
  } catch (error) {
    if (error is UsageConflict) return;
    rethrow;
  }
  throw StateError('Missing usage payload conflict');
}

UsageEvent event([
  Map<String, Object?> raw = const {},
  Map<String, Object?> meta = const {},
  String invocation = 'inv-1',
  int version = 1,
  bool? outbound = true,
]) => const UsageNormalizer()
    .observe(
      {...fixture('metadata'), ...meta},
      invocation,
      version,
      UsageProducer.transport,
      UsageProducer.transport,
      UsageMapping.fromJson(fixture('mapping')),
      raw,
      outbound,
    )
    .event!;

List<String> portableIds() => [
  for (final c in fixture('normalization')['cases'] as List<Object?>)
    object(c)['id'] as String,
  'ECO-USE-002',
  'ECO-USE-003',
  'ECO-USE-009',
  'V2-P9-2-FALLBACK-NEG',
  'ECO-USE-004',
  'ECO-USE-007',
  'V2-P9-2-FALLBACK-POS',
  'budget.result-codec',
];
Map<String, Object?> portableCase(String id) {
  for (final raw in fixture('normalization')['cases'] as List<Object?>) {
    final c = object(raw);
    if (c['id'] != id) continue;
    final e = event(
      object(c['raw']),
      {'certainty': c['certainty']},
      'inv-1',
      1,
      c['outbound_proven'] as bool?,
    );
    check(
      jsonEncode(e.units) == jsonEncode(c['expected_units']) &&
          jsonEncode(e.toJson()['provider_amount']) ==
              jsonEncode(c['expected_amount']),
    );
    return {'event': e.toJson(), 'hash': e.hash, 'ack': e.acknowledgement};
  }
  if (id == 'ECO-USE-002') {
    final e = event({'output': 12});
    final p = UsageFold.fold([e, e, e]);
    check(p.active.length == 1 && p.history.length == 1);
    return {
      'active': p.active.length,
      'history': p.history.length,
      'ack': e.acknowledgement,
    };
  }
  if (['ECO-USE-003', 'ECO-USE-009', 'V2-P9-2-FALLBACK-NEG'].contains(id)) {
    final audio = id == 'ECO-USE-009';
    final a = event(
      audio ? {'audio_input': '1.250', 'audio_unit': 'second'} : {'output': 12},
    );
    final b = event(
      audio ? {'audio_input': '1.251', 'audio_unit': 'second'} : {'output': 13},
    );
    rejects(() => UsageFold.fold([a, b]));
    return {
      'error': 'USAGE_PAYLOAD_CONFLICT',
      'quarantine': true,
      'additional_units': 0,
      'original': a.units,
    };
  }
  if (id == 'ECO-USE-004') {
    final a = event({}, {'certainty': 'preliminary'});
    final b = event(
      {'output': 12},
      {'event_id': 'usage-inv-1-v2', 'supersedes_event_id': a.eventId},
      'inv-1',
      2,
    );
    final p = UsageFold.fold([b, a, b]);
    check(p.active.length == 1 && p.history.length == 2);
    return {
      'active': 1,
      'history': 2,
      'event': p.active.values.single.toJson(),
      'original': a.units,
    };
  }
  if (id == 'ECO-USE-007') {
    var canonical = 0;
    var telemetry = 0;
    for (final observer in UsageProducer.values) {
      final r = const UsageNormalizer().observe(
        fixture('metadata'),
        'inv-1',
        1,
        UsageProducer.transport,
        observer,
        UsageMapping.fromJson(fixture('mapping')),
        {},
        true,
      );
      if (r.event != null) canonical++;
      if (r.telemetryOnly) telemetry++;
    }
    check(canonical == 1 && telemetry == 1);
    return {'canonical': canonical, 'telemetry': telemetry};
  }
  if (id == 'V2-P9-2-FALLBACK-POS') {
    final a = event({}, {'certainty': 'unknown'});
    final b = event(
      {'output': 7},
      {'event_id': 'usage-fallback-v1', 'deployment_ref': 'alternate-fixture'},
      'inv-2',
    );
    final p = UsageFold.fold([a, b]);
    check(
      p.active.length == 2 &&
          a.toJson()['business_operation_ref'] ==
              b.toJson()['business_operation_ref'],
    );
    return {
      'active': 2,
      'business_operation_ref': a.toJson()['business_operation_ref'],
      'request_counts': [1, 1],
      'original': a.units,
    };
  }
  if (id == 'budget.result-codec') {
    final d = fixture('admission-result');
    check(UsageAdmissionResult.fromJson(d).permits('2026-11-02T10:00:00Z'));
    for (final status in ['denied', 'unavailable', 'unknown']) {
      check(
        !UsageAdmissionResult.fromJson({
          ...d,
          'status': status,
          'reason': 'OWNER_CODE',
        }).permits('2026-11-02T10:00:00Z'),
      );
    }
    return {
      'admitted': true,
      'denied': false,
      'unavailable': false,
      'unknown': false,
    };
  }
  throw ArgumentError('Unknown portable case');
}

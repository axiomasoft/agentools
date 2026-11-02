import 'dart:convert';

import 'package:crypto/crypto.dart';

import '../schema/canonicalizer.dart';

const _maxInteger = 9007199254740991;
const _unitKeys = [
  'request_count',
  'input_uncached_tokens',
  'input_cache_read_tokens',
  'input_cache_write_tokens',
  'output_tokens',
  'reasoning_tokens',
  'audio_input_units',
  'audio_output_units',
  'audio_unit',
];

Map<String, Object?> _object(Object? value) {
  if (value is! Map<String, Object?>) throw ArgumentError('Object required');
  return value;
}

String _text(Object? value) {
  if (value is! String || value.trim().isEmpty) {
    throw ArgumentError('Nonempty string required');
  }
  return value;
}

int _integer(Object? value) {
  if (value is! int || value < 0 || value > _maxInteger) {
    throw ArgumentError('Nonnegative safe integer required');
  }
  return value;
}

String _decimal(Object? value) {
  if (value is! String ||
      value.trim() != value ||
      value.length > 128 ||
      !RegExp(r'^(0|[1-9][0-9]*)(\.[0-9]+)?$').hasMatch(value)) {
    throw ArgumentError('Exact decimal string required');
  }
  return value;
}

void _keys(Map<String, Object?> value, List<String> keys) {
  if (value.length != keys.length ||
      keys.any((key) => !value.containsKey(key))) {
    throw ArgumentError('Unknown or missing field');
  }
}

String _instant(Object? value) {
  final raw = _text(value);
  final match = RegExp(
    r'^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.(\d{1,6}))?Z$',
  ).firstMatch(raw);
  if (match == null) throw ArgumentError('UTC instant required');
  final parsed = DateTime.parse(raw);
  if (parsed.toIso8601String().substring(0, 19) != raw.substring(0, 19)) {
    throw ArgumentError('Invalid UTC instant');
  }
  final fraction = (match.group(1) ?? '').replaceFirst(RegExp(r'0+$'), '');
  return '${raw.substring(0, 19)}${fraction.isEmpty ? '' : '.$fraction'}Z';
}

Map<String, Object?> _copy(Map<String, Object?> value) =>
    _object(jsonDecode(jsonEncode(value)));
String _hash(String tenant, String domain, Object? payload) =>
    'sha-256:${sha256.convert(utf8.encode(Canonicalizer().canonicalize({'revision': 'platform/v1', 'tenant_id': tenant, 'domain': domain, 'payload': payload})))}';
Map<String, Object?>? _amount(Object? raw, {bool estimate = false}) {
  if (raw == null) return null;
  final data = _object(raw);
  _keys(
    data,
    estimate ? ['kind', 'amount', 'currency'] : ['amount', 'currency'],
  );
  if (estimate && data['kind'] != 'estimate') {
    throw ArgumentError('Estimate must be labeled');
  }
  _decimal(data['amount']);
  if (!RegExp(r'^[A-Z]{3}$').hasMatch(_text(data['currency']))) {
    throw ArgumentError('Explicit currency required');
  }
  return _copy(data);
}

Map<String, Object?> _units(Map<String, Object?> raw) {
  _keys(raw, _unitKeys);
  for (final entry in raw.entries) {
    if (entry.value == null) continue;
    if (entry.key == 'audio_input_units' || entry.key == 'audio_output_units') {
      _decimal(entry.value);
    } else if (entry.key == 'audio_unit') {
      _text(entry.value);
    } else {
      _integer(entry.value);
    }
  }
  final audio =
      raw['audio_input_units'] != null || raw['audio_output_units'] != null;
  if (audio != (raw['audio_unit'] != null)) {
    throw ArgumentError('Explicit audio unit required only for observed audio');
  }
  return {for (final key in _unitKeys) key: raw[key]};
}

Map<String, Object?> _unknown(int? count) => {
  for (final key in _unitKeys) key: key == 'request_count' ? count : null,
};

enum UsageProducer { transport, sdkHook }

String _producer(UsageProducer producer) =>
    producer == UsageProducer.transport ? 'transport' : 'sdk_hook';

final class UsageMapping {
  final String ref;
  final Map<String, String> fields;
  final bool inputIncludesCacheRead;
  final String cacheWriteRelation;
  final String reasoningRelation;
  UsageMapping(
    this.ref,
    Map<String, String> fields,
    this.inputIncludesCacheRead,
    this.cacheWriteRelation,
    this.reasoningRelation,
  ) : fields = Map.unmodifiable(fields) {
    _text(ref);
    if (!['subset_of_uncached', 'separate'].contains(cacheWriteRelation) ||
        !['subset_of_output', 'separate'].contains(reasoningRelation)) {
      throw ArgumentError('Explicit overlap required');
    }
    for (final field in fields.entries) {
      if (![
        'input',
        'cache_read',
        'cache_write',
        'output',
        'reasoning',
        'audio_input',
        'audio_output',
        'audio_unit',
        'provider_amount',
      ].contains(field.key)) {
        throw ArgumentError('Unknown category');
      }
      _text(field.value);
    }
  }
  factory UsageMapping.fromJson(Map<String, Object?> data) {
    final fields = _object(
      data['fields'],
    ).map((key, value) => MapEntry(key, _text(value)));
    final includes = data['input_includes_cache_read'];
    if (includes is! bool) throw ArgumentError('Boolean overlap required');
    return UsageMapping(
      _text(data['ref']),
      fields,
      includes,
      _text(data['cache_write_relation']),
      _text(data['reasoning_relation']),
    );
  }
}

final class UsageEvent {
  final Map<String, Object?> _data;
  final String hash;
  UsageEvent._(Map<String, Object?> data)
    : _data = _copy(data),
      hash = _hash(_text(data['tenant_ref']), 'canonical-usage-event', data);
  factory UsageEvent.fromJson(Map<String, Object?> input) {
    final data = _copy(input);
    _keys(data, [
      'schema_version',
      'event_id',
      'source',
      'tenant_ref',
      'actor_ref',
      'subject_ref',
      'assistant_session_ref',
      'turn_ref',
      'run_ref',
      'business_operation_ref',
      'deployment_ref',
      'model_revision',
      'correlation_id',
      'certainty',
      'occurred_at',
      'units',
      'provider_amount',
      'estimate',
      'pricing_version',
      'supersedes_event_id',
    ]);
    if (data['schema_version'] != '1') {
      throw ArgumentError('Unknown usage schema');
    }
    for (final key in [
      'event_id',
      'tenant_ref',
      'assistant_session_ref',
      'turn_ref',
      'run_ref',
      'business_operation_ref',
      'deployment_ref',
      'correlation_id',
    ]) {
      _text(data[key]);
    }
    for (final key in [
      'actor_ref',
      'subject_ref',
      'model_revision',
      'pricing_version',
      'supersedes_event_id',
    ]) {
      if (data[key] != null) _text(data[key]);
    }
    final source = _object(data['source']);
    _keys(source, [
      'kind',
      'invocation_id',
      'usage_version',
      'producer',
      'mapping_ref',
    ]);
    if (source['kind'] != 'agentools.invocation' ||
        !['transport', 'sdk_hook'].contains(source['producer'])) {
      throw ArgumentError('Unknown usage source');
    }
    _text(source['invocation_id']);
    _text(source['mapping_ref']);
    final version = _integer(source['usage_version']);
    if (version == 0 ||
        ((version == 1) != (data['supersedes_event_id'] == null)) ||
        data['supersedes_event_id'] == data['event_id']) {
      throw ArgumentError('Immutable correction chain required');
    }
    if (![
      'preliminary',
      'final',
      'partial',
      'unknown',
    ].contains(data['certainty'])) {
      throw ArgumentError('Unknown certainty');
    }
    data['occurred_at'] = _instant(data['occurred_at']);
    final units = _units(_object(data['units']));
    _amount(data['provider_amount']);
    _amount(data['estimate'], estimate: true);
    if (data['certainty'] == 'unknown' &&
        (data['provider_amount'] != null ||
            jsonEncode(units) !=
                jsonEncode(_unknown(units['request_count'] as int?)))) {
      throw ArgumentError('Unknown actuals cannot be fabricated');
    }
    return UsageEvent._(data);
  }
  Map<String, Object?> toJson() => _copy(_data);
  Map<String, Object?> get units => _object(toJson()['units']);
  String get tenant => _text(_data['tenant_ref']);
  String get eventId => _text(_data['event_id']);
  String get invocation => _text(_object(_data['source'])['invocation_id']);
  int get version => _integer(_object(_data['source'])['usage_version']);
  String get operation => jsonEncode([tenant, invocation]);
  String get acknowledgement => 'usage-ack:${hash.substring(8)}';
  Map<String, Object?> binding() {
    final data = toJson();
    for (final key in [
      'schema_version',
      'event_id',
      'source',
      'occurred_at',
      'certainty',
      'units',
      'provider_amount',
      'estimate',
      'pricing_version',
      'supersedes_event_id',
    ]) {
      data.remove(key);
    }
    data['producer'] = _object(_data['source'])['producer'];
    return data;
  }
}

final class UsageNormalizer {
  const UsageNormalizer();
  ({UsageEvent? event, bool telemetryOnly}) observe(
    Map<String, Object?> metadata,
    String invocation,
    int version,
    UsageProducer canonical,
    UsageProducer observer,
    UsageMapping mapping,
    Map<String, Object?> raw,
    bool? outboundProven,
  ) {
    if (canonical != observer) return (event: null, telemetryOnly: true);
    final count = outboundProven == null ? null : (outboundProven ? 1 : 0);
    var facts = _unknown(count);
    Map<String, Object?>? amount;
    Object? field(String key) =>
        mapping.fields.containsKey(key) ? raw[mapping.fields[key]] : null;
    int? quantity(String key) =>
        field(key) == null ? null : _integer(field(key));
    if (metadata['certainty'] != 'unknown') {
      var input = quantity('input');
      final read = quantity('cache_read');
      final write = quantity('cache_write');
      final output = quantity('output');
      final reasoning = quantity('reasoning');
      if (mapping.inputIncludesCacheRead) {
        if (input != null && read != null && read > input) {
          throw ArgumentError('Cached subset exceeds input');
        }
        input = input == null || read == null ? null : input - read;
      }
      if (mapping.cacheWriteRelation == 'subset_of_uncached' &&
          input != null &&
          write != null &&
          write > input) {
        throw ArgumentError('Cache write subset exceeds input');
      }
      if (mapping.reasoningRelation == 'subset_of_output' &&
          output != null &&
          reasoning != null &&
          reasoning > output) {
        throw ArgumentError('Reasoning subset exceeds output');
      }
      final audioIn = field('audio_input') == null
          ? null
          : _decimal(field('audio_input'));
      final audioOut = field('audio_output') == null
          ? null
          : _decimal(field('audio_output'));
      facts = {
        'request_count': count,
        'input_uncached_tokens': input,
        'input_cache_read_tokens': read,
        'input_cache_write_tokens': write,
        'output_tokens': output,
        'reasoning_tokens': reasoning,
        'audio_input_units': audioIn,
        'audio_output_units': audioOut,
        'audio_unit': audioIn == null && audioOut == null
            ? null
            : field('audio_unit'),
      };
      amount = _amount(field('provider_amount'));
    }
    return (
      event: UsageEvent.fromJson({
        ...metadata,
        'source': {
          'kind': 'agentools.invocation',
          'invocation_id': invocation,
          'usage_version': version,
          'producer': _producer(canonical),
          'mapping_ref': mapping.ref,
        },
        'units': facts,
        'provider_amount': amount,
      }),
      telemetryOnly: false,
    );
  }
}

final class UsageConflict implements Exception {
  const UsageConflict();
  @override
  String toString() => 'USAGE_PAYLOAD_CONFLICT';
}

final class UsageProjection {
  final List<UsageEvent> history;
  final Map<String, UsageEvent> active;
  UsageProjection(List<UsageEvent> history, Map<String, UsageEvent> active)
    : history = List.unmodifiable(history),
      active = Map.unmodifiable(active);
}

final class UsageFold {
  static UsageProjection fold(List<UsageEvent> input) {
    final events = List<UsageEvent>.of(input)
      ..sort((a, b) {
        final order = a.operation.compareTo(b.operation);
        return order == 0 ? a.version.compareTo(b.version) : order;
      });
    final identities = <String, String>{};
    final ids = <String, String>{};
    final active = <String, UsageEvent>{};
    final history = <UsageEvent>[];
    for (final event in events) {
      final identity = jsonEncode([event.operation, event.version]);
      final id = jsonEncode([event.tenant, event.eventId]);
      if ((identities.containsKey(identity) &&
              identities[identity] != event.hash) ||
          (ids.containsKey(id) && ids[id] != event.hash)) {
        throw const UsageConflict();
      }
      if (identities.containsKey(identity)) continue;
      final before = active[event.operation];
      final supersedes = event.toJson()['supersedes_event_id'];
      if (before == null
          ? supersedes != null
          : supersedes != before.eventId || event.version <= before.version) {
        throw ArgumentError('Missing predecessor or correction branch');
      }
      if (before != null &&
          Canonicalizer().canonicalize(before.binding()) !=
              Canonicalizer().canonicalize(event.binding())) {
        throw const UsageConflict();
      }
      identities[identity] = event.hash;
      ids[id] = event.hash;
      active[event.operation] = event;
      history.add(event);
    }
    return UsageProjection(history, active);
  }
}

final class UsageAdmissionResult {
  final Map<String, Object?> _data;
  UsageAdmissionResult._(Map<String, Object?> data) : _data = _copy(data);
  factory UsageAdmissionResult.fromJson(Map<String, Object?> data) {
    _keys(data, [
      'status',
      'admission_ref',
      'reservation_ref',
      'expires_at',
      'owner_revision',
      'limits',
      'reason',
      'retry_after',
    ]);
    if (![
      'admitted',
      'denied',
      'unavailable',
      'unknown',
      'not_found',
    ].contains(data['status'])) {
      throw ArgumentError('Unknown admission status');
    }
    if (data['owner_revision'] is! String) {
      throw ArgumentError('Owner revision required');
    }
    if (data['status'] == 'admitted') {
      _text(data['admission_ref']);
      _instant(data['expires_at']);
      _text(data['owner_revision']);
    }
    if (['denied', 'unavailable', 'unknown'].contains(data['status'])) {
      _text(data['reason']);
    }
    for (final key in ['admission_ref', 'reservation_ref', 'reason']) {
      if (data[key] != null) _text(data[key]);
    }
    for (final key in ['expires_at', 'retry_after']) {
      if (data[key] != null) _instant(data[key]);
    }
    for (final entry in _object(data['limits']).entries) {
      _text(entry.key);
      _integer(entry.value);
    }
    return UsageAdmissionResult._(data);
  }
  bool permits(String now) =>
      _data['status'] == 'admitted' &&
      DateTime.parse(
        _instant(now),
      ).isBefore(DateTime.parse(_text(_data['expires_at'])));
}

import 'dart:convert';
import 'dart:io';

import 'package:agentools/agentools.dart';
import 'package:agentools/src/schema/canonicalizer.dart';
import 'package:json_schema/json_schema.dart';

void main(List<String> args) {
  final vectors = jsonDecode(
    File('fixtures/platform/v1/compatibility/vectors.json').readAsStringSync(),
  ) as Map<String, dynamic>;
  final published = jsonDecode(
    File('spec/platform/v1/schema/contracts.json').readAsStringSync(),
  ) as Map<String, dynamic>;
  final rows = <Map<String, dynamic>>[];
  for (final rawCase in vectors['cases'] as List<dynamic>) {
    final item = rawCase as Map<String, dynamic>;
    final id = item['id'] as String;
    if (args.isNotEmpty && !args.contains(id)) continue;
    late Map<String, dynamic> observed;
    late bool passed;
    late String tier;
    if (item['kind'] == 'schema') {
      final fixture = jsonDecode(
        File(item['source'] as String).readAsStringSync(),
      ) as Map<String, dynamic>;
      final schema = JsonSchema.create({
        ...published,
        '\$ref': '#/\$defs/${fixture['definition']}',
      }, schemaVersion: SchemaVersion.draft2020_12);
      final valid = schema.validate(fixture['data']).isValid;
      observed = {'valid': valid, 'data': fixture['data']};
      passed = valid == (fixture['expect'] as Map<String, dynamic>)['valid'];
      tier = 'design-schema';
    } else {
      final reducer = ActionReducer(
        hashChain: JournalHashChain(Canonicalizer()),
      );
      var state = FoldedAction();
      String? rejection;
      final events = item['events'] as List<dynamic>;
      for (var index = 0; index < events.length; index++) {
        final raw = events[index] as Map<String, dynamic>;
        final seq = index + 1;
        final event = ActionEvent(
          eventId: 'event-$seq',
          actionId: 'dated-action-1',
          seq: seq,
          expectedPrevSeq: raw['expected_prev_seq'] as int? ?? seq - 1,
          timestamp: '2026-10-31T12:00:00Z',
          actor: Actor(type: 'host', id: 'owner-1'),
          kind: raw['kind'] as String,
          eventVersion: 1,
          specRevision: '2026-09',
          payload:
              raw['payload'] as Map<String, dynamic>? ?? <String, dynamic>{},
          epoch: raw['epoch'] as int?,
          previousHash: state.previousHash,
        );
        try {
          state = reducer.apply(state, event);
        } on LifecycleException catch (error) {
          rejection = error is StaleExecutorEpoch
              ? 'stale_epoch'
              : error is VersionConflict
              ? 'version_conflict'
              : 'lifecycle';
          break;
        }
      }
      observed = {
        'status': state.status?.value,
        'seq': state.seq,
        'epoch': state.epoch,
        'cancellation_requested': state.cancellationRequested,
        'unresolved_effects': state.hasUnresolvedEffect(),
        'rejection': rejection,
      };
      passed =
          Canonicalizer().canonicalize(observed) ==
          Canonicalizer().canonicalize(item['expected']);
      observed['previous_hash'] = state.previousHash;
      tier = 'contract-scripted';
    }
    rows.add({
      'id': id,
      'status': passed ? 'passed' : 'failed',
      'tier': tier,
      'observed': observed,
    });
  }
  if (rows.isEmpty || (args.isNotEmpty && rows.length != args.length))
    throw StateError('Missing selected cases');
  stdout.writeln(jsonEncode({'consumer': 'dart', 'cases': rows}));
  exitCode = rows.any((row) => row['status'] != 'passed') ? 1 : 0;
}

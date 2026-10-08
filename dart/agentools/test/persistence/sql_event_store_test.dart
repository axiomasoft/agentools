import 'dart:convert';
import 'dart:io';

import 'package:agentools/agentools.dart';
import 'package:test/test.dart';

class _ScriptedExecutor implements SqlExecutor {
  _ScriptedExecutor(this.responses);

  final List<SqlQueryResult> responses;
  final calls = <(String, Map<String, Object?>)>[];
  var _index = 0;

  @override
  SqlQueryResult execute(String sql, Map<String, Object?> params) {
    calls.add((sql, params));
    if (_index >= responses.length) {
      throw StateError('Unexpected SQL: $sql');
    }
    return responses[_index++];
  }
}

UncommittedEvent _event(
  String eventId, {
  Map<String, dynamic> payload = const {},
  String timestamp = '2026-09-20T10:00:00+00:00',
}) {
  return UncommittedEvent(
    eventId: eventId,
    kind: 'action_created',
    eventVersion: 1,
    specRevision: '2026-08',
    actor: Actor(type: 'user', id: 'u-1'),
    timestamp: timestamp,
    payload: payload,
  );
}

void main() {
  test('SQL hydration and inserts preserve exact shared timestamp strings', () {
    final fixture =
        jsonDecode(
              File(
                '../../fixtures/hardening/audit-regressions-v1.json',
              ).readAsStringSync(),
            )
            as Map<String, dynamic>;
    for (final ts in fixture['timestamps'] as List) {
      final executor = _ScriptedExecutor([
        SqlQueryResult(
          rows: [
            {
              'event_id': 'timestamp-fixture',
              'action_id': 'action',
              'seq': 1,
              'expected_prev_seq': 0,
              'ts_text': ts,
              'ts': DateTime.utc(2000),
              'actor_type': 'user',
              'actor_id': 'u',
              'kind': 'action_created',
              'event_version': 1,
              'spec_rev': '2026-09',
              'epoch': null,
              'prev_hash': null,
              'payload_json': '{}',
            },
          ],
          affectedRows: 1,
        ),
      ]);
      expect(
        SqlEventStore(
          executor,
        ).load('tenant', 'action').events.single.timestamp,
        ts,
      );
      expect(executor.calls.single.$1, contains('ts_text'));
    }
  });

  test(
    'SQL constants lock the header, omit caller position, and keyset without OFFSET',
    () {
      expect(SqlEventStore.selectHeaderSql, contains('FOR UPDATE'));
      expect(
        SqlEventStore.lockHeaderSql,
        contains('ON CONFLICT (tenant_id, action_id) DO NOTHING'),
      );
      expect(
        SqlEventStore.insertEventSql,
        contains('RETURNING seq, global_position'),
      );
      expect(SqlEventStore.insertEventSql, isNot(contains('global_position)')));
      expect(
        SqlEventStore.insertEventSql.toLowerCase(),
        isNot(contains('delete')),
      );
      expect(SqlEventStore.loadSql, contains('seq > @after_seq'));
      expect(SqlEventStore.loadSql, contains('ORDER BY seq ASC'));
      expect(SqlEventStore.loadSql, contains('LIMIT @limit'));
      expect(SqlEventStore.loadSql, isNot(contains('OFFSET')));
      expect(SqlEventStore.lookupSql, contains('content_hash'));
    },
  );

  test(
    'shared schema assigns IDENTITY global_position and forbids application DELETE',
    () {
      final schema = File(
        '../../fixtures/hardening/sql/event-store.v1.sql',
      ).readAsStringSync();
      expect(schema, contains('GENERATED ALWAYS AS IDENTITY'));
      expect(schema, contains('PRIMARY KEY (tenant_id, action_id, seq)'));
      expect(schema, contains('UNIQUE (tenant_id, event_id)'));
      expect(schema.toUpperCase(), isNot(contains('DELETE FROM')));
    },
  );

  test(
    'scripted append classifies under lock then INSERTs RETURNING position',
    () async {
      final executor = _ScriptedExecutor([
        const SqlQueryResult(rows: [], affectedRows: 0),
        const SqlQueryResult(rows: [], affectedRows: 1),
        const SqlQueryResult(
          rows: [
            {'current_seq': 0},
          ],
          affectedRows: 1,
        ),
        const SqlQueryResult(rows: [], affectedRows: 0),
        const SqlQueryResult(rows: [], affectedRows: 0),
        const SqlQueryResult(
          rows: [
            {'seq': 1, 'global_position': 7},
          ],
          affectedRows: 1,
        ),
        const SqlQueryResult(rows: [], affectedRows: 1),
        const SqlQueryResult(rows: [], affectedRows: 0),
      ]);
      final store = SqlEventStore(executor);
      final result = store.append('tenant-a', 'action-1', 0, [
        _event('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'),
      ]);
      expect(result.outcome, AppendResult.appended);
      expect(result.firstSeq, 1);
      expect(result.lastSeq, 1);
      expect(result.lastGlobalPosition, 7);
      expect(executor.calls[0].$1, 'BEGIN');
      expect(executor.calls[1].$1, SqlEventStore.lockHeaderSql);
      expect(executor.calls[2].$1, SqlEventStore.selectHeaderSql);
      expect(executor.calls[4].$1, SqlEventStore.tailHashSql);
      expect(executor.calls[5].$1, SqlEventStore.insertEventSql);
      expect(executor.calls[5].$2.containsKey('global_position'), isFalse);
      expect(executor.calls[5].$2['ts_text'], executor.calls[5].$2['ts']);
      expect(executor.calls.last.$1, 'COMMIT');
    },
  );

  test('stale expectedPrevSeq is a version conflict and rolls back', () async {
    final executor = _ScriptedExecutor([
      const SqlQueryResult(rows: [], affectedRows: 0),
      const SqlQueryResult(rows: [], affectedRows: 1),
      const SqlQueryResult(
        rows: [
          {'current_seq': 1},
        ],
        affectedRows: 1,
      ),
      const SqlQueryResult(rows: [], affectedRows: 0),
      const SqlQueryResult(rows: [], affectedRows: 0),
    ]);
    final store = SqlEventStore(executor);
    expect(
      () => store.append('tenant-a', 'action-1', 0, [
        _event('bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'),
      ]),
      throwsA(isA<VersionConflict>()),
    );
    expect(executor.calls.last.$1, 'ROLLBACK');
  });

  test('payload collision refuses overwrite without INSERT', () async {
    final executor = _ScriptedExecutor([
      const SqlQueryResult(rows: [], affectedRows: 0),
      const SqlQueryResult(rows: [], affectedRows: 1),
      const SqlQueryResult(
        rows: [
          {'current_seq': 1},
        ],
        affectedRows: 1,
      ),
      const SqlQueryResult(
        rows: [
          {
            'event_id': 'cccccccccccccccccccccccccccccccc',
            'action_id': 'action-1',
            'seq': 1,
            'content_hash': 'sha-256:other',
          },
        ],
        affectedRows: 1,
      ),
      const SqlQueryResult(rows: [], affectedRows: 0),
    ]);
    final store = SqlEventStore(executor);
    expect(
      () => store.append('tenant-a', 'action-1', 0, [
        _event('cccccccccccccccccccccccccccccccc', payload: {'v': 2}),
      ]),
      throwsA(isA<EventIdCollision>()),
    );
    expect(
      executor.calls.any((call) => call.$1 == SqlEventStore.insertEventSql),
      isFalse,
    );
    expect(executor.calls.last.$1, 'ROLLBACK');
  });

  test('keyset load uses seq greater than after and limit plus one', () async {
    final executor = _ScriptedExecutor([
      const SqlQueryResult(
        rows: [
          {
            'event_id': 'id-1',
            'action_id': 'action-1',
            'seq': 3,
            'expected_prev_seq': 2,
            'ts_text': '2026-09-20T10:00:00+00:00',
            'actor_type': 'user',
            'actor_id': 'u-1',
            'kind': 'action_created',
            'event_version': 1,
            'spec_rev': '2026-08',
            'epoch': null,
            'prev_hash': null,
            'payload_json': '{}',
          },
        ],
        affectedRows: 1,
      ),
    ]);
    final store = SqlEventStore(executor);
    final page = store.load('tenant-a', 'action-1', afterSeq: 2, limit: 2);
    expect(page.lastSeq, 3);
    expect(page.hasMore, isFalse);
    expect(executor.calls.single.$1, SqlEventStore.loadSql);
    expect(executor.calls.single.$2['after_seq'], 2);
    expect(executor.calls.single.$2['limit'], 3);
  });

  test('production dependencies do not add a postgres driver', () {
    final productionDependencies = <String>[];
    var inDependencies = false;
    for (final line in File('pubspec.yaml').readAsLinesSync()) {
      if (line == 'dependencies:') {
        inDependencies = true;
        continue;
      }
      if (inDependencies && line.isNotEmpty && !line.startsWith(' ')) {
        break;
      }
      if (inDependencies && line.trim().isNotEmpty) {
        productionDependencies.add(line.trim().toLowerCase());
      }
    }

    expect(productionDependencies, isNot(contains(startsWith('postgres:'))));
  });

  // The hash chain stays verifiable after reload whatever the input offset,
  // precision or the session time zone of the executor.
  test('stores, chains and hydrates exact envelope timestamps', () {
    final executor = _ScriptedExecutor([
      const SqlQueryResult(rows: [], affectedRows: 0),
      const SqlQueryResult(rows: [], affectedRows: 1),
      const SqlQueryResult(
        rows: [
          {'current_seq': 1},
        ],
        affectedRows: 1,
      ),
      const SqlQueryResult(rows: [], affectedRows: 0),
      const SqlQueryResult(rows: [], affectedRows: 0),
      const SqlQueryResult(
        rows: [
          {
            'event_id': 'ev-tail',
            'action_id': 'action-1',
            'seq': 1,
            'expected_prev_seq': 0,
            'ts': '2026-09-20 15:44:59.5+05:45',
            'ts_text': '2026-09-20T09:59:59.5Z',
            'actor_type': 'user',
            'actor_id': 'u-1',
            'kind': 'action_created',
            'event_version': 1,
            'spec_rev': '2026-08',
            'epoch': null,
            'prev_hash': null,
            'payload_json': '{}',
          },
        ],
        affectedRows: 1,
      ),
      const SqlQueryResult(
        rows: [
          {'seq': 2, 'global_position': 8},
        ],
        affectedRows: 1,
      ),
      const SqlQueryResult(
        rows: [
          {'seq': 3, 'global_position': 9},
        ],
        affectedRows: 1,
      ),
      const SqlQueryResult(rows: [], affectedRows: 1),
      const SqlQueryResult(rows: [], affectedRows: 0),
    ]);
    SqlEventStore(executor).append('tenant-a', 'action-1', 1, [
      _event(
        'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaab',
        timestamp: '2026-09-20T12:00:01+02:00',
      ),
      _event(
        'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaac',
        timestamp: '2026-09-20T10:00:02.1234567Z',
      ),
    ]);

    final inserts = executor.calls
        .where((call) => call.$1 == SqlEventStore.insertEventSql)
        .map((call) => call.$2)
        .toList();
    expect(inserts.map((params) => params['ts']), [
      '2026-09-20T12:00:01+02:00',
      '2026-09-20T10:00:02.1234567Z',
    ]);

    ActionEvent hydrated(String id, int seq, String ts, String? prev) {
      return ActionEvent(
        eventId: id,
        actionId: 'action-1',
        seq: seq,
        expectedPrevSeq: seq - 1,
        timestamp: ts,
        actor: Actor(type: 'user', id: 'u-1'),
        kind: 'action_created',
        eventVersion: 1,
        specRevision: '2026-08',
        payload: const {},
        previousHash: prev,
      );
    }

    final chain = JournalHashChain(Canonicalizer());
    final tail = hydrated('ev-tail', 1, '2026-09-20T09:59:59.5Z', null);
    expect(inserts[0]['prev_hash'], chain.hash(tail));
    final first = hydrated(
      'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaab',
      2,
      inserts[0]['ts'] as String,
      inserts[0]['prev_hash'] as String?,
    );
    expect(inserts[1]['prev_hash'], chain.hash(first));
  });
}

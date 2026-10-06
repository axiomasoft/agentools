import 'dart:convert';

import '../confirmation/sql_confirmation_token_store.dart';
import '../contracts/event_store.dart';
import '../journal/action_event.dart';
import '../journal/actor.dart';
import '../journal/event_id_collision.dart';
import '../journal/journal_hash_chain.dart';
import '../journal/version_conflict.dart';
import '../schema/canonicalizer.dart';
import 'append_result.dart';
import 'event_store_failure.dart';
import 'event_store_support.dart';
import 'event_stream_page.dart';
import 'uncommitted_event.dart';

/// Injected-SQL EventStore. Production PDO assigns IDENTITY global_position.
final class SqlEventStore implements EventStore {
  SqlEventStore(this._executor, {Canonicalizer? canonicalizer})
    : _canonicalizer = canonicalizer ?? Canonicalizer();

  final SqlExecutor _executor;
  final Canonicalizer _canonicalizer;

  static const lockHeaderSql = '''
INSERT INTO agentools_event_streams (tenant_id, action_id, current_seq)
VALUES (@tenant_id, @action_id, 0)
ON CONFLICT (tenant_id, action_id) DO NOTHING
''';

  static const selectHeaderSql = '''
SELECT current_seq
FROM agentools_event_streams
WHERE tenant_id = @tenant_id AND action_id = @action_id
FOR UPDATE
''';

  static const lookupSql = '''
SELECT event_id, action_id, seq, content_hash
FROM agentools_events
WHERE tenant_id = @tenant_id AND event_id = @event_id
''';

  static const insertEventSql = '''
INSERT INTO agentools_events (
  tenant_id, action_id, seq, event_id, kind, event_version, spec_rev,
  actor_type, actor_id, ts, payload_json, epoch, prev_hash, content_hash, expected_prev_seq
) VALUES (
  @tenant_id, @action_id, @seq, @event_id, @kind, @event_version, @spec_rev,
  @actor_type, @actor_id, @ts, @payload_json, @epoch, @prev_hash, @content_hash, @expected_prev_seq
)
RETURNING seq, global_position
''';

  static const updateHeaderSql = '''
UPDATE agentools_event_streams
SET current_seq = @current_seq
WHERE tenant_id = @tenant_id AND action_id = @action_id
''';

  static const loadSql = '''
SELECT event_id, action_id, seq, expected_prev_seq, ts, actor_type, actor_id,
       kind, event_version, spec_rev, epoch, prev_hash, payload_json
FROM agentools_events
WHERE tenant_id = @tenant_id AND action_id = @action_id AND seq > @after_seq
ORDER BY seq ASC
LIMIT @limit
''';

  static const tailHashSql = '''
SELECT event_id, action_id, seq, expected_prev_seq, ts, actor_type, actor_id,
       kind, event_version, spec_rev, epoch, prev_hash, payload_json
FROM agentools_events
WHERE tenant_id = @tenant_id AND action_id = @action_id
ORDER BY seq DESC
LIMIT 1
''';

  static const positionSql = '''
SELECT global_position
FROM agentools_events
WHERE tenant_id = @tenant_id AND event_id = @event_id
''';

  @override
  AppendResult append(
    String tenantId,
    String actionId,
    int expectedPrevSeq,
    List<UncommittedEvent> events,
  ) {
    final rules = EventStoreSupport(canonicalizer: _canonicalizer);
    rules.assertAppendable(tenantId, actionId, events);
    _executor.execute('BEGIN', const {});
    try {
      _executor.execute(lockHeaderSql, {
        'tenant_id': tenantId,
        'action_id': actionId,
      });
      final header = _executor.execute(selectHeaderSql, {
        'tenant_id': tenantId,
        'action_id': actionId,
      });
      final actual = header.rows.isEmpty
          ? 0
          : (header.rows.first['current_seq'] as num?)?.toInt() ?? 0;
      final hashes = [
        for (final event in events)
          rules.contentHash(tenantId, actionId, event),
      ];
      final existing = <int, Map<String, Object?>>{};
      for (var index = 0; index < events.length; index++) {
        final found = _executor.execute(lookupSql, {
          'tenant_id': tenantId,
          'event_id': events[index].eventId,
        });
        if (found.rows.isNotEmpty) {
          existing[index] = found.rows.first;
        }
      }
      final result = existing.isEmpty
          ? _insertAll(
              tenantId,
              actionId,
              actual,
              expectedPrevSeq,
              events,
              hashes,
            )
          : _classifyReplay(
              tenantId,
              actionId,
              expectedPrevSeq,
              actual,
              events,
              hashes,
              existing,
            );
      _executor.execute('COMMIT', const {});
      return result;
    } catch (error) {
      _executor.execute('ROLLBACK', const {});
      rethrow;
    }
  }

  @override
  EventStreamPage load(
    String tenantId,
    String actionId, {
    int afterSeq = 0,
    int limit = EventStoreSupport.defaultPage,
  }) {
    if (tenantId.isEmpty || actionId.isEmpty) {
      throw EventStoreFailure.streamIdentity();
    }
    if (limit < 1 || limit > EventStoreSupport.maxPage) {
      throw EventStoreFailure.pageLimit(limit);
    }
    final result = _executor.execute(loadSql, {
      'tenant_id': tenantId,
      'action_id': actionId,
      'after_seq': afterSeq,
      'limit': limit + 1,
    });
    final hasMore = result.rows.length > limit;
    final page = result.rows.take(limit).map(_hydrate).toList();
    return EventStreamPage(
      events: page,
      lastSeq: page.isEmpty ? afterSeq : page.last.seq,
      hasMore: hasMore,
    );
  }

  AppendResult _insertAll(
    String tenantId,
    String actionId,
    int actual,
    int expectedPrevSeq,
    List<UncommittedEvent> events,
    List<String> hashes,
  ) {
    if (expectedPrevSeq != actual) {
      throw VersionConflict(expected: expectedPrevSeq, actual: actual);
    }
    final chain = JournalHashChain(_canonicalizer);
    ActionEvent? previous = _tailEvent(tenantId, actionId);
    var lastPosition = 0;
    for (var index = 0; index < events.length; index++) {
      final event = events[index];
      final seq = actual + index + 1;
      final timestamp = _canonicalTimestamp(event.timestamp);
      final committed = ActionEvent(
        eventId: event.eventId,
        actionId: actionId,
        seq: seq,
        expectedPrevSeq: actual + index,
        timestamp: timestamp,
        actor: event.actor,
        kind: event.kind,
        eventVersion: event.eventVersion,
        specRevision: event.specRevision,
        payload: event.payload,
        epoch: event.epoch,
        previousHash: previous == null ? null : chain.hash(previous),
      );
      final inserted = _executor.execute(insertEventSql, {
        'tenant_id': tenantId,
        'action_id': actionId,
        'seq': seq,
        'event_id': event.eventId,
        'kind': event.kind,
        'event_version': event.eventVersion,
        'spec_rev': event.specRevision,
        'actor_type': event.actor.type,
        'actor_id': event.actor.id,
        'ts': timestamp,
        'payload_json': jsonEncode(event.payload),
        'epoch': event.epoch,
        'prev_hash': committed.previousHash,
        'content_hash': hashes[index],
        'expected_prev_seq': actual + index,
      });
      lastPosition = (inserted.rows.first['global_position'] as num).toInt();
      previous = committed;
    }
    _executor.execute(updateHeaderSql, {
      'tenant_id': tenantId,
      'action_id': actionId,
      'current_seq': actual + events.length,
    });
    return AppendResult(
      firstSeq: actual + 1,
      lastSeq: actual + events.length,
      lastGlobalPosition: lastPosition,
      outcome: AppendResult.appended,
    );
  }

  AppendResult _classifyReplay(
    String tenantId,
    String actionId,
    int expectedPrevSeq,
    int actual,
    List<UncommittedEvent> events,
    List<String> hashes,
    Map<int, Map<String, Object?>> existing,
  ) {
    if (existing.length != events.length) {
      for (final entry in existing.entries) {
        if (entry.value['content_hash'] != hashes[entry.key]) {
          throw EventIdCollision(events[entry.key].eventId);
        }
      }
      throw EventStoreFailure.partialOverlap();
    }
    for (var index = 0; index < events.length; index++) {
      final row = existing[index]!;
      if (row['content_hash'] != hashes[index] ||
          row['action_id'] != actionId) {
        throw EventIdCollision(events[index].eventId);
      }
    }
    final firstSeq = (existing[0]!['seq'] as num).toInt();
    for (var index = 0; index < events.length; index++) {
      if ((existing[index]!['seq'] as num).toInt() != firstSeq + index) {
        throw EventStoreFailure.partialOverlap();
      }
    }
    if (firstSeq != expectedPrevSeq + 1) {
      throw VersionConflict(expected: expectedPrevSeq, actual: actual);
    }
    final position = _executor.execute(positionSql, {
      'tenant_id': tenantId,
      'event_id': events.last.eventId,
    });
    return AppendResult(
      firstSeq: firstSeq,
      lastSeq: firstSeq + events.length - 1,
      lastGlobalPosition: (position.rows.first['global_position'] as num)
          .toInt(),
      outcome: AppendResult.idempotentReplay,
    );
  }

  ActionEvent? _tailEvent(String tenantId, String actionId) {
    final result = _executor.execute(tailHashSql, {
      'tenant_id': tenantId,
      'action_id': actionId,
    });
    if (result.rows.isEmpty) {
      return null;
    }
    return _hydrate(result.rows.first);
  }

  ActionEvent _hydrate(Map<String, Object?> row) {
    final payload = jsonDecode(row['payload_json'] as String);
    return ActionEvent(
      eventId: row['event_id'] as String,
      actionId: row['action_id'] as String,
      seq: (row['seq'] as num).toInt(),
      expectedPrevSeq: (row['expected_prev_seq'] as num).toInt(),
      timestamp: _canonicalTimestamp(row['ts']),
      actor: Actor(
        type: row['actor_type'] as String,
        id: row['actor_id'] as String,
      ),
      kind: row['kind'] as String,
      eventVersion: (row['event_version'] as num).toInt(),
      specRevision: row['spec_rev'] as String,
      payload: Map<String, dynamic>.from(payload as Map),
      epoch: (row['epoch'] as num?)?.toInt(),
      previousHash: row['prev_hash'] as String?,
    );
  }

  /// Canonical lexical form of an event instant: UTC, microsecond precision
  /// (the TIMESTAMPTZ resolution), fraction omitted when zero. The same form is
  /// stored, hashed into the chain and hydrated, so `prev_hash` stays
  /// verifiable after reload regardless of the input offset, input precision,
  /// the session time zone or whether the executor yields text or [DateTime].
  static String _canonicalTimestamp(Object? value) {
    final instant = switch (value) {
      DateTime() => value.toUtc(),
      String() => DateTime.parse(value).toUtc(),
      _ => throw FormatException('Event timestamp must be text or DateTime.'),
    };
    String two(int part) => part.toString().padLeft(2, '0');
    final date =
        '${instant.year.toString().padLeft(4, '0')}-${two(instant.month)}-${two(instant.day)}';
    final time =
        '${two(instant.hour)}:${two(instant.minute)}:${two(instant.second)}';
    final fraction = instant.millisecond * 1000 + instant.microsecond;
    final micros = fraction == 0
        ? ''
        : '.${fraction.toString().padLeft(6, '0')}';
    return '${date}T$time$micros+00:00';
  }
}

import '../contracts/event_store.dart';
import '../journal/action_event.dart';
import '../journal/event_id_collision.dart';
import '../journal/journal_hash_chain.dart';
import '../journal/version_conflict.dart';
import '../schema/canonicalizer.dart';
import 'append_result.dart';
import 'event_store_failure.dart';
import 'event_store_support.dart';
import 'event_stream_page.dart';
import 'uncommitted_event.dart';

/// Process-local EventStore. Production stores must classify under a stream lock.
/// This adapter is testkit/reference-only and MUST NOT be a production default.
final class InMemoryEventStore implements EventStore {
  static const defaultPage = EventStoreSupport.defaultPage;
  static const maxPage = EventStoreSupport.maxPage;
  static const maxBatch = EventStoreSupport.maxBatch;

  InMemoryEventStore({Canonicalizer? canonicalizer})
    : _canonicalizer = canonicalizer ?? Canonicalizer();

  final Canonicalizer _canonicalizer;
  final Map<String, Map<String, List<ActionEvent>>> _events = {};
  final Map<String, Map<String, _IndexedEvent>> _byEventId = {};
  int _globalPosition = 0;

  void assertAppendable(
    String tenantId,
    String actionId,
    List<UncommittedEvent> events,
  ) {
    EventStoreSupport(
      canonicalizer: _canonicalizer,
    ).assertAppendable(tenantId, actionId, events);
  }

  @override
  AppendResult append(
    String tenantId,
    String actionId,
    int expectedPrevSeq,
    List<UncommittedEvent> events,
  ) {
    assertAppendable(tenantId, actionId, events);

    final actual = _currentSeq(tenantId, actionId);
    final hashes = [
      for (final event in events) contentHash(tenantId, actionId, event),
    ];
    final existing = <int, _IndexedEvent>{};
    for (var index = 0; index < events.length; index++) {
      final row = _byEventId[tenantId]?[events[index].eventId];
      if (row != null) {
        existing[index] = row;
      }
    }

    if (existing.isEmpty) {
      if (expectedPrevSeq != actual) {
        throw VersionConflict(expected: expectedPrevSeq, actual: actual);
      }
      return _insertAll(tenantId, actionId, actual, events, hashes);
    }

    if (existing.length != events.length) {
      for (final entry in existing.entries) {
        if (entry.value.hash != hashes[entry.key]) {
          throw EventIdCollision(events[entry.key].eventId);
        }
      }
      throw EventStoreFailure.partialOverlap();
    }

    for (var index = 0; index < events.length; index++) {
      final row = existing[index]!;
      if (row.hash != hashes[index] || row.actionId != actionId) {
        throw EventIdCollision(events[index].eventId);
      }
    }

    final firstSeq = existing[0]!.seq;
    for (var index = 0; index < events.length; index++) {
      if (existing[index]!.seq != firstSeq + index) {
        throw EventStoreFailure.partialOverlap();
      }
    }
    if (firstSeq != expectedPrevSeq + 1) {
      throw VersionConflict(expected: expectedPrevSeq, actual: actual);
    }

    final last = events.last;
    return AppendResult(
      firstSeq: firstSeq,
      lastSeq: firstSeq + events.length - 1,
      lastGlobalPosition: _byEventId[tenantId]![last.eventId]!.position,
      outcome: AppendResult.idempotentReplay,
    );
  }

  @override
  EventStreamPage load(
    String tenantId,
    String actionId, {
    int afterSeq = 0,
    int limit = defaultPage,
  }) {
    if (tenantId.isEmpty || actionId.isEmpty) {
      throw EventStoreFailure.streamIdentity();
    }
    if (limit < 1 || limit > maxPage) {
      throw EventStoreFailure.pageLimit(limit);
    }
    final matching = [
      for (final event in _events[tenantId]?[actionId] ?? const <ActionEvent>[])
        if (event.seq > afterSeq) event,
    ];
    final page = matching.take(limit).toList();
    return EventStreamPage(
      events: page,
      lastSeq: page.isEmpty ? afterSeq : page.last.seq,
      hasMore: matching.length > limit,
    );
  }

  String contentHash(String tenantId, String actionId, UncommittedEvent event) {
    return EventStoreSupport(
      canonicalizer: _canonicalizer,
    ).contentHash(tenantId, actionId, event);
  }

  AppendResult _insertAll(
    String tenantId,
    String actionId,
    int actual,
    List<UncommittedEvent> events,
    List<String> hashes,
  ) {
    final committed = [...?_events[tenantId]?[actionId]];
    final chain = JournalHashChain(_canonicalizer);
    final firstSeq = actual + 1;
    var lastPosition = 0;
    for (var index = 0; index < events.length; index++) {
      final event = events[index];
      final seq = actual + index + 1;
      final previous = committed.isEmpty ? null : committed.last;
      committed.add(
        ActionEvent(
          eventId: event.eventId,
          actionId: actionId,
          seq: seq,
          expectedPrevSeq: actual + index,
          timestamp: event.timestamp,
          actor: event.actor,
          kind: event.kind,
          eventVersion: event.eventVersion,
          specRevision: event.specRevision,
          payload: event.payload,
          epoch: event.epoch,
          previousHash: previous == null ? null : chain.hash(previous),
        ),
      );
      lastPosition = ++_globalPosition;
      _byEventId.putIfAbsent(tenantId, () => {})[event.eventId] = _IndexedEvent(
        actionId: actionId,
        seq: seq,
        hash: hashes[index],
        position: lastPosition,
      );
    }
    _events.putIfAbsent(tenantId, () => {})[actionId] = committed;
    return AppendResult(
      firstSeq: firstSeq,
      lastSeq: actual + events.length,
      lastGlobalPosition: lastPosition,
      outcome: AppendResult.appended,
    );
  }

  int _currentSeq(String tenantId, String actionId) {
    final events = _events[tenantId]?[actionId];
    if (events == null || events.isEmpty) {
      return 0;
    }
    return events.last.seq;
  }
}

final class _IndexedEvent {
  const _IndexedEvent({
    required this.actionId,
    required this.seq,
    required this.hash,
    required this.position,
  });

  final String actionId;
  final int seq;
  final String hash;
  final int position;
}

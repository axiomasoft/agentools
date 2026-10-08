import 'dart:convert';

import 'package:crypto/crypto.dart';

import '../schema/canonicalizer.dart';
import '../ops/audit_redaction.dart';
import 'event_store_failure.dart';
import 'uncommitted_event.dart';

/// Shared append/load bounds and classify-before-insert checks for EventStore adapters.
final class EventStoreSupport {
  static const defaultPage = 500;
  static const maxPage = 1000;
  static const maxBatch = 64;

  EventStoreSupport({Canonicalizer? canonicalizer})
    : _canonicalizer = canonicalizer ?? Canonicalizer();

  final Canonicalizer _canonicalizer;

  void assertAppendable(
    String tenantId,
    String actionId,
    List<UncommittedEvent> events,
  ) {
    if (tenantId.isEmpty || actionId.isEmpty) {
      throw EventStoreFailure.streamIdentity();
    }
    if (events.isEmpty) {
      throw EventStoreFailure.batchEmpty();
    }
    if (events.length > maxBatch) {
      throw EventStoreFailure.batchLimit(events.length);
    }
    final seen = <String>{};
    for (final event in events) {
      if (!seen.add(event.eventId)) {
        throw EventStoreFailure.duplicateInBatch(event.eventId);
      }
      if (_isSeqDerived(event.eventId, actionId)) {
        throw EventStoreFailure.seqDerivedEventId(event.eventId);
      }
      _assertAuditPayload(event);
    }
  }

  String contentHash(String tenantId, String actionId, UncommittedEvent event) {
    final envelope = <String, Object?>{
      'action_id': actionId,
      'actor': event.actor.toMap(),
      'event_id': event.eventId,
      'event_version': event.eventVersion,
      'kind': event.kind,
      'payload': event.payload,
      'spec_rev': event.specRevision,
      'tenant_id': tenantId,
      'ts': event.timestamp,
      if (event.epoch != null) 'epoch': event.epoch,
    };
    final digest = _canonicalizer.canonicalize(envelope);
    return 'sha-256:${sha256.convert(utf8.encode(digest))}';
  }

  bool _isSeqDerived(String eventId, String actionId) {
    return RegExp(r'^ev-.+-\d+$').hasMatch(eventId) ||
        eventId.startsWith('ev-$actionId-');
  }

  void _assertAuditPayload(UncommittedEvent event) {
    if (!event.kind.startsWith('audit.')) {
      return;
    }
    AuditRedaction().assertPayload(event.payload, event.kind);
  }
}

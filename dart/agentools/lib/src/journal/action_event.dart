import 'actor.dart';

/// Immutable envelope for one append-only action journal record.
final class ActionEvent {
  ActionEvent({
    required this.eventId,
    required this.actionId,
    required this.seq,
    required this.expectedPrevSeq,
    required this.timestamp,
    required this.actor,
    required this.kind,
    required this.eventVersion,
    required this.specRevision,
    required Map<String, dynamic> payload,
    this.epoch,
    this.previousHash,
  })  : payload = Map.unmodifiable(payload),
        assert(eventId.isNotEmpty),
        assert(actionId.isNotEmpty),
        assert(timestamp.isNotEmpty),
        assert(kind.isNotEmpty),
        assert(specRevision.isNotEmpty),
        assert(seq >= 1),
        assert(expectedPrevSeq >= 0),
        assert(eventVersion >= 1),
        assert(epoch == null || epoch >= 1);

  final String eventId;
  final String actionId;
  final int seq;
  final int expectedPrevSeq;
  final String timestamp;
  final Actor actor;
  final String kind;
  final int eventVersion;
  final String specRevision;
  final Map<String, dynamic> payload;
  final int? epoch;
  final String? previousHash;

  /// ALC-36 input: the immutable event envelope without its chain link.
  Map<String, dynamic> hashInput() {
    final input = Map<String, dynamic>.from(toMap(includeNulls: false));
    input.remove('prev_hash');
    return input;
  }

  Map<String, dynamic> toMap({bool includeNulls = false}) {
    final map = <String, dynamic>{
      'event_id': eventId,
      'action_id': actionId,
      'seq': seq,
      'expected_prev_seq': expectedPrevSeq,
      'ts': timestamp,
      'actor': actor.toMap(),
      'kind': kind,
      'event_version': eventVersion,
      'spec_rev': specRevision,
      'epoch': epoch,
      'prev_hash': previousHash,
      'payload': _encodedPayload(),
    };
    if (includeNulls) {
      return Map.unmodifiable(map);
    }
    map.removeWhere((_, value) => value == null);
    return Map.unmodifiable(map);
  }

  ActionEvent withPreviousHash(String? previousHash) {
    return ActionEvent(
      eventId: eventId,
      actionId: actionId,
      seq: seq,
      expectedPrevSeq: expectedPrevSeq,
      timestamp: timestamp,
      actor: actor,
      kind: kind,
      eventVersion: eventVersion,
      specRevision: specRevision,
      payload: payload,
      epoch: epoch,
      previousHash: previousHash,
    );
  }

  Object _encodedPayload() {
    if (payload.isEmpty) {
      // PHP stores an empty payload as `[]`; hash-chain bytes depend on that shape.
      return const <dynamic>[];
    }
    return payload;
  }
}

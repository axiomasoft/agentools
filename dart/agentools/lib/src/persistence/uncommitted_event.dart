import '../journal/actor.dart';

/// Write DTO. Storage assigns seq, expected_prev_seq, prev_hash and global_position.
final class UncommittedEvent {
  UncommittedEvent({
    required this.eventId,
    required this.kind,
    required this.eventVersion,
    required this.specRevision,
    required this.actor,
    required this.timestamp,
    required Map<String, dynamic> payload,
    this.epoch,
  }) : payload = Map.unmodifiable(payload),
       assert(eventId.isNotEmpty),
       assert(kind.isNotEmpty),
       assert(specRevision.isNotEmpty),
       assert(timestamp.isNotEmpty),
       assert(eventVersion >= 1),
       assert(epoch == null || epoch >= 1);

  final String eventId;
  final String kind;
  final int eventVersion;
  final String specRevision;
  final Actor actor;
  final String timestamp;
  final Map<String, dynamic> payload;
  final int? epoch;
}

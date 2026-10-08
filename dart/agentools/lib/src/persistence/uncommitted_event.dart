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
  }) : payload = Map.unmodifiable(payload) {
    if (!(eventId.isNotEmpty)) {
      throw ArgumentError(
        'Invalid uncommitted_event field: eventId.isNotEmpty',
      );
    }
    if (!(kind.isNotEmpty)) {
      throw ArgumentError('Invalid uncommitted_event field: kind.isNotEmpty');
    }
    if (!(specRevision.isNotEmpty)) {
      throw ArgumentError(
        'Invalid uncommitted_event field: specRevision.isNotEmpty',
      );
    }
    if (!(timestamp.isNotEmpty)) {
      throw ArgumentError(
        'Invalid uncommitted_event field: timestamp.isNotEmpty',
      );
    }
    if (!(eventVersion >= 1)) {
      throw ArgumentError('Invalid uncommitted_event field: eventVersion >= 1');
    }
    if (!(epoch == null || epoch! >= 1)) {
      throw ArgumentError(
        'Invalid uncommitted_event field: epoch == null || epoch! >= 1',
      );
    }
  }

  final String eventId;
  final String kind;
  final int eventVersion;
  final String specRevision;
  final Actor actor;
  final String timestamp;
  final Map<String, dynamic> payload;
  final int? epoch;
}

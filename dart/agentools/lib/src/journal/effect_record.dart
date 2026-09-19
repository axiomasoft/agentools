import 'effect_status.dart';

final class EffectRecord {
  EffectRecord({
    required this.effectId,
    required this.status,
    required this.epoch,
    required this.requestedSeq,
    this.resolvedSeq,
    this.toolName,
    this.idempotencyKey,
    this.externalRef,
  });

  final String effectId;
  final EffectStatus status;
  final int epoch;
  final int requestedSeq;
  final int? resolvedSeq;
  final String? toolName;
  final String? idempotencyKey;
  final String? externalRef;

  EffectRecord resolve({
    required EffectStatus status,
    required int seq,
    required int epoch,
    String? externalRef,
  }) {
    return EffectRecord(
      effectId: effectId,
      status: status,
      epoch: epoch,
      requestedSeq: requestedSeq,
      resolvedSeq: seq,
      toolName: toolName,
      idempotencyKey: idempotencyKey,
      externalRef: externalRef,
    );
  }
}

import 'action_status.dart';
import 'effect_record.dart';
import 'effect_status.dart';

/// A derived projection. It is never used as the source for appending events.
final class FoldedAction {
  FoldedAction({
    this.actionId,
    this.status,
    this.seq = 0,
    this.epoch = 0,
    Map<String, dynamic>? inputs,
    Map<String, EffectRecord>? effects,
    this.cancellationRequested = false,
    this.previousHash,
  }) : inputs = Map.unmodifiable(inputs ?? const {}),
       effects = Map.unmodifiable(effects ?? const {});

  final String? actionId;
  final ActionStatus? status;
  final int seq;
  final int epoch;
  final Map<String, dynamic> inputs;
  final Map<String, EffectRecord> effects;
  final bool cancellationRequested;
  final String? previousHash;

  bool hasUnresolvedEffect() {
    for (final effect in effects.values) {
      if (effect.status == EffectStatus.requested) {
        return true;
      }
    }
    return false;
  }
}

import '../journal/action_event.dart';
import '../journal/action_journal.dart';
import '../journal/effect_status.dart';
import 'effect_applier.dart';
import 'effect_reconciliation_required.dart';

/// The external call is reachable only after the action's effect registry has
/// been read and a fresh [effect_requested] event has been appended.
final class EffectCommitter {
  ActionJournal commit(
    ActionJournal journal,
    ActionEvent request,
    EffectApplier applier,
  ) {
    if (request.kind != 'effect_requested') {
      throw ArgumentError('EffectCommitter accepts only effect_requested events.');
    }
    final effectId = request.payload['effect_id'];
    if (effectId is! String || effectId.isEmpty) {
      throw ArgumentError('An effect_requested event requires effect_id.');
    }

    final existing = journal.state().effects[effectId];
    if (existing?.status == EffectStatus.confirmed) {
      return journal;
    }
    if (existing?.status == EffectStatus.requested) {
      throw EffectReconciliationRequired();
    }

    final updated = journal.append(request);
    final effect = updated.state().effects[effectId];
    if (effect == null) {
      throw StateError('The effect registry did not record the request.');
    }
    applier.apply(effect);

    return updated;
  }
}

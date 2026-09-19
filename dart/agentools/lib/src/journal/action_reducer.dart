import 'action_event.dart';
import 'action_status.dart';
import 'effect_record.dart';
import 'effect_status.dart';
import 'event_upcaster_chain.dart';
import 'folded_action.dart';
import 'journal_hash_chain.dart';
import 'journal_integrity_exception.dart';
import 'lifecycle_exception.dart';
import 'stale_executor_epoch.dart';
import 'version_conflict.dart';

/// Pure lifecycle fold. Every validation is performed in ALC-16 order.
final class ActionReducer {
  ActionReducer({
    this.specRevision = '2026-08',
    JournalHashChain? hashChain,
    EventUpcasterChain? upcasters,
  })  : _hashChain = hashChain,
        _upcasters = upcasters ?? EventUpcasterChain();

  static const _knownKinds = <String>{
    'action_created',
    'action_started',
    'input_recorded',
    'epoch_started',
    'confirmation_requested',
    'confirmation_resolved',
    'effect_requested',
    'effect_confirmed',
    'effect_failed',
    'action_completed',
    'action_failed',
    'task_bound',
    'task_binding_lost',
    'cancellation_requested',
    'action_cancelled',
  };

  final String specRevision;
  final JournalHashChain? _hashChain;
  final EventUpcasterChain _upcasters;

  FoldedAction fold(List<ActionEvent> events) {
    var state = FoldedAction();
    for (final event in events) {
      state = apply(state, _upcasters.upcast(event));
    }
    return state;
  }

  FoldedAction apply(FoldedAction state, ActionEvent event) {
    if (state.status?.isTerminal() ?? false) {
      throw LifecycleException('A terminal action cannot accept another event.');
    }
    if (event.seq != state.seq + 1) {
      throw LifecycleException('Action journal sequence has a gap.');
    }
    if (event.expectedPrevSeq != state.seq) {
      throw VersionConflict(
        expected: event.expectedPrevSeq,
        actual: state.seq,
      );
    }
    if (event.specRevision != specRevision) {
      throw LifecycleException(
        'The event was written by another specification revision.',
      );
    }
    if (state.actionId != null && event.actionId != state.actionId) {
      throw LifecycleException(
        'All records in an action journal must have the same action id.',
      );
    }
    if (_isEffectEvent(event.kind) &&
        (event.epoch == null || event.epoch! < state.epoch)) {
      throw StaleExecutorEpoch(
        eventEpoch: event.epoch ?? 0,
        currentEpoch: state.epoch,
      );
    }
    if (_hashChain != null && event.previousHash != state.previousHash) {
      throw JournalIntegrityException(
        'The event previous hash does not match the journal chain.',
      );
    }

    if (!_knownKinds.contains(event.kind)) {
      return FoldedAction(
        actionId: event.actionId,
        status: state.status,
        seq: event.seq,
        epoch: state.epoch,
        inputs: state.inputs,
        effects: state.effects,
        cancellationRequested: state.cancellationRequested,
        previousHash: _hash(event),
      );
    }

    final status = _nextStatus(state, event);
    var inputs = state.inputs;
    var effects = state.effects;
    var epoch = state.epoch;
    var cancelled = state.cancellationRequested;

    if (event.kind == 'epoch_started') {
      if (event.epoch == null || event.epoch! <= state.epoch) {
        throw LifecycleException(
          'Executor epoch must increase monotonically.',
        );
      }
      epoch = event.epoch!;
    }
    if (event.kind == 'input_recorded') {
      final name = _requiredString(event, 'name');
      inputs = Map<String, dynamic>.from(inputs)..[name] = event.payload['value'];
    }
    if (event.kind == 'effect_requested') {
      final effectId = _requiredString(event, 'effect_id');
      final eventEpoch = _requiredEpoch(event);
      if (effects.containsKey(effectId)) {
        throw LifecycleException('An effect can only be requested once.');
      }
      effects = Map<String, EffectRecord>.from(effects)
        ..[effectId] = EffectRecord(
          effectId: effectId,
          status: EffectStatus.requested,
          epoch: eventEpoch,
          requestedSeq: event.seq,
          toolName: _optionalString(event, 'tool_name'),
          idempotencyKey: _optionalString(event, 'idempotency_key'),
        );
    }
    if (event.kind == 'effect_confirmed' || event.kind == 'effect_failed') {
      final effectId = _requiredString(event, 'effect_id');
      final eventEpoch = _requiredEpoch(event);
      final effect = effects[effectId];
      if (effect == null || effect.status != EffectStatus.requested) {
        throw LifecycleException(
          'An effect outcome requires one unresolved effect request.',
        );
      }
      if (eventEpoch != state.epoch) {
        throw LifecycleException(
          'An effect outcome must be written by the current executor epoch.',
        );
      }
      effects = Map<String, EffectRecord>.from(effects)
        ..[effectId] = effect.resolve(
          status: event.kind == 'effect_confirmed'
              ? EffectStatus.confirmed
              : EffectStatus.failed,
          seq: event.seq,
          epoch: eventEpoch,
          externalRef: _optionalString(event, 'external_ref'),
        );
    }
    if (event.kind == 'cancellation_requested') {
      cancelled = true;
    }

    return FoldedAction(
      actionId: event.actionId,
      status: status,
      seq: event.seq,
      epoch: epoch,
      inputs: inputs,
      effects: effects,
      cancellationRequested: cancelled,
      previousHash: _hash(event),
    );
  }

  ActionStatus _nextStatus(FoldedAction state, ActionEvent event) {
    final from = state.status;
    final kind = event.kind;
    if (kind == 'action_created' &&
        from == null &&
        event.seq == 1 &&
        event.expectedPrevSeq == 0) {
      return ActionStatus.created;
    }
    if (from == null) {
      throw LifecycleException('The first known event must create the action.');
    }

    switch (from) {
      case ActionStatus.created:
        return switch (kind) {
          'input_recorded' ||
          'epoch_started' ||
          'cancellation_requested' =>
            from,
          'action_started' => ActionStatus.running,
          'action_cancelled' => _cancel(state),
          _ => _invalidTransition(from, kind),
        };
      case ActionStatus.running:
        return switch (kind) {
          'input_recorded' ||
          'epoch_started' ||
          'task_bound' ||
          'task_binding_lost' ||
          'cancellation_requested' =>
            from,
          'confirmation_requested' => _confirmationRequested(event),
          'effect_requested' => ActionStatus.applying,
          'action_completed' => ActionStatus.completed,
          'action_failed' => ActionStatus.failed,
          'action_cancelled' => _cancel(state),
          _ => _invalidTransition(from, kind),
        };
      case ActionStatus.awaitingConfirmation:
        return switch (kind) {
          'confirmation_requested' ||
          'epoch_started' ||
          'cancellation_requested' =>
            from,
          'confirmation_resolved' => _confirmationResolved(event),
          'action_failed' => ActionStatus.failed,
          'action_cancelled' => _cancel(state),
          _ => _invalidTransition(from, kind),
        };
      case ActionStatus.applying:
        return switch (kind) {
          'effect_confirmed' || 'effect_failed' => ActionStatus.running,
          'effect_requested' ||
          'epoch_started' ||
          'task_bound' ||
          'cancellation_requested' =>
            from,
          'task_binding_lost' => state.hasUnresolvedEffect()
              ? ActionStatus.reconciliationRequired
              : _invalidTransition(from, kind),
          'action_failed' => ActionStatus.failed,
          _ => _invalidTransition(from, kind),
        };
      case ActionStatus.reconciliationRequired:
        return switch (kind) {
          'effect_confirmed' || 'effect_failed' => ActionStatus.running,
          'epoch_started' || 'cancellation_requested' => from,
          'action_failed' => ActionStatus.failed,
          _ => _invalidTransition(from, kind),
        };
      default:
        throw LifecycleException('A terminal action cannot transition.');
    }
  }

  ActionStatus _confirmationRequested(ActionEvent event) {
    _requiredString(event, 'deadline');
    return ActionStatus.awaitingConfirmation;
  }

  ActionStatus _confirmationResolved(ActionEvent event) {
    return switch (_requiredString(event, 'decision')) {
      'approve' => ActionStatus.running,
      'reject' || 'revoke' => ActionStatus.rejected,
      'expire' => ActionStatus.expired,
      _ => throw LifecycleException(
          'The confirmation decision is not in the transition table.',
        ),
    };
  }

  ActionStatus _cancel(FoldedAction state) {
    if (!state.cancellationRequested || state.hasUnresolvedEffect()) {
      throw LifecycleException(
        'The action cannot be cancelled in its current state.',
      );
    }
    return ActionStatus.cancelled;
  }

  Never _invalidTransition(ActionStatus from, String kind) {
    throw LifecycleException('Event $kind is not valid from ${from.value}.');
  }

  bool _isEffectEvent(String kind) {
    return kind == 'effect_requested' ||
        kind == 'effect_confirmed' ||
        kind == 'effect_failed';
  }

  String _requiredString(ActionEvent event, String field) {
    final value = event.payload[field];
    if (value is! String || value.isEmpty) {
      throw LifecycleException(
        'Event ${event.kind} requires a non-empty $field payload field.',
      );
    }
    return value;
  }

  String? _optionalString(ActionEvent event, String field) {
    if (!event.payload.containsKey(field)) {
      return null;
    }
    return _requiredString(event, field);
  }

  int _requiredEpoch(ActionEvent event) {
    if (event.epoch == null) {
      throw LifecycleException(
        'Event ${event.kind} requires an executor epoch.',
      );
    }
    return event.epoch!;
  }

  String? _hash(ActionEvent event) {
    return _hashChain?.hash(event);
  }
}

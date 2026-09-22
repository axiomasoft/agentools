/// SDK semantic profile exporter for exact-set parity (P6.2).
library;

import 'dart:convert';
import 'dart:io';

import 'package:agentools/agentools.dart';
import 'package:agentools/src/persistence/event_store_support.dart';

const _categoryDefaultRetryable = {
  'validation': false,
  'permission': false,
  'not_found': false,
  'precondition': false,
  'conflict': true,
  'unavailable': true,
  'budget': false,
  'internal': false,
};

const _codes = [
  IdempotencyErrorCodes.keyMissing,
  IdempotencyErrorCodes.inFlight,
  IdempotencyErrorCodes.keyReuse,
  IdempotencyErrorCodes.lostLease,
  IdempotencyErrorCodes.ambiguousEffect,
  IdempotencyErrorCodes.capabilityUnknown,
  JournalErrorCodes.versionConflict,
  JournalErrorCodes.eventIdCollision,
  JournalErrorCodes.eventBatchPartialOverlap,
  JournalErrorCodes.eventBatchEmpty,
  JournalErrorCodes.eventBatchLimit,
  JournalErrorCodes.eventIdDuplicateInBatch,
  JournalErrorCodes.eventIdSeqDerived,
  JournalErrorCodes.eventStreamIdentity,
  JournalErrorCodes.auditForbiddenField,
  JournalErrorCodes.metricLabelForbidden,
  JournalErrorCodes.eventPageLimit,
  ToolProfileErrorCodes.descriptorDrift,
  ConfirmationErrorCodes.argsMismatch,
  ConfirmationErrorCodes.expired,
  ConfirmationErrorCodes.tokenSpent,
  ConfirmationErrorCodes.principalMismatch,
  ConfirmationErrorCodes.tokenInvalid,
  ConfirmationErrorCodes.recordMissing,
  ConfirmationErrorCodes.contextMismatch,
  ConfirmationErrorCodes.policyDenied,
  ConfirmationErrorCodes.policyChanged,
  ConfirmationErrorCodes.approvalMissing,
  ConfirmationErrorCodes.approvalStale,
  ConfirmationErrorCodes.approvalMismatch,
  ConfirmationErrorCodes.quorumInsufficient,
  ConfirmationErrorCodes.separationViolation,
  ConfirmationErrorCodes.authorizationDenied,
  ConfirmationErrorCodes.tokenKeyDenied,
  ConfirmationErrorCodes.tokenBindingMismatch,
  ConfirmationErrorCodes.tokenNotYetValid,
  ConfirmationErrorCodes.tokenCollision,
  OpsErrorCodes.foreignAccessToken,
  OpsErrorCodes.taskInputRequiredWhileApplying,
  OpsErrorCodes.baggageLimitExceeded,
  'agentools.precondition.extension_unsupported',
  'agentools.budget.clarification_rounds_exhausted',
  'agentools.validation.unknown_result_type',
  'agentools.budget.limit_exceeded',
  'agentools.validation.canonicalization_failed',
];

Map<String, dynamic> buildSemanticProfile() {
  final rows = <Map<String, dynamic>>[];
  for (final code in _codes) {
    final parts = code.split('.');
    final category = parts.length >= 2 ? parts[1] : 'internal';
    rows.add({
      'code': code,
      'category': category,
      'default_retryable': _categoryDefaultRetryable[category] ?? false,
      'source': 'dart/agentools/conformance/semantic_profile.dart',
    });
  }
  rows.sort((a, b) => (a['code'] as String).compareTo(b['code'] as String));

  final caseRetryability = <Map<String, dynamic>>[];
  final fixtureRoot = Directory('fixtures/hardening').existsSync()
      ? Directory('fixtures/hardening')
      : Directory('../../fixtures/hardening');
  for (final file in fixtureRoot.listSync().whereType<File>().where(
    (file) => file.path.endsWith('-v1.json'),
  )) {
    final document =
        jsonDecode(file.readAsStringSync()) as Map<String, dynamic>;
    for (final raw in (document['cases'] as List<dynamic>? ?? const [])) {
      final item = raw as Map<String, dynamic>;
      final code = item['semantic_code'] as String? ?? '';
      final retryable =
          code == IdempotencyErrorCodes.inFlight ||
          code == JournalErrorCodes.versionConflict;
      caseRetryability.add({
        'case_id': item['id'],
        'semantic_code': code,
        'category': code == 'ok' ? 'success' : code.split('.')[1],
        'retryable': retryable,
      });
    }
  }
  caseRetryability.sort(
    (a, b) => (a['case_id'] as String).compareTo(b['case_id'] as String),
  );

  final states = <Map<String, dynamic>>[];
  void addStates(String domain, Iterable<String> values) {
    for (final value in values) {
      states.add({'key': '$domain:$value', 'domain': domain, 'value': value});
    }
  }

  addStates('action', ActionStatus.values.map((value) => value.value));
  addStates('proposal', ProposalState.values.map((value) => value.value));
  addStates(
    'idempotency',
    DeduplicationState.values.map((value) => value.value),
  );
  states.sort((a, b) => (a['key'] as String).compareTo(b['key'] as String));

  Map<String, dynamic> transition(
    String id,
    String? from,
    String event,
    String to,
  ) => {'transition': id, 'from': from, 'event': event, 'to': to};

  final transitions = [
    transition('ALC-T1', null, 'action_created', 'created'),
    transition('ALC-T2', 'created', 'input_recorded', 'created'),
    transition('ALC-T3', 'created', 'epoch_started', 'created'),
    transition('ALC-T4', 'created', 'action_started', 'running'),
    transition('ALC-T5', 'running', 'input_recorded', 'running'),
    transition('ALC-T6', 'running', 'epoch_started', 'running'),
    transition(
      'ALC-T7',
      'running',
      'confirmation_requested',
      'awaiting_confirmation',
    ),
    transition('ALC-T8', 'running', 'effect_requested', 'applying'),
    transition('ALC-T9', 'running', 'action_completed', 'completed'),
    transition('ALC-T10', 'running', 'action_failed', 'failed'),
    transition(
      'ALC-T11',
      'awaiting_confirmation',
      'confirmation_resolved',
      'running',
    ),
    transition(
      'ALC-T12',
      'awaiting_confirmation',
      'confirmation_resolved',
      'rejected',
    ),
    transition(
      'ALC-T13',
      'awaiting_confirmation',
      'confirmation_resolved',
      'rejected',
    ),
    transition(
      'ALC-T14',
      'awaiting_confirmation',
      'confirmation_resolved',
      'expired',
    ),
    transition(
      'ALC-T15',
      'awaiting_confirmation',
      'confirmation_requested',
      'awaiting_confirmation',
    ),
    transition(
      'ALC-T16',
      'awaiting_confirmation',
      'epoch_started',
      'awaiting_confirmation',
    ),
    transition('ALC-T17', 'awaiting_confirmation', 'action_failed', 'failed'),
    transition('ALC-T18', 'applying', 'effect_confirmed', 'running'),
    transition('ALC-T19', 'applying', 'effect_failed', 'running'),
    transition('ALC-T20', 'applying', 'effect_requested', 'applying'),
    transition('ALC-T21', 'applying', 'epoch_started', 'applying'),
    transition('ALC-T22', 'applying', 'action_failed', 'failed'),
    transition('ALC-T23', 'running', 'task_bound', 'running'),
    transition('ALC-T24', 'applying', 'task_bound', 'applying'),
    transition('ALC-T25', 'running', 'task_binding_lost', 'running'),
    transition(
      'ALC-T26',
      'applying',
      'task_binding_lost',
      'reconciliation_required',
    ),
    transition(
      'ALC-T27',
      'reconciliation_required',
      'effect_confirmed',
      'running',
    ),
    transition(
      'ALC-T28',
      'reconciliation_required',
      'effect_failed',
      'running',
    ),
    transition(
      'ALC-T29',
      'reconciliation_required',
      'epoch_started',
      'reconciliation_required',
    ),
    transition('ALC-T30', 'reconciliation_required', 'action_failed', 'failed'),
    transition('ALC-T31', 'created', 'cancellation_requested', 'created'),
    transition('ALC-T32', 'running', 'cancellation_requested', 'running'),
    transition(
      'ALC-T33',
      'awaiting_confirmation',
      'cancellation_requested',
      'awaiting_confirmation',
    ),
    transition('ALC-T34', 'applying', 'cancellation_requested', 'applying'),
    transition(
      'ALC-T35',
      'reconciliation_required',
      'cancellation_requested',
      'reconciliation_required',
    ),
    transition('ALC-T36', 'created', 'action_cancelled', 'cancelled'),
    transition('ALC-T37', 'running', 'action_cancelled', 'cancelled'),
    transition(
      'ALC-T38',
      'awaiting_confirmation',
      'action_cancelled',
      'cancelled',
    ),
  ];

  final limits = ExpressionLimits();

  return {
    'schema_version': 'semantic-profile/v1',
    'spec_revision': '2026-09',
    'implementation': 'dart',
    'error_codes': rows,
    'case_retryability': caseRetryability,
    'states': states,
    'transitions': transitions,
    'envelope_versions': [
      {
        'envelope': 'confirmation_token',
        'version': TokenEnvelope.version,
        'prefix': TokenEnvelope.prefix,
      },
    ],
    'bounds': [
      {'bound': 'baggage.max_bytes', 'value': BaggageRefs.maxBytes},
      {'bound': 'baggage.max_items', 'value': BaggageRefs.maxItems},
      {'bound': 'event_store.max_batch', 'value': EventStoreSupport.maxBatch},
      {'bound': 'event_store.max_page', 'value': EventStoreSupport.maxPage},
      {
        'bound': 'expression.max_collection_size',
        'value': limits.maxCollectionSize,
      },
      {'bound': 'expression.max_depth', 'value': limits.maxDepth},
      {'bound': 'expression.max_length', 'value': limits.maxLength},
      {
        'bound': 'expression.max_visited_nodes',
        'value': limits.maxVisitedNodes,
      },
    ],
    'outcomes': ScenarioOutcome.values
        .map(
          (value) => {
            'key': 'scenario:${value.wireValue}',
            'domain': 'scenario',
            'value': value.wireValue,
          },
        )
        .toList(),
    'capabilities': ToolIdempotencyCapability.values
        .map((value) => {'capability': value.value})
        .toList(),
    'canonicalization_profile': 'RFC8785',
  };
}

void main() {
  // ignore: avoid_print
  print(jsonEncode(buildSemanticProfile()));
}

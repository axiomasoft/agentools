/// SDK semantic profile exporter for exact-set parity (P6.2).
library;

import 'dart:convert';

import 'package:agentools/agentools.dart';

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

  return {
    'schema_version': 'semantic-profile/v1',
    'spec_revision': '2026-09',
    'implementation': 'dart',
    'error_codes': rows,
    'canonicalization_profile': 'RFC8785',
  };
}

void main() {
  // ignore: avoid_print
  print(jsonEncode(buildSemanticProfile()));
}

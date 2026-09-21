import '../contracts/external_effect_probe.dart';
import '../contracts/idempotency_store.dart';
import '../contracts/tool_port.dart';
import '../errors/tool_execution_result.dart';
import '../schema/canonicalizer.dart';
import '../tool/tool_execution_exception.dart';
import 'arguments_fingerprint.dart';
import 'deduplication_state.dart';
import 'idempotency_acquisition.dart';
import 'idempotency_failure.dart';
import 'idempotency_key.dart';
import 'idempotency_lease.dart';
import 'idempotency_request.dart';
import 'idempotency_scope.dart';
import 'lease_duration.dart';
import 'stored_result_digest.dart';
import 'stored_tool_result.dart';
import 'tool_idempotency_capability.dart';
import 'tool_idempotency_catalog.dart';

/// Supported fenced invoke path. Store and catalog are required.
final class IdempotencyCoordinator implements ToolPort {
  IdempotencyCoordinator({
    required IdempotencyStore store,
    required ToolIdempotencyCatalog catalog,
    required ToolPort tools,
    required Canonicalizer canonicalizer,
    required this.tenantId,
    required this.actionId,
    this.leaseDuration = LeaseDuration.defaultDuration,
    this.probe,
    DateTime Function()? clock,
  }) : _store = store,
       _catalog = catalog,
       _tools = tools,
       _canonicalizer = canonicalizer,
       _clock = clock {
    if (tenantId.isEmpty || actionId.isEmpty) {
      throw ArgumentError(
        'Coordinator tenant id and action id must not be empty.',
      );
    }
  }

  final IdempotencyStore _store;
  final ToolIdempotencyCatalog _catalog;
  final ToolPort _tools;
  final Canonicalizer _canonicalizer;
  final String tenantId;
  final String actionId;
  final Duration leaseDuration;
  final ExternalEffectProbe? probe;
  final DateTime Function()? _clock;

  @override
  ToolExecutionResult call(
    String tool,
    Map<String, dynamic> arguments,
    String idempotencyKey,
  ) {
    try {
      return _invoke(tool, arguments, idempotencyKey);
    } on ToolExecutionException catch (exception) {
      return ToolExecutionResult.failure(exception.error);
    }
  }

  ToolExecutionResult _invoke(
    String tool,
    Map<String, dynamic> arguments,
    String idempotencyKey,
  ) {
    final capability = _catalog.capability(tool);
    if (capability == ToolIdempotencyCapability.unknown &&
        _catalog.isHighRisk(tool)) {
      throw IdempotencyFailure.capabilityUnknown(tool);
    }
    final key = IdempotencyKey.required(idempotencyKey);
    final now = _now();
    final request = IdempotencyRequest(
      scope: IdempotencyScope(tenantId: tenantId, toolName: tool, key: key),
      argumentsFingerprint: ArgumentsFingerprint.fromArguments(
        arguments,
        _canonicalizer,
      ),
      actionId: actionId,
    );
    final acquisition = _store.acquire(request, now, leaseDuration);
    if (acquisition.state == DeduplicationState.duplicateCompleted) {
      return _replay(acquisition);
    }
    if (acquisition.state == DeduplicationState.concurrentInFlight) {
      return ToolExecutionResult.failure(
        acquisition.error ?? IdempotencyFailure.inFlight(key.value).error,
      );
    }
    final lease = acquisition.lease;
    if (lease == null) {
      throw StateError('A new acquisition must carry a lease.');
    }
    if (acquisition.takeover) {
      final decided = _takeoverDecision(
        capability,
        tool,
        idempotencyKey,
        lease,
        now,
      );
      if (decided != null) {
        return decided;
      }
    }
    _store.heartbeat(lease, now, leaseDuration);
    final result = _tools.call(tool, arguments, idempotencyKey);
    final stored = _stored(result);
    final digest = StoredResultDigest.fromJcs(
      _canonicalizer.canonicalize(stored.error?.toMap() ?? stored.value ?? {}),
    );
    try {
      if (result.isError) {
        _store.fail(lease, stored, digest, _now());
      } else {
        _store.complete(lease, stored, digest, _now());
      }
    } on IdempotencyFailure catch (failure) {
      if (failure.error.code == IdempotencyFailure.lostLease().error.code) {
        return ToolExecutionResult.failure(failure.error);
      }
      rethrow;
    }
    return result;
  }

  ToolExecutionResult? _takeoverDecision(
    ToolIdempotencyCapability capability,
    String tool,
    String idempotencyKey,
    IdempotencyLease lease,
    DateTime now,
  ) {
    if (capability == ToolIdempotencyCapability.nativeKey) {
      return null;
    }
    if (capability == ToolIdempotencyCapability.readBeforeWrite) {
      final probe = this.probe;
      if (probe == null) {
        return _storeAmbiguous(lease, now);
      }
      final found = probe.find(tool, idempotencyKey);
      if (found == null) {
        return null;
      }
      final digest = StoredResultDigest.fromJcs(
        _canonicalizer.canonicalize(found.error?.toMap() ?? found.value ?? {}),
      );
      _store.complete(lease, found, digest, now);
      if (found.error != null) {
        return ToolExecutionResult.failure(found.error!);
      }
      return ToolExecutionResult.success(found.value ?? {});
    }
    return _storeAmbiguous(lease, now);
  }

  ToolExecutionResult _storeAmbiguous(IdempotencyLease lease, DateTime now) {
    final error = IdempotencyFailure.ambiguousEffect().error;
    final stored = StoredToolResult(error: error);
    final digest = StoredResultDigest.fromJcs(
      _canonicalizer.canonicalize(error.toMap()),
    );
    _store.fail(lease, stored, digest, now);
    return ToolExecutionResult.failure(error);
  }

  ToolExecutionResult _replay(IdempotencyAcquisition acquisition) {
    final stored = acquisition.storedResult;
    if (stored == null) {
      throw StateError('A completed duplicate must carry a stored result.');
    }
    if (stored.error != null) {
      return ToolExecutionResult.failure(stored.error!);
    }
    return ToolExecutionResult.success(stored.value ?? {});
  }

  StoredToolResult _stored(ToolExecutionResult result) {
    if (result.error != null) {
      return StoredToolResult(error: result.error);
    }
    return StoredToolResult(value: result.value ?? {});
  }

  DateTime _now() => _clock?.call() ?? DateTime.now().toUtc();
}

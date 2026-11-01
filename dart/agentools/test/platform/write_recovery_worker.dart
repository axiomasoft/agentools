import 'dart:convert';
import 'dart:io';
import 'package:agentools/agentools.dart';
import 'package:agentools/agentools_testkit.dart';
import '../confirmation/platform_binding_test.dart' show PlatformBindingPorts;

List<Map<String, dynamic>> recoveryCases() {
  final data =
      jsonDecode(
            File(
              '../../fixtures/platform/v1/recovery/portable.json',
            ).readAsStringSync(),
          )
          as Map<String, dynamic>;
  final cases = (data['cases'] as List<dynamic>).cast<Map<String, dynamic>>();
  if (cases.length != 39) {
    throw StateError('Exact required portable cases changed');
  }
  return cases;
}

Map<String, dynamic> exerciseRecovery(Map<String, dynamic> item) {
  switch (item['kind']) {
    case 'action':
      var state = FoldedAction();
      var accepted = true;
      final reducer = ActionReducer(
        hashChain: JournalHashChain(Canonicalizer()),
      );
      for (final raw in item['events'] as List<dynamic>) {
        final event = raw as Map<String, dynamic>;
        try {
          state = reducer.apply(
            state,
            ActionEvent(
              eventId: 'event-${state.seq + 1}',
              actionId: 'action',
              seq: event['seq'] as int? ?? state.seq + 1,
              expectedPrevSeq: event['expected_prev_seq'] as int? ?? state.seq,
              timestamp: '2026-11-01T00:00:00Z',
              actor: Actor(type: 'host', id: 'owner'),
              kind: event['kind'] as String,
              eventVersion: 1,
              specRevision: '2026-09',
              payload: event['payload'] as Map<String, dynamic>? ?? {},
              epoch: event['epoch'] as int?,
              previousHash: state.previousHash,
            ),
          );
        } on LifecycleException {
          accepted = false;
          break;
        }
      }
      return {
        'accepted': accepted,
        'status': state.status?.value,
        'seq': state.seq,
        'cancelled': state.cancellationRequested,
        'outcome': switch (state.status?.value) {
          'completed' => 'succeeded',
          'failed' => 'failed',
          'applying' || 'reconciliation_required' => 'unknown',
          _ => null,
        },
        'effects': [
          for (final entry in state.effects.entries)
            {
              'id': entry.key,
              'status': entry.value.status.value,
              'epoch': entry.value.epoch,
            },
        ],
      };
    case 'confirmation':
      final ports = PlatformBindingPorts();
      final store = InMemoryConfirmationTokenStore();
      final keys = StaticTokenKeyProvider(
        SigningKey(
          id: KeyId('test-v1'),
          algorithm: 'HS256',
          secret: utf8.encode('s' * 32),
        ),
      );
      final service = ConfirmationService(
        protocol: ConfirmationProtocol(
          keys: keys,
          store: store,
          issuer: 'platform-tests',
          audience: 'confirmation',
        ),
        keys: keys,
        store: store,
        issuer: 'platform-tests',
        audience: 'confirmation',
        actors: ports,
        clock: ports,
        descriptors: ports,
        contexts: ports,
        policies: ports,
        records: ports,
        approvals: ports,
        authorizer: ports,
        canonicalizer: Canonicalizer(),
      );
      final args = item['arguments'] as Map<String, dynamic>;
      final change = item['change'] as Map<String, dynamic>;
      final token = service.prepare(
        actionId: 'action-1',
        toolName: 'platform_confirmed_action',
        arguments: args,
        preview: {
          'summary': 'Exact write',
          'changes': [args],
        },
      );
      ports.binding = change['descriptor_binding'] as String? ?? ports.binding;
      ports.revision = change['policy_revision'] as String? ?? ports.revision;
      ports.actor = change['actor'] as String? ?? ports.actor;
      ports.tenant = change['tenant'] as String? ?? ports.tenant;
      ports.proof = change['proof_source'] as String? ?? ports.proof;
      ports.instant = ports.instant.add(
        Duration(seconds: change['elapsed_seconds'] as int? ?? 0),
      );
      if (change['consume_after_denial'] == true) {
        ports.proof = 'none';
        try {
          service.commit(token: token.value, arguments: args);
        } on ConfirmationFailure {
          /* The unchanged protocol consumes this presentation. */
        }
        ports.proof = 'ui';
      }
      if (change['replay'] == true) {
        service.commit(token: token.value, arguments: args);
      }
      try {
        service.commit(
          token: token.value,
          arguments: change['arguments'] ?? args,
        );
        return {'accepted': true};
      } on ConfirmationFailure {
        return {'accepted': false};
      } on DescriptorDrift {
        return {'accepted': false};
      }
    case 'retry':
      final store = InMemoryDeduplicationStore(
        DeduplicationPolicy(
          retention: const Duration(days: 1),
          confirmationLifetime: Duration.zero,
          clientRetryWindow: Duration.zero,
        ),
      );
      final start = DateTime.parse('2026-11-01T00:00:00Z');
      store.acquire(
        IdempotencyRequest(
          scope: IdempotencyScope(
            tenantId: 'tenant-a',
            toolName: 'write',
            key: IdempotencyKey('at1:abcdefgh'),
          ),
          argumentsFingerprint: ArgumentsFingerprint.fromArguments({
            'amount': 1,
          }, Canonicalizer()),
          actionId: 'action',
        ),
        start,
        const Duration(seconds: 30),
      );
      final port = _Owner();
      final capability = switch (item['capability']) {
        'native_key' => ToolIdempotencyCapability.nativeKey,
        'read_before_write' => ToolIdempotencyCapability.readBeforeWrite,
        'at_most_once_only' => ToolIdempotencyCapability.atMostOnceOnly,
        _ => ToolIdempotencyCapability.unknown,
      };
      final coordinator = IdempotencyCoordinator(
        store: store,
        catalog: ToolIdempotencyCatalog({'write': capability}),
        tools: port,
        canonicalizer: Canonicalizer(),
        tenantId: 'tenant-a',
        actionId: 'action',
        clock: () =>
            start.add(Duration(seconds: item['elapsed_seconds'] as int)),
        probe: item['probe'] == 'missing'
            ? null
            : _Probe(item['probe'] == 'found'),
      );
      final args = {'amount': item['changed_hash'] == true ? 2 : 1};
      final first = coordinator.call('write', args, 'at1:abcdefgh');
      final second = coordinator.call('write', args, 'at1:abcdefgh');
      return {
        'accepted': !first.isError,
        'calls': port.calls,
        'code': first.error?.code,
        'replay_equal':
            jsonEncode(first.value) == jsonEncode(second.value) &&
            first.error?.code == second.error?.code,
      };
    default:
      throw StateError('Unsupported required recovery case');
  }
}

final class _Owner implements ToolPort {
  int calls = 0;
  @override
  ToolExecutionResult call(
    String tool,
    Map<String, dynamic> arguments,
    String idempotencyKey,
  ) {
    ++calls;
    return ToolExecutionResult.success({'receipt': 'first'});
  }
}

final class _Probe implements ExternalEffectProbe {
  _Probe(this.found);
  final bool found;
  @override
  StoredToolResult? find(String tool, String idempotencyKey) =>
      found ? StoredToolResult(value: {'receipt': 'first'}) : null;
}

void main() {
  print(
    jsonEncode({
      'consumer': 'dart-sdk',
      'tier': 'contract-scripted',
      'cases': [
        for (final item in recoveryCases())
          {'id': item['id'], 'observed': exerciseRecovery(item)},
      ],
    }),
  );
}

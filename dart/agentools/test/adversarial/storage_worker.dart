import 'dart:convert';
import 'dart:io';
import 'dart:math';

import 'package:agentools/agentools.dart';
import 'package:crypto/crypto.dart';
import 'package:postgres/postgres.dart';

Future<void> main() async {
  stdout.writeln(jsonEncode({'type': 'ready', 'pid': pid}));
  await stdout.flush();
  final worker = _Worker();
  await for (final line
      in stdin.transform(utf8.decoder).transform(const LineSplitter())) {
    if (line.trim().isEmpty) {
      continue;
    }
    final message = jsonDecode(line) as Map<String, dynamic>;
    stdout.writeln(jsonEncode(await worker.handle(message)));
    await stdout.flush();
    if (message['op'] == 'shutdown') {
      return;
    }
  }
}

final class _Worker {
  Connection? _conn;
  String _mutant = '';
  IdempotencyRequest? _request;
  String? _leaseToken;
  var _leaseAttempt = 0;
  List<UncommittedEvent> _prepared = const [];
  var _preparedActual = 0;
  var _preparedTenant = '';
  var _preparedAction = '';
  var _inserted = 0;

  Future<Map<String, Object?>> handle(Map<String, dynamic> message) async {
    try {
      if (message['type'] == 'hello') {
        return await _hello(message);
      }
      final id = message['id'] as String? ?? '';
      final op = message['op'] as String? ?? '';
      final args = Map<String, dynamic>.from(
        (message['args'] as Map?) ?? const {},
      );
      final payload = await switch (op) {
        'begin' => _begin(),
        'commit' => _commit(),
        'rollback' => _rollback(),
        'consume' => _consume(args),
        'acquire' => _acquire(args),
        'heartbeat' => _heartbeat(args),
        'complete' => _finish(args, fail: false),
        'fail' => _finish(args, fail: true),
        'append' => _append(args),
        'append_prepare' => _appendPrepare(args),
        'append_one' => _appendOne(),
        'append_finish' => _appendFinish(),
        'load' => _load(args),
        'shutdown' => Future.value({'outcome': 'shutdown'}),
        _ => Future.value({'outcome': 'unknown_op'}),
      };
      return {'id': id, 'status': 'ok', ...payload};
    } catch (error) {
      return _failure(message['id'] as String? ?? '', error);
    }
  }

  Future<Map<String, Object?>> _hello(Map<String, dynamic> message) async {
    _mutant = message['mutant'] as String? ?? '';
    final conn = await Connection.open(
      Endpoint(
        host: message['host'] as String,
        port: message['port'] as int,
        database: message['dbname'] as String,
        username: message['user'] as String,
        password: message['password'] as String,
      ),
      settings: ConnectionSettings(
        sslMode: SslMode.disable,
        applicationName: message['application_name'] as String,
      ),
    );
    _conn = conn;
    await conn.execute(
      Sql.named("SELECT set_config('search_path', @schema, false)"),
      parameters: {'schema': message['schema']},
    );
    await conn.execute("SET lock_timeout TO '${message['lock_ms']}ms'");
    await conn.execute(
      "SET statement_timeout TO '${message['statement_ms']}ms'",
    );
    await conn.execute(
      "SET idle_in_transaction_session_timeout TO '${message['idle_in_tx_ms']}ms'",
    );
    return {'status': 'ok', 'backend_pid': await _backendPid(), 'pid': pid};
  }

  Future<Map<String, Object?>> _begin() async {
    await _db.execute('BEGIN');
    return {'outcome': 'began', 'backend_pid': await _backendPid()};
  }

  Future<Map<String, Object?>> _commit() async {
    await _db.execute('COMMIT');
    return {'outcome': 'committed'};
  }

  Future<Map<String, Object?>> _rollback() async {
    await _db.execute('ROLLBACK');
    _prepared = const [];
    _inserted = 0;
    return {'outcome': 'rolled_back'};
  }

  Future<Map<String, Object?>> _consume(Map<String, dynamic> args) async {
    final inserted = await _query(SqlConfirmationTokenStore.consumeSql, {
      'tenant_id': args['tenant_id'],
      'token_id': args['token_id'],
      'token_digest': args['digest'],
      'purpose': args['purpose'],
      'expires_at': args['expires_at'],
      'now': args['now'],
    });
    if (inserted.isNotEmpty && inserted.first['token_id'] == args['token_id']) {
      return {'outcome': 'accepted'};
    }
    final existing = await _query(SqlConfirmationTokenStore.selectDigestSql, {
      'tenant_id': args['tenant_id'],
      'token_id': args['token_id'],
    });
    if (existing.isNotEmpty) {
      final digest = existing.first['token_digest']?.toString().toLowerCase();
      if (digest != null &&
          digest != (args['digest'] as String).toLowerCase()) {
        throw ConfirmationFailure.collision();
      }
    }
    throw ConfirmationFailure.spent();
  }

  Future<Map<String, Object?>> _acquire(Map<String, dynamic> args) async {
    final request = _makeRequest(args);
    final now = DateTime.parse(args['now'] as String);
    final seconds = args['lease_seconds'] as int;
    for (var i = 0; i < 3; i++) {
      final acquired = await _attemptAcquire(request, now, seconds);
      if (acquired != null) {
        return acquired;
      }
    }
    throw StateError('Idempotency acquire could not converge.');
  }

  Future<Map<String, Object?>?> _attemptAcquire(
    IdempotencyRequest request,
    DateTime now,
    int seconds,
  ) async {
    final leaseUntil = now.add(Duration(seconds: seconds));
    final token = List<int>.generate(16, (_) => Random.secure().nextInt(256));
    final tokenHex = token
        .map((byte) => byte.toRadixString(16).padLeft(2, '0'))
        .join();
    final inserted = await _query(SqlIdempotencyStore.insertSql, {
      ..._scope(request),
      'fingerprint': request.argumentsFingerprint.value,
      'lease_token': tokenHex,
      'lease_until': leaseUntil.toUtc().toIso8601String(),
      'expires_at': now.add(const Duration(days: 1)).toUtc().toIso8601String(),
    });
    if (inserted.isNotEmpty) {
      _request = request;
      _leaseToken = tokenHex;
      _leaseAttempt = 1;
      return {
        'outcome': 'new',
        'attempt': 1,
        'lease_token_sha256': sha256.convert(utf8.encode(tokenHex)).toString(),
      };
    }
    final selected = await _query(
      SqlIdempotencyStore.selectSql,
      _scope(request),
    );
    if (selected.isEmpty) {
      return null;
    }
    final row = selected.first;
    if (row['fingerprint']?.toString() != request.argumentsFingerprint.value) {
      throw IdempotencyFailure.keyReuse(request.scope.key.value);
    }
    if (row['state']?.toString() == 'completed') {
      return {'outcome': 'duplicate_completed'};
    }
    final existingUntil = DateTime.parse(row['lease_until']!.toString());
    if (existingUntil.isAfter(now)) {
      return {'outcome': 'in_flight'};
    }
    final takeoverHex = List<int>.generate(
      16,
      (_) => Random.secure().nextInt(256),
    ).map((byte) => byte.toRadixString(16).padLeft(2, '0')).join();
    final taken = await _query(SqlIdempotencyStore.takeoverSql, {
      ..._scope(request),
      'lease_token': takeoverHex,
      'lease_until': leaseUntil.toUtc().toIso8601String(),
      'now': now.toUtc().toIso8601String(),
    });
    if (taken.isEmpty) {
      return null;
    }
    _request = request;
    _leaseToken = takeoverHex;
    _leaseAttempt = (taken.first['attempt'] as num).toInt();
    return {
      'outcome': 'takeover',
      'attempt': _leaseAttempt,
      'lease_token_sha256': sha256.convert(utf8.encode(takeoverHex)).toString(),
    };
  }

  Future<Map<String, Object?>> _heartbeat(Map<String, dynamic> args) async {
    final request = _request;
    final token = _leaseToken;
    if (request == null || token == null) {
      throw IdempotencyFailure.lostLease();
    }
    final now = DateTime.parse(args['now'] as String);
    final rows = await _query(SqlIdempotencyStore.heartbeatSql, {
      ..._scope(request),
      'lease_until': now
          .add(const Duration(seconds: 30))
          .toUtc()
          .toIso8601String(),
      'lease_token': token,
    });
    if (rows.isEmpty) {
      throw IdempotencyFailure.lostLease();
    }
    return {'outcome': 'heartbeat'};
  }

  Future<Map<String, Object?>> _finish(
    Map<String, dynamic> args, {
    required bool fail,
  }) async {
    final request = _request;
    final token = _leaseToken;
    if (request == null || token == null) {
      throw IdempotencyFailure.lostLease();
    }
    final digest = StoredResultDigest.fromJcs('{"ok":true}');
    final sql = _mutant == 'unfenced'
        ? '''
UPDATE agentools_idempotency_leases
SET state = 'completed', result_json = @result_json, result_digest = @result_digest,
    lease_token = NULL, lease_until = NULL
WHERE tenant_id = @tenant_id AND tool_name = @tool_name AND idempotency_key = @idempotency_key
  AND state = 'in_progress'
RETURNING tenant_id
'''
        : SqlIdempotencyStore.finishSql;
    final params = {
      ..._scope(request),
      'result_json': jsonEncode({
        'value': {'ok': true},
      }),
      'result_digest': digest.value,
      if (_mutant != 'unfenced') 'lease_token': token,
    };
    final rows = await _query(sql, params);
    if (rows.isEmpty) {
      throw IdempotencyFailure.lostLease();
    }
    return {'outcome': fail ? 'failed' : 'completed'};
  }

  Future<Map<String, Object?>> _append(Map<String, dynamic> args) async {
    final events = _events(args);
    final tenant = args['tenant_id'] as String;
    final action = args['action_id'] as String;
    final expected = args['expected_prev_seq'] as int;
    final hashes = [
      for (final event in events) _contentHash(tenant, action, event),
    ];
    await _db.execute('BEGIN');
    try {
      await _query(SqlEventStore.lockHeaderSql, {
        'tenant_id': tenant,
        'action_id': action,
      });
      final header = await _query(SqlEventStore.selectHeaderSql, {
        'tenant_id': tenant,
        'action_id': action,
      });
      final actual = header.isEmpty
          ? 0
          : (header.first['current_seq'] as num?)?.toInt() ?? 0;
      final existing = <int, Map<String, Object?>>{};
      for (var index = 0; index < events.length; index++) {
        final found = await _query(SqlEventStore.lookupSql, {
          'tenant_id': tenant,
          'event_id': events[index].eventId,
        });
        if (found.isNotEmpty) {
          existing[index] = found.first;
        }
      }
      late final Map<String, Object?> result;
      if (existing.isEmpty) {
        if (expected != actual) {
          throw VersionConflict(expected: expected, actual: actual);
        }
        for (var index = 0; index < events.length; index++) {
          final event = events[index];
          await _query(SqlEventStore.insertEventSql, {
            'tenant_id': tenant,
            'action_id': action,
            'seq': actual + index + 1,
            'event_id': event.eventId,
            'kind': event.kind,
            'event_version': event.eventVersion,
            'spec_rev': event.specRevision,
            'actor_type': event.actor.type,
            'actor_id': event.actor.id,
            'ts': event.timestamp,
            'payload_json': jsonEncode(event.payload),
            'epoch': event.epoch,
            'prev_hash': null,
            'content_hash': hashes[index],
            'expected_prev_seq': actual + index,
          });
        }
        await _query(SqlEventStore.updateHeaderSql, {
          'tenant_id': tenant,
          'action_id': action,
          'current_seq': actual + events.length,
        });
        result = {
          'outcome': 'appended',
          'first_seq': actual + 1,
          'last_seq': actual + events.length,
        };
      } else if (existing.length != events.length) {
        throw EventStoreFailure.partialOverlap();
      } else {
        for (var index = 0; index < events.length; index++) {
          final row = existing[index]!;
          if (row['content_hash'] != hashes[index] ||
              row['action_id'] != action) {
            throw EventIdCollision(events[index].eventId);
          }
        }
        result = {'outcome': 'idempotent_replay'};
      }
      await _db.execute('COMMIT');
      return result;
    } catch (error) {
      await _db.execute('ROLLBACK');
      rethrow;
    }
  }

  Future<Map<String, Object?>> _appendPrepare(Map<String, dynamic> args) async {
    await _db.execute('BEGIN');
    final tenant = args['tenant_id'] as String;
    final action = args['action_id'] as String;
    await _query(SqlEventStore.lockHeaderSql, {
      'tenant_id': tenant,
      'action_id': action,
    });
    final header = await _query(SqlEventStore.selectHeaderSql, {
      'tenant_id': tenant,
      'action_id': action,
    });
    _prepared = _events(args);
    _preparedActual = header.isEmpty
        ? 0
        : (header.first['current_seq'] as num?)?.toInt() ?? 0;
    _preparedTenant = tenant;
    _preparedAction = action;
    _inserted = 0;
    return {
      'outcome': 'locked',
      'actual': _preparedActual,
      'backend_pid': await _backendPid(),
    };
  }

  Future<Map<String, Object?>> _appendOne() async {
    await _insertPrepared(1);
    return {'outcome': 'inserted_one', 'count': _inserted};
  }

  Future<Map<String, Object?>> _appendFinish() async {
    await _insertPrepared(_prepared.length);
    await _db.execute('COMMIT');
    return {'outcome': 'appended', 'count': _inserted};
  }

  Future<void> _insertPrepared(int want) async {
    while (_inserted < want) {
      final index = _inserted;
      final event = _prepared[index];
      await _query(SqlEventStore.insertEventSql, {
        'tenant_id': _preparedTenant,
        'action_id': _preparedAction,
        'seq': _preparedActual + index + 1,
        'event_id': event.eventId,
        'kind': event.kind,
        'event_version': event.eventVersion,
        'spec_rev': event.specRevision,
        'actor_type': event.actor.type,
        'actor_id': event.actor.id,
        'ts': event.timestamp,
        'payload_json': jsonEncode(event.payload),
        'epoch': event.epoch,
        'prev_hash': null,
        'content_hash': _contentHash(_preparedTenant, _preparedAction, event),
        'expected_prev_seq': _preparedActual + index,
      });
      _inserted++;
      if (_mutant == 'non-atomic') {
        await _db.execute('COMMIT');
        await _db.execute('BEGIN');
      }
    }
    await _query(SqlEventStore.updateHeaderSql, {
      'tenant_id': _preparedTenant,
      'action_id': _preparedAction,
      'current_seq': _preparedActual + _inserted,
    });
  }

  Future<Map<String, Object?>> _load(Map<String, dynamic> args) async {
    final limit = args['limit'] as int;
    final rows = await _query(SqlEventStore.loadSql, {
      'tenant_id': args['tenant_id'],
      'action_id': args['action_id'],
      'after_seq': args['after_seq'],
      'limit': limit + 1,
    });
    final hasMore = rows.length > limit;
    final page = rows.take(limit).toList();
    return {
      'outcome': 'page',
      'count': page.length,
      'has_more': hasMore,
      'last_seq': page.isEmpty
          ? args['after_seq'] as int
          : (page.last['seq'] as num).toInt(),
    };
  }

  String _contentHash(
    String tenantId,
    String actionId,
    UncommittedEvent event,
  ) {
    final envelope = <String, Object?>{
      'action_id': actionId,
      'actor': event.actor.toMap(),
      'event_id': event.eventId,
      'event_version': event.eventVersion,
      'kind': event.kind,
      'payload': event.payload,
      'spec_rev': event.specRevision,
      'tenant_id': tenantId,
      'ts': event.timestamp,
      if (event.epoch != null) 'epoch': event.epoch,
    };
    final digest = Canonicalizer().canonicalize(envelope);
    return 'sha-256:${sha256.convert(utf8.encode(digest))}';
  }

  IdempotencyRequest _makeRequest(Map<String, dynamic> args) {
    return IdempotencyRequest(
      scope: IdempotencyScope(
        tenantId: args['tenant_id'] as String,
        toolName: args['tool_name'] as String,
        key: IdempotencyKey(args['key'] as String),
      ),
      argumentsFingerprint: ArgumentsFingerprint.fromJcs(
        args['fingerprint'] as String,
      ),
      actionId: args['action_id'] as String,
    );
  }

  Map<String, Object?> _scope(IdempotencyRequest request) {
    return {
      'tenant_id': request.scope.tenantId,
      'tool_name': request.scope.toolName,
      'idempotency_key': request.scope.key.value,
    };
  }

  List<UncommittedEvent> _events(Map<String, dynamic> args) {
    final raw = (args['events'] as List).cast<Map<String, dynamic>>();
    return [
      for (final item in raw)
        UncommittedEvent(
          eventId: item['event_id'] as String,
          kind: item['kind'] as String,
          eventVersion: item['event_version'] as int,
          specRevision: item['spec_rev'] as String,
          actor: Actor(
            type: item['actor_type'] as String,
            id: item['actor_id'] as String,
          ),
          timestamp: item['timestamp'] as String,
          payload: Map<String, dynamic>.from(item['payload'] as Map),
        ),
    ];
  }

  Map<String, Object?> _failure(String id, Object error) {
    var outcome = 'error';
    String? sqlstate;
    String? code;
    if (error is ConfirmationFailure) {
      code = error.error.code;
      outcome = code.contains('collision') ? 'collision' : 'spent';
    } else if (error is IdempotencyFailure) {
      code = error.error.code;
      outcome = code.contains('lost_lease')
          ? 'lost_lease'
          : (code.contains('in_flight') ? 'in_flight' : 'idempotency_error');
    } else if (error is VersionConflict) {
      code = error.error.code;
      outcome = 'version_conflict';
    } else if (error is EventIdCollision) {
      code = error.error.code;
      outcome = 'collision';
    } else if (error is EventStoreFailure) {
      code = error.error.code;
      outcome = code.contains('partial') ? 'partial_overlap' : 'event_error';
    } else if (error is ServerException) {
      sqlstate = error.code;
      if (sqlstate == '57014') {
        outcome = 'query_cancel';
      } else if (sqlstate == '57P01' ||
          sqlstate == '57P02' ||
          sqlstate == '57P03') {
        outcome = 'backend_terminate';
      }
    }
    return {
      'id': id,
      'status': 'error',
      'outcome': outcome,
      'sqlstate': sqlstate,
      'code': code,
      'fault': (outcome == 'query_cancel' || outcome == 'backend_terminate')
          ? outcome
          : null,
    };
  }

  Future<int> _backendPid() async {
    final rows = await _query('SELECT pg_backend_pid() AS pid', const {});
    return (rows.first['pid'] as num).toInt();
  }

  Future<List<Map<String, Object?>>> _query(
    String sql,
    Map<String, Object?> params,
  ) async {
    final result = params.isEmpty
        ? await _db.execute(sql)
        : await _db.execute(Sql.named(sql), parameters: params);
    return [
      for (final row in result) Map<String, Object?>.from(row.toColumnMap()),
    ];
  }

  Connection get _db {
    final conn = _conn;
    if (conn == null) {
      throw StateError('not connected');
    }
    return conn;
  }
}

import 'dart:math';
import 'dart:typed_data';

import 'idempotency_request.dart';

/// Opaque fencing handle. Never log the token.
final class IdempotencyLease {
  IdempotencyLease({
    required this.request,
    required this.token,
    required this.attempt,
    required this.leaseUntil,
  }) {
    if (token.isEmpty || attempt < 1) {
      throw ArgumentError(
        'Lease token must be nonempty and attempt must be >= 1.',
      );
    }
  }

  final IdempotencyRequest request;
  final String token;
  final int attempt;
  final DateTime leaseUntil;

  static IdempotencyLease issue(
    IdempotencyRequest request,
    DateTime leaseUntil, {
    int attempt = 1,
  }) {
    return IdempotencyLease(
      request: request,
      token: _token(),
      attempt: attempt,
      leaseUntil: leaseUntil,
    );
  }

  static String _token() {
    final random = Random.secure();
    final bytes = Uint8List.fromList(
      List<int>.generate(16, (_) => random.nextInt(256)),
    );
    return bytes.map((b) => b.toRadixString(16).padLeft(2, '0')).join();
  }
}

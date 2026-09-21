import '../tool/tool_execution_error.dart';
import '../tool/tool_execution_exception.dart';
import 'idempotency_error_codes.dart';

final class IdempotencyFailure extends ToolExecutionException {
  IdempotencyFailure(super.error);

  factory IdempotencyFailure.inFlight(String key) {
    return IdempotencyFailure(
      ToolExecutionError(
        code: IdempotencyErrorCodes.inFlight,
        category: 'conflict',
        retryable: true,
        title: 'Work for this idempotency key is already in flight.',
        context: {'idempotency_key': key},
      ),
    );
  }

  factory IdempotencyFailure.keyReuse(String key) {
    return IdempotencyFailure(
      ToolExecutionError(
        code: IdempotencyErrorCodes.keyReuse,
        category: 'validation',
        retryable: false,
        title: 'The idempotency key was reused with different arguments.',
        context: {'idempotency_key': key},
      ),
    );
  }

  factory IdempotencyFailure.lostLease() {
    return IdempotencyFailure(
      ToolExecutionError(
        code: IdempotencyErrorCodes.lostLease,
        category: 'conflict',
        retryable: false,
        title: 'The idempotency lease is no longer held.',
      ),
    );
  }

  factory IdempotencyFailure.ambiguousEffect() {
    return IdempotencyFailure(
      ToolExecutionError(
        code: IdempotencyErrorCodes.ambiguousEffect,
        category: 'precondition',
        retryable: false,
        title: 'The previous attempt left an ambiguous external effect.',
      ),
    );
  }

  factory IdempotencyFailure.capabilityUnknown(String tool) {
    return IdempotencyFailure(
      ToolExecutionError(
        code: IdempotencyErrorCodes.capabilityUnknown,
        category: 'precondition',
        retryable: false,
        title: 'High-risk tools require a known idempotency capability.',
        context: {'tool': tool},
      ),
    );
  }
}

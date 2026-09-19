import '../tool/tool_execution_exception.dart';

final class IdempotencyFailure extends ToolExecutionException {
  IdempotencyFailure(super.error);
}

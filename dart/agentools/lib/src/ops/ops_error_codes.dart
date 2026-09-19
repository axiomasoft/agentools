/// Long-operation, audit, and security tool error codes from §E1.
abstract final class OpsErrorCodes {
  static const foreignAccessToken =
      'agentools.permission.foreign_access_token';
  static const taskInputRequiredWhileApplying =
      'agentools.precondition.task_input_required_while_applying';
  static const baggageLimitExceeded =
      'agentools.budget.baggage_limit_exceeded';
}

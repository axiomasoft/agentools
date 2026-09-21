import 'descriptor_digest.dart';
import 'tool_error_codes.dart' show ToolProfileErrorCodes;
import 'tool_execution_error.dart';
import 'tool_execution_exception.dart';

/// A descriptor changed after an action was prepared and before it was applied.
final class DescriptorDrift extends ToolExecutionException {
  DescriptorDrift({
    required DescriptorDigest expected,
    required DescriptorDigest actual,
  }) : super(
         ToolExecutionError(
           code: ToolProfileErrorCodes.descriptorDrift,
           category: 'precondition',
           retryable: false,
           title: 'Дескриптор инструмента изменился после подготовки действия',
           detail:
               'Подготовленное предложение больше не описывает применяемый инструмент.',
           context: {
             'expected_digest': expected.value,
             'actual_digest': actual.value,
           },
         ),
       );

  DescriptorDigest get expectedDigest =>
      DescriptorDigest.fromValue(error.context!['expected_digest'] as String);

  DescriptorDigest get actualDigest =>
      DescriptorDigest.fromValue(error.context!['actual_digest'] as String);
}

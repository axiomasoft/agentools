import 'negotiation_mode.dart';
import 'tool_error.dart';

/// Результат negotiation возможностей расширения.
final class NegotiationResult {
  NegotiationResult(this.mode, {this.error}) {
    if ((mode == NegotiationMode.rejected) != (error != null)) {
      throw ArgumentError(
        'Only a rejected negotiation carries a tool error.',
      );
    }
  }

  final NegotiationMode mode;
  final NegotiationToolError? error;
}

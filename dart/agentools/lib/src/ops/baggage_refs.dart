import '../errors/tool_execution_error.dart';
import '../errors/tool_execution_result.dart';
import 'ops_error_codes.dart';

/// Opaque baggage identifiers only; substantive data is forbidden (AOB-29..AOB-31).
final class BaggageRefs {
  static const _maxItems = 64;
  static const _maxBytes = 8192;

  BaggageRefs([List<String>? refs]) : _refs = List.unmodifiable(refs ?? const []);

  final List<String> _refs;

  BaggageRefs add(String opaqueId) {
    if (opaqueId.isEmpty || !_isOpaque(opaqueId)) {
      throw ArgumentError('Baggage may contain only opaque identifiers.');
    }

    final next = [..._refs, opaqueId];
    final encoded = next.join(',');
    if (next.length > _maxItems || encoded.length > _maxBytes) {
      throw BaggageLimitException(
        ToolExecutionResult.failure(
          ToolExecutionError(
            code: OpsErrorCodes.baggageLimitExceeded,
            category: 'budget',
            retryable: false,
            title: 'Baggage limits were exceeded.',
          ),
        ),
      );
    }

    return BaggageRefs(next);
  }

  List<String> refs() => _refs;

  bool _isOpaque(String value) {
    return RegExp(r'^[a-zA-Z0-9._:-]+$').hasMatch(value);
  }
}

final class BaggageLimitException implements Exception {
  BaggageLimitException(this.result);

  final ToolExecutionResult result;

  @override
  String toString() => 'Baggage limit exceeded.';
}

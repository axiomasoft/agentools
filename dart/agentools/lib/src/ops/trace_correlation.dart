/// Trace correlation stored in journal payload, not as a tracer dependency (AOB-17..AOB-22).
final class TraceCorrelation {
  TraceCorrelation({
    required this.traceId,
    this.spanId,
    this.linkedTraceId,
    this.linkedSpanId,
  }) {
    if (traceId.isEmpty) {
      throw ArgumentError('Trace id is required.');
    }
  }

  final String traceId;
  final String? spanId;
  final String? linkedTraceId;
  final String? linkedSpanId;

  /// AOB-20; close the prepare span when confirmation is requested.
  Map<String, dynamic> confirmationRequestedPayload() {
    return {'trace': _toTraceBlock()};
  }

  /// AOB-21; resume on commit with a span link, not by extending the prepare span.
  Map<String, dynamic> commitResumePayload() {
    final trace = Map<String, dynamic>.from(_toTraceBlock());
    if (linkedTraceId != null) {
      trace['link'] = <String, String>{
        'trace_id': ?linkedTraceId,
        'span_id': ?linkedSpanId,
      };
    }
    return {'trace': trace};
  }

  /// AOB-19; trace ids must not become action or idempotency identifiers.
  bool mustNotBeActionIdentifier() => true;

  Map<String, String> _toTraceBlock() {
    return {'trace_id': traceId, 'span_id': ?spanId};
  }
}

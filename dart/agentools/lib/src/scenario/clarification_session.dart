/// CLR-6–CLR-15: a single MRTR binding with opaque state and round snapshots.
final class ClarificationSession {
  final List<Map<String, dynamic>> _rounds = [];
  Map<String, dynamic>? _requests;
  String? _requestState;
  String? _requestCallId;

  void request(
    String callId,
    String requestState,
    Map<String, dynamic> requests,
  ) {
    if (callId.isEmpty || requestState.isEmpty || requests.isEmpty) {
      throw ArgumentError(
        'An MRTR request needs call id, opaque state, and requests.',
      );
    }
    _requestCallId = callId;
    _requestState = requestState;
    _requests = Map<String, dynamic>.from(requests);
    _rounds.add({
      'round': _rounds.length + 1,
      'direction': 'request',
      'call_id': callId,
      'request_state': requestState,
      'input_requests': requests,
    });
  }

  Map<String, dynamic> answer(String callId, Map<String, dynamic> answers) {
    if (_requests == null || _requestState == null || _requestCallId == null) {
      throw StateError('No pending clarification request exists.');
    }
    if (callId.isEmpty || callId == _requestCallId) {
      throw ArgumentError('An MRTR retry must use a distinct call id.');
    }
    for (final key in answers.keys) {
      if (!_requests!.containsKey(key)) {
        throw ArgumentError(
          'agentools.validation.unknown_result_type: response key was not requested.',
        );
      }
    }
    final record = {
      'round': _rounds.length,
      'direction': 'response',
      'call_id': callId,
      'request_state': _requestState,
      'input_responses': answers,
    };
    _rounds.add(record);
    _requests = null;
    return record;
  }

  List<Map<String, dynamic>> rounds() =>
      List<Map<String, dynamic>>.unmodifiable(_rounds);

  bool get hasPendingRequest => _requests != null;
}

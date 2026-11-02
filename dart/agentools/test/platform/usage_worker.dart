import 'dart:convert';
import 'dart:io';
import 'usage_contract.dart';

void main() {
  final cases = <Map<String, Object?>>[];
  final observations = <String, Object?>{};
  var failures = 0;
  for (final id in portableIds()) {
    try {
      observations[id] = portableCase(id);
      cases.add({'id': id, 'status': 'passed'});
    } catch (error) {
      failures++;
      cases.add({'id': id, 'status': 'failed'});
      stderr.writeln('$id: $error');
    }
  }
  stdout.writeln(
    jsonEncode({
      'mode': 'contract-scripted',
      'tier': 'contract-scripted',
      'cases': cases,
      'observations': observations,
      'count': cases.length,
      'failures': failures,
      'skips': 0,
    }),
  );
  exitCode = failures == 0 && cases.isNotEmpty ? 0 : 1;
}

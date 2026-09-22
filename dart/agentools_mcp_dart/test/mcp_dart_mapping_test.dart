import 'package:agentools/agentools.dart';
import 'package:agentools_mcp_dart/src/mcp_dart_mapping.dart';
import 'package:mcp_dart/mcp_dart.dart';
import 'package:test/test.dart';

void main() {
  test('input_required permits an omitted request state', () {
    final wire = InputRequiredResult.fromJson({
      'resultType': 'input_required',
      'inputRequests': {
        'confirm': {'method': 'roots/list', 'params': <String, dynamic>{}},
      },
    });

    final mapped = mapToolCallResult(wire);

    expect(mapped.kind, McpToolOutcomeKind.inputRequired);
    expect(mapped.inputRequests, isNotEmpty);
    expect(mapped.requestState, isNull);
  });
}

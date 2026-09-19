import 'dart:convert';
import 'dart:io';

import 'package:agentools/agentools.dart';
import 'package:agentools/src/schema/tool_descriptor.dart' as schema_dto;
import 'package:test/test.dart';

void main() {
  const schemaDirectory = '../../spec/revisions/2026-08/schema';

  Map<String, Set<String>> loadSchemaProperties(String fileName) {
    final file = File('$schemaDirectory/$fileName');
    final schema = jsonDecode(file.readAsStringSync()) as Map<String, dynamic>;
    final properties = schema['properties'] as Map<String, dynamic>;
    return {fileName: properties.keys.toSet()};
  }

  test('DTO field sets match published schema properties', () {
    final toolDescriptorProps =
        loadSchemaProperties('tool-descriptor.json')['tool-descriptor.json']!;
    final errorProps = loadSchemaProperties('error.json')['error.json']!;

    expect(schema_dto.ToolDescriptor.schemaProperties, toolDescriptorProps);
    expect(ToolError.schemaProperties, errorProps);

    // Printed for Completion Notes evidence.
    // ignore: avoid_print
    print('ToolDescriptor OK');
    // ignore: avoid_print
    print('ToolError OK');
  });
}

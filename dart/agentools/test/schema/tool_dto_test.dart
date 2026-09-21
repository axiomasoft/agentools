// ignore_for_file: implementation_imports

import 'package:agentools/agentools.dart';
import 'package:agentools/src/schema/tool_descriptor.dart' as schema_dto;
import 'package:test/test.dart';

void main() {
  const schemaDirectory = '../../spec/revisions/2026-08/schema';
  late PublishedSchemaValidator validator;

  setUp(() {
    validator = PublishedSchemaValidator(schemaDirectory);
  });

  test('ToolDescriptor round-trips fixture with unknown member', () {
    const withUnknown = '''
{
  "name": "crm_task_update",
  "description": "Purpose: Отправляет платёж.\\nCall when: Подтверждённый платёж готов.\\nRequired arguments: amount_minor\\nExample: fina_payment_send({\\"amount_minor\\":12500})",
  "inputSchema": {"type": "object"},
  "_meta": {"dev.agentools/action": {"side_effect": "write"}},
  "future_extension": true
}''';
    final schema = validator.schema(schema_dto.ToolDescriptor.schemaId);
    final descriptor = schema_dto.ToolDescriptor.fromJsonText(
      withUnknown,
      schema,
    );
    expect(descriptor.toJsonText(), withUnknown);
  });

  test('ToolError parses a valid published error object', () {
    final schema = validator.schema(ToolError.schemaId);
    final error = ToolError.fromJson({
      'code': 'agentools.validation.idempotency_key_missing',
      'category': 'validation',
      'retryable': false,
      'title': 'Ключ не передан',
    }, schema);
    expect(error.code, 'agentools.validation.idempotency_key_missing');
    expect(error.category, 'validation');
  });
}

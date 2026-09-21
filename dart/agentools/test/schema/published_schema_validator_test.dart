// ignore_for_file: implementation_imports

import 'package:agentools/agentools.dart';
import 'package:agentools/src/schema/tool_descriptor.dart' as schema_dto;
import 'package:json_schema/json_schema.dart';
import 'package:test/test.dart';

void main() {
  const schemaDirectory = '../../spec/revisions/2026-08/schema';

  group('PublishedSchemaValidator', () {
    late PublishedSchemaValidator validator;

    setUp(() {
      validator = PublishedSchemaValidator(schemaDirectory);
    });

    test('validates a published idempotency document offline', () {
      validator.assertValid({
        'key': 'at1:Abcdefgh',
        'state': 'duplicate_completed',
        'args_fingerprint': 'sha-256:${'a' * 64}',
        'stored_error': {
          'code': 'agentools.validation.idempotency_key_missing',
          'category': 'validation',
          'retryable': false,
          'title': 'Ключ не передан',
        },
        'created_at': '2026-08-03T09:15:01.118Z',
        'expires_at': '2026-08-10T09:15:01.118Z',
      }, 'https://agentools.dev/schema/2026-08/idempotency.json');
    });

    test('reports path and rule for invalid published data', () {
      try {
        validator.assertValid({
          'name': 'BadName',
          'description':
              'Purpose: demo\nCall when: demo\nRequired arguments: none\nExample: demo()',
          'inputSchema': {'type': 'object'},
          '_meta': {
            'dev.agentools/action': {'side_effect': 'write'},
          },
        }, schema_dto.ToolDescriptor.schemaId);
        fail('Invalid data was accepted.');
      } on SchemaValidationException catch (error) {
        expect(error.path, ['name']);
        expect(error.rule, 'pattern');
      }
    });

    test('uses draft 2020-12 via prefixItems', () {
      final schema = JsonSchema.create({
        '\$schema': 'https://json-schema.org/draft/2020-12/schema',
        'type': 'array',
        'prefixItems': [
          {'type': 'string'},
          {'type': 'integer'},
        ],
      }, schemaVersion: SchemaVersion.draft2020_12);

      expect(schema.validate(['ok', 1]).isValid, isTrue);
      expect(schema.validate(['ok', 'nope']).isValid, isFalse);
    });
  });
}

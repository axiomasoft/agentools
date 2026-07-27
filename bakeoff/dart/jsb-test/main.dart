// Смоук-тест json_schema_builder как ВАЛИДАТОРА (не генератора — экспорты пакета
// codegen не несут, P2.3 Scope п.(а)). Прогон: схема пробы -> validate(valid.json)
// -> validate(unknown-key.json), фиксируем ошибки/успех по наблюдаемому выводу.
import 'dart:convert';
import 'dart:io';
import 'package:json_schema_builder/json_schema_builder.dart';

Future<void> main(List<String> args) async {
  final probe = args[0];
  final probeDir = args[1];
  final schemaMap = jsonDecode(await File('$probeDir/schema.json').readAsString());
  Object? extra;
  if (await File('$probeDir/common.json').exists()) {
    extra = jsonDecode(await File('$probeDir/common.json').readAsString());
  }
  final schema = Schema.fromMap(schemaMap as Map<String, Object?>);
  final registry = SchemaRegistry();
  registry.addSchema(Uri.parse(schemaMap['\$id'] as String), schema);
  if (extra != null) {
    final extraMap = extra as Map<String, Object?>;
    registry.addSchema(Uri.parse(extraMap['\$id'] as String), Schema.fromMap(extraMap));
  }

  final valid = jsonDecode(await File('$probeDir/valid.json').readAsString());
  final unknown = jsonDecode(await File('$probeDir/unknown-key.json').readAsString());

  final validErrors = await schema.validate(valid, schemaRegistry: registry);
  print('$probe valid.json errors: ${validErrors.length}');
  for (final e in validErrors) {
    print('  - $e');
  }

  final unknownErrors = await schema.validate(unknown, schemaRegistry: registry);
  print('$probe unknown-key.json errors: ${unknownErrors.length}');
  for (final e in unknownErrors) {
    print('  - $e');
  }
  registry.dispose();
}

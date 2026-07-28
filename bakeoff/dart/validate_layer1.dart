// Прогон пинованного валидатора слоя 1 CI (json_schema 5.2.2, D15) по паре schema/document —
// P4.6, T-2. Вывод — наблюдаемый факт энфорсмента ключевого слова, не документация пакета.
// Использование: dart run validate_layer1.dart <schema.json> <document.json>
import 'dart:convert';
import 'dart:io';
import 'package:json_schema/json_schema.dart';

Future<void> main(List<String> args) async {
  final schemaFile = args[0];
  final dataFile = args[1];
  final schemaMap = jsonDecode(await File(schemaFile).readAsString());
  final data = jsonDecode(await File(dataFile).readAsString());

  final schema = JsonSchema.create(schemaMap);
  final result = schema.validate(data);

  if (result.isValid) {
    print('VALID');
    exit(0);
  }

  print('INVALID');
  for (final e in result.errors) {
    print('  - ${e.schemaPath}: ${e.message}');
  }
  exit(1);
}

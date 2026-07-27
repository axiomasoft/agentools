import 'dart:convert';
import 'dart:io';
import 'package:json_schema/json_schema.dart';
import '../manual/p01_open_enum.dart';
import '../manual/p06_if_then_else.dart';
import '../manual/p08_nested_depth.dart';

JsonSchema loadSchema(String probe) => JsonSchema.create(
    jsonDecode(File('../probes/$probe/schema.json').readAsStringSync()));

void main() {
  final s1 = loadSchema('p01-open-enum');
  final v1 = jsonDecode(File('../probes/p01-open-enum/valid.json').readAsStringSync());
  final o1 = P01OpenEnum.fromJson(v1, s1);
  print('p01 OK: ${o1.toJson()}');
  try {
    P01OpenEnum.fromJson({'kind': 'NOT VALID'}, s1);
    print('p01 FAIL: should have thrown');
  } on FormatException catch (e) {
    print('p01 negative-OK: $e');
  }

  final s6 = loadSchema('p06-if-then-else');
  final v6 = jsonDecode(File('../probes/p06-if-then-else/valid.json').readAsStringSync());
  final o6 = P06IfThenElse.fromJson(v6, s6);
  print('p06 OK: ${o6.toJson()}');
  try {
    P06IfThenElse.fromJson({'kind': 'llm'}, s6);
    print('p06 FAIL: should have thrown (missing prompt_ref)');
  } on FormatException catch (e) {
    print('p06 negative-OK (if/then): $e');
  }
  try {
    P06IfThenElse.fromJson({'kind': 'tool', 'tool': 't1', 'side_effect': 'write'}, s6);
    print('p06 FAIL: should have thrown (missing approval, dependentSchemas)');
  } on FormatException catch (e) {
    print('p06 negative-OK (dependentSchemas): $e');
  }

  final s8 = loadSchema('p08-nested-depth');
  final v8 = jsonDecode(File('../probes/p08-nested-depth/valid.json').readAsStringSync());
  final o8 = P08NestedDepth.fromJson(v8, s8);
  print('p08 OK: ${o8.toJson()}');
}

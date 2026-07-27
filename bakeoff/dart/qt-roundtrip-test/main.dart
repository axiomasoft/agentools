import 'dart:io';
import '../generated/quicktype/p01-open-enum.dart' as p01;
import '../generated/quicktype/p10-numbers.dart' as p10;
import '../generated/quicktype/p08-nested-depth.dart' as p08;

void main() {
  final u1 = File('../probes/p01-open-enum/unknown-key.json').readAsStringSync();
  final o1 = p01.bakeoffp01OpenEnumFromJson(u1);
  print('p01 IN: $u1');
  print('p01 OUT: ${p01.bakeoffp01OpenEnumToJson(o1)}');

  final v10 = File('../probes/p10-numbers/valid.json').readAsStringSync();
  final o10 = p10.bakeoffp10NumbersFromJson(v10);
  print('p10 amount_minor: ${o10.amountMinor} (${o10.amountMinor.runtimeType})');
  print('p10 big_integer: ${o10.bigInteger} (${o10.bigInteger.runtimeType})');
  print('p10 scientific: ${o10.scientific} (${o10.scientific.runtimeType})');
  print('p10 OUT: ${p10.bakeoffp10NumbersToJson(o10)}');

  final v8 = File('../probes/p08-nested-depth/valid.json').readAsStringSync();
  final o8 = p08.bakeoffp08NestedDepthFromJson(v8);
  print('p08 OUT: ${p08.bakeoffp08NestedDepthToJson(o8)}');
}

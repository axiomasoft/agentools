// Ручной DTO (контрольная точка D32, симметрично P2.2). Валидация схемы делегирована
// пинованному json_schema 5.2.2 (D15) — вызывающий код грузит Schema и передаёт сюда.
import 'package:json_schema/json_schema.dart';

class P01OpenEnum {
  final String kind;
  P01OpenEnum(this.kind);

  factory P01OpenEnum.fromJson(Map<String, dynamic> json, JsonSchema schema) {
    final result = schema.validate(json);
    if (!result.isValid) {
      throw FormatException(result.errors.map((e) => e.message).join('; '));
    }
    return P01OpenEnum(json['kind'] as String);
  }

  Map<String, dynamic> toJson() => {'kind': kind};
}

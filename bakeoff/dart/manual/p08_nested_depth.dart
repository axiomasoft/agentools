// Ручной DTO (D32). Валидация (включая patternProperties на l6) — пинованный json_schema 5.2.2.
import 'package:json_schema/json_schema.dart';

class P08NestedDepth {
  final Map<String, String> l6Attrs;
  P08NestedDepth(this.l6Attrs);

  factory P08NestedDepth.fromJson(Map<String, dynamic> json, JsonSchema schema) {
    final result = schema.validate(json);
    if (!result.isValid) {
      throw FormatException(result.errors.map((e) => e.message).join('; '));
    }
    final l6 = json['l1']['l2']['l3']['l4']['l5']['l6'] as Map<String, dynamic>;
    return P08NestedDepth(l6.map((k, v) => MapEntry(k, v as String)));
  }

  Map<String, dynamic> toJson() => {
        'l1': {
          'l2': {
            'l3': {
              'l4': {
                'l5': {'l6': l6Attrs}
              }
            }
          }
        }
      };
}

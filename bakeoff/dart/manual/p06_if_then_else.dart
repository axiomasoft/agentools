// Ручной DTO (D32). Валидация — пинованный json_schema 5.2.2, включая if/then/else и
// dependentSchemas: обе конструкции реализованы библиотекой, ручной код их не дублирует.
import 'package:json_schema/json_schema.dart';

class P06IfThenElse {
  final String kind;
  final String? promptRef;
  final String? tool;
  final String? sideEffect;
  final String? approval;

  P06IfThenElse({
    required this.kind,
    this.promptRef,
    this.tool,
    this.sideEffect,
    this.approval,
  });

  factory P06IfThenElse.fromJson(Map<String, dynamic> json, JsonSchema schema) {
    final result = schema.validate(json);
    if (!result.isValid) {
      throw FormatException(result.errors.map((e) => e.message).join('; '));
    }
    return P06IfThenElse(
      kind: json['kind'] as String,
      promptRef: json['prompt_ref'] as String?,
      tool: json['tool'] as String?,
      sideEffect: json['side_effect'] as String?,
      approval: json['approval'] as String?,
    );
  }

  Map<String, dynamic> toJson() => {
        'kind': kind,
        if (promptRef != null) 'prompt_ref': promptRef,
        if (tool != null) 'tool': tool,
        if (sideEffect != null) 'side_effect': sideEffect,
        if (approval != null) 'approval': approval,
      };
}

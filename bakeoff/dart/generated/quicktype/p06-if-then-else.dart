// To parse this JSON data, do
//
//     final bakeoffp06IfThenElse = bakeoffp06IfThenElseFromJson(jsonString);

import 'dart:convert';

Bakeoffp06IfThenElse bakeoffp06IfThenElseFromJson(String str) => Bakeoffp06IfThenElse.fromJson(json.decode(str));

String bakeoffp06IfThenElseToJson(Bakeoffp06IfThenElse data) => json.encode(data.toJson());


///Условные поля профиля инструмента: тип шага диктует обязательные поля (образец —
///Step.allOf в scenario.json), плюс dependentSchemas для approval, зависимого от
///side_effect.
class Bakeoffp06IfThenElse {
    final Approval? approval;
    final Kind kind;
    final String? promptRef;
    final SideEffect? sideEffect;
    final String? tool;

    Bakeoffp06IfThenElse({
        this.approval,
        required this.kind,
        this.promptRef,
        this.sideEffect,
        this.tool,
    });

    factory Bakeoffp06IfThenElse.fromJson(Map<String, dynamic> json) => Bakeoffp06IfThenElse(
        approval: approvalValues.map[json["approval"]],
        kind: kindValues.map[json["kind"]]!,
        promptRef: json["prompt_ref"],
        sideEffect: sideEffectValues.map[json["side_effect"]],
        tool: json["tool"],
    );

    Map<String, dynamic> toJson() => {
        "approval": approvalValues.reverse[approval],
        "kind": kindValues.reverse[kind],
        "prompt_ref": promptRef,
        "side_effect": sideEffectValues.reverse[sideEffect],
        "tool": tool,
    };
}

enum Approval {
    CONFIRM,
    NONE
}

final approvalValues = EnumValues({
    "confirm": Approval.CONFIRM,
    "none": Approval.NONE
});

enum Kind {
    LLM,
    TOOL
}

final kindValues = EnumValues({
    "llm": Kind.LLM,
    "tool": Kind.TOOL
});

enum SideEffect {
    READ,
    WRITE
}

final sideEffectValues = EnumValues({
    "read": SideEffect.READ,
    "write": SideEffect.WRITE
});

class EnumValues<T> {
    Map<String, T> map;
    late Map<T, String> reverseMap;

    EnumValues(this.map);

    Map<T, String> get reverse {
            reverseMap = map.map((k, v) => MapEntry(v, k));
            return reverseMap;
    }
}

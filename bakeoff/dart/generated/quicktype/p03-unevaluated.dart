// To parse this JSON data, do
//
//     final bakeoffp03Unevaluated = bakeoffp03UnevaluatedFromJson(jsonString);

import 'dart:convert';

Bakeoffp03Unevaluated bakeoffp03UnevaluatedFromJson(String str) => Bakeoffp03Unevaluated.fromJson(json.decode(str));

String bakeoffp03UnevaluatedToJson(Bakeoffp03Unevaluated data) => json.encode(data.toJson());


///Закрытая схема авторинга (J9): unevaluatedProperties:false на внешнем уровне
///allOf-композиции. Опубликованные схемы этот ключ не несут (J9); проба проверяет,
///переживает ли генератор его в схеме авторинга.
class Bakeoffp03Unevaluated {
    final String id;
    final String? note;

    Bakeoffp03Unevaluated({
        required this.id,
        this.note,
    });

    factory Bakeoffp03Unevaluated.fromJson(Map<String, dynamic> json) => Bakeoffp03Unevaluated(
        id: json["id"],
        note: json["note"],
    );

    Map<String, dynamic> toJson() => {
        "id": id,
        "note": note,
    };
}

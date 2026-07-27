// To parse this JSON data, do
//
//     final bakeoffp01OpenEnum = bakeoffp01OpenEnumFromJson(jsonString);

import 'dart:convert';

Bakeoffp01OpenEnum bakeoffp01OpenEnumFromJson(String str) => Bakeoffp01OpenEnum.fromJson(json.decode(str));

String bakeoffp01OpenEnumToJson(Bakeoffp01OpenEnum data) => json.encode(data.toJson());


///Приём открытых перечислений AgenTools (E2): oneOf из const-веток известных значений плюс
///fallback string с шаблоном расширения.
class Bakeoffp01OpenEnum {
    
    ///Известные значения плюс fallback-ветка вида '<product>.<ext>'.
    final String kind;

    Bakeoffp01OpenEnum({
        required this.kind,
    });

    factory Bakeoffp01OpenEnum.fromJson(Map<String, dynamic> json) => Bakeoffp01OpenEnum(
        kind: json["kind"],
    );

    Map<String, dynamic> toJson() => {
        "kind": kind,
    };
}

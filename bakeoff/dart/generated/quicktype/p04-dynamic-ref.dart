// To parse this JSON data, do
//
//     final bakeoffp04DynamicRef = bakeoffp04DynamicRefFromJson(jsonString);

import 'dart:convert';

Bakeoffp04DynamicRef bakeoffp04DynamicRefFromJson(String str) => Bakeoffp04DynamicRef.fromJson(json.decode(str));

String bakeoffp04DynamicRefToJson(Bakeoffp04DynamicRef data) => json.encode(data.toJson());


///Экзотика 2020-12 (J6): расширяемый базовый список шагов через $dynamicRef/$dynamicAnchor.
///Ожидаем провал (crash/not-supported), нужен наблюдаемый факт, а не догадка.
class Bakeoffp04DynamicRef {
    final List<dynamic> children;
    final String id;

    Bakeoffp04DynamicRef({
        required this.children,
        required this.id,
    });

    factory Bakeoffp04DynamicRef.fromJson(Map<String, dynamic> json) => Bakeoffp04DynamicRef(
        children: List<dynamic>.from(json["children"].map((x) => x)),
        id: json["id"],
    );

    Map<String, dynamic> toJson() => {
        "children": List<dynamic>.from(children.map((x) => x)),
        "id": id,
    };
}

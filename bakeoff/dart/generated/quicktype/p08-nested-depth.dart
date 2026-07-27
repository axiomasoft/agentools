// To parse this JSON data, do
//
//     final bakeoffp08NestedDepth = bakeoffp08NestedDepthFromJson(jsonString);

import 'dart:convert';

Bakeoffp08NestedDepth bakeoffp08NestedDepthFromJson(String str) => Bakeoffp08NestedDepth.fromJson(json.decode(str));

String bakeoffp08NestedDepthToJson(Bakeoffp08NestedDepth data) => json.encode(data.toJson());


///Лимиты SEP-2106 (DoS-пробы, E5): глубокая вложенность объектов (6 уровней) плюс
///patternProperties на листовом уровне — проверяем, что генератор не падает на глубине и не
///игнорирует шаблонные ключи.
class Bakeoffp08NestedDepth {
    final L1 l1;

    Bakeoffp08NestedDepth({
        required this.l1,
    });

    factory Bakeoffp08NestedDepth.fromJson(Map<String, dynamic> json) => Bakeoffp08NestedDepth(
        l1: L1.fromJson(json["l1"]),
    );

    Map<String, dynamic> toJson() => {
        "l1": l1.toJson(),
    };
}

class L1 {
    final L2 l2;

    L1({
        required this.l2,
    });

    factory L1.fromJson(Map<String, dynamic> json) => L1(
        l2: L2.fromJson(json["l2"]),
    );

    Map<String, dynamic> toJson() => {
        "l2": l2.toJson(),
    };
}

class L2 {
    final L3 l3;

    L2({
        required this.l3,
    });

    factory L2.fromJson(Map<String, dynamic> json) => L2(
        l3: L3.fromJson(json["l3"]),
    );

    Map<String, dynamic> toJson() => {
        "l3": l3.toJson(),
    };
}

class L3 {
    final L4 l4;

    L3({
        required this.l4,
    });

    factory L3.fromJson(Map<String, dynamic> json) => L3(
        l4: L4.fromJson(json["l4"]),
    );

    Map<String, dynamic> toJson() => {
        "l4": l4.toJson(),
    };
}

class L4 {
    final L5 l5;

    L4({
        required this.l5,
    });

    factory L4.fromJson(Map<String, dynamic> json) => L4(
        l5: L5.fromJson(json["l5"]),
    );

    Map<String, dynamic> toJson() => {
        "l5": l5.toJson(),
    };
}

class L5 {
    final L6 l6;

    L5({
        required this.l6,
    });

    factory L5.fromJson(Map<String, dynamic> json) => L5(
        l6: L6.fromJson(json["l6"]),
    );

    Map<String, dynamic> toJson() => {
        "l6": l6.toJson(),
    };
}

class L6 {
    L6();

    factory L6.fromJson(Map<String, dynamic> json) => L6(
    );

    Map<String, dynamic> toJson() => {
    };
}

// To parse this JSON data, do
//
//     final bakeoffp07PrefixItems = bakeoffp07PrefixItemsFromJson(jsonString);

import 'dart:convert';

Bakeoffp07PrefixItems bakeoffp07PrefixItemsFromJson(String str) => Bakeoffp07PrefixItems.fromJson(json.decode(str));

String bakeoffp07PrefixItemsToJson(Bakeoffp07PrefixItems data) => json.encode(data.toJson());


///Кортеж фиксированной длины (пример: пара хешей 'до/после' side-эффекта) через prefixItems
///2020-12 (заменяет tuple-форму items в draft-07), закрытый items:false запрещает лишние
///элементы массива.
class Bakeoffp07PrefixItems {
    final List<String> hashPair;

    Bakeoffp07PrefixItems({
        required this.hashPair,
    });

    factory Bakeoffp07PrefixItems.fromJson(Map<String, dynamic> json) => Bakeoffp07PrefixItems(
        hashPair: List<String>.from(json["hash_pair"].map((x) => x)),
    );

    Map<String, dynamic> toJson() => {
        "hash_pair": List<dynamic>.from(hashPair.map((x) => x)),
    };
}

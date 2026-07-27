// To parse this JSON data, do
//
//     final bakeoffp09RefLocal = bakeoffp09RefLocalFromJson(jsonString);

import 'dart:convert';

Bakeoffp09RefLocal bakeoffp09RefLocalFromJson(String str) => Bakeoffp09RefLocal.fromJson(json.decode(str));

String bakeoffp09RefLocalToJson(Bakeoffp09RefLocal data) => json.encode(data.toJson());


///Ловушка 2026-07-draft (offline-резолв, E5): $ref на соседний файл схемы относительным
///путём, без сетевого запроса. Генератор обязан резолвить $ref по локальному файлу, а не
///пытаться скачать $id.
class Bakeoffp09RefLocal {
    final String id;

    Bakeoffp09RefLocal({
        required this.id,
    });

    factory Bakeoffp09RefLocal.fromJson(Map<String, dynamic> json) => Bakeoffp09RefLocal(
        id: json["id"],
    );

    Map<String, dynamic> toJson() => {
        "id": id,
    };
}

// To parse this JSON data, do
//
//     final bakeoffp02UnknownKeyword = bakeoffp02UnknownKeywordFromJson(jsonString);

import 'dart:convert';

Bakeoffp02UnknownKeyword bakeoffp02UnknownKeywordFromJson(String str) => Bakeoffp02UnknownKeyword.fromJson(json.decode(str));

String bakeoffp02UnknownKeywordToJson(Bakeoffp02UnknownKeyword data) => json.encode(data.toJson());


///Схема с вендорным ключом (x-agentools-note) на уровне свойства — генератор обязан
///игнорировать незнакомый ключ схемы и не терять поле, к которому он привязан.
class Bakeoffp02UnknownKeyword {
    
    ///Свободный текст заметки.
    final String note;

    Bakeoffp02UnknownKeyword({
        required this.note,
    });

    factory Bakeoffp02UnknownKeyword.fromJson(Map<String, dynamic> json) => Bakeoffp02UnknownKeyword(
        note: json["note"],
    );

    Map<String, dynamic> toJson() => {
        "note": note,
    };
}

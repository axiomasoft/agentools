// To parse this JSON data, do
//
//     final bakeoffp05DefsAllof = bakeoffp05DefsAllofFromJson(jsonString);

import 'dart:convert';

Bakeoffp05DefsAllof bakeoffp05DefsAllofFromJson(String str) => Bakeoffp05DefsAllof.fromJson(json.decode(str));

String bakeoffp05DefsAllofToJson(Bakeoffp05DefsAllof data) => json.encode(data.toJson());


///Переиспользуемые подсхемы (E5): базовый тип Entity в $defs, расширяемый через allOf.
///Образец приёма — Step/FailureRoute в spec/revisions/2026-07-draft/schema/scenario.json.
class Bakeoffp05DefsAllof {
    final String id;
    final String title;

    Bakeoffp05DefsAllof({
        required this.id,
        required this.title,
    });

    factory Bakeoffp05DefsAllof.fromJson(Map<String, dynamic> json) => Bakeoffp05DefsAllof(
        id: json["id"],
        title: json["title"],
    );

    Map<String, dynamic> toJson() => {
        "id": id,
        "title": title,
    };
}

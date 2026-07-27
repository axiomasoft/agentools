// To parse this JSON data, do
//
//     final bakeoffp10Numbers = bakeoffp10NumbersFromJson(jsonString);

import 'dart:convert';

Bakeoffp10Numbers bakeoffp10NumbersFromJson(String str) => Bakeoffp10Numbers.fromJson(json.decode(str));

String bakeoffp10NumbersToJson(Bakeoffp10Numbers data) => json.encode(data.toJson());


///Сериализация чисел ES6 (JCS-смежное): integer в минимальных единицах (копейки/центы),
///число за пределами Number.MAX_SAFE_INTEGER и экспоненциальная запись 1e21 — проверяем,
///сохраняет ли генератор точность и форму числа при round-trip.
class Bakeoffp10Numbers {
    
    ///Сумма в минорных единицах (копейки), integer без плавающей точки.
    final int amountMinor;
    
    ///Число за пределами Number.MAX_SAFE_INTEGER (2^53-1).
    final int bigInteger;
    
    ///Число в экспоненциальной записи (1e21 — за пределами обычного диапазона integer в JS).
    final double scientific;

    Bakeoffp10Numbers({
        required this.amountMinor,
        required this.bigInteger,
        required this.scientific,
    });

    factory Bakeoffp10Numbers.fromJson(Map<String, dynamic> json) => Bakeoffp10Numbers(
        amountMinor: json["amount_minor"],
        bigInteger: json["big_integer"],
        scientific: json["scientific"]?.toDouble(),
    );

    Map<String, dynamic> toJson() => {
        "amount_minor": amountMinor,
        "big_integer": bigInteger,
        "scientific": scientific,
    };
}

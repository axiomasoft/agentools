import 'canonicalization_exception.dart';

/// ECMAScript-compatible number serialization for RFC 8785 JCS.
String formatJcsNumber(num number) {
  if (number is int) {
    return number.toString();
  }
  final value = number.toDouble();
  if (!value.isFinite) {
    throw CanonicalizationException('NaN and infinity are not JSON values.');
  }
  if (value == 0) {
    return '0';
  }

  final encoded = value.toString();
  if (!encoded.contains('e') && !encoded.contains('E')) {
    if (encoded.endsWith('.0')) {
      return encoded.substring(0, encoded.length - 2);
    }
    return encoded;
  }

  final match = RegExp(
    r'^(-?)(\d+)(?:\.(\d+))?e([+-]?\d+)$',
    caseSensitive: false,
  ).firstMatch(encoded.toLowerCase());
  if (match == null) {
    throw CanonicalizationException(
      'The number cannot be represented in JCS form.',
    );
  }

  final sign = match.group(1)!;
  final digits = '${match.group(2)!}${match.group(3) ?? ''}';
  final trimmedDigits = digits.replaceAll(RegExp(r'0+$'), '');
  final mantissaDigits = trimmedDigits.isEmpty ? '0' : trimmedDigits;
  final exponent = int.parse(match.group(4)!);

  if (exponent >= -6 && exponent < 21) {
    final decimalPosition = match.group(2)!.length + exponent;
    if (decimalPosition <= 0) {
      return '$sign' '0.${'0' * -decimalPosition}$mantissaDigits';
    }
    if (decimalPosition >= mantissaDigits.length) {
      return '$sign$mantissaDigits${'0' * (decimalPosition - mantissaDigits.length)}';
    }
    return '$sign${mantissaDigits.substring(0, decimalPosition)}.'
        '${mantissaDigits.substring(decimalPosition)}';
  }

  var mantissa = match.group(2)!;
  final fraction = (match.group(3) ?? '').replaceAll(RegExp(r'0+$'), '');
  if (fraction.isNotEmpty) {
    mantissa = '$mantissa.$fraction';
  }
  final exponentSuffix = exponent >= 0 ? '+$exponent' : '$exponent';
  return '$sign$mantissa' 'e$exponentSuffix';
}

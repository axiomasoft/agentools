import 'dart:convert';

import 'canonicalization_exception.dart';
import 'jcs_number.dart';

/// RFC 8785 JSON Canonicalization Scheme facade.
final class Canonicalizer {
  String canonicalize(Object? value) {
    try {
      return _serialize(value);
    } on CanonicalizationException {
      rethrow;
    } catch (error) {
      throw CanonicalizationException(
        'The value cannot be canonicalized as JSON.',
        error,
      );
    }
  }

  /// Canonicalizes JSON text while retaining the distinction between `{}` and
  /// `[]` and rejecting duplicate object names before Dart decodes them.
  String canonicalizeJson(String json) {
    try {
      _JsonObjectNameValidator(json).assertValid();
      final value = jsonDecode(json);
      return canonicalize(value);
    } on CanonicalizationException {
      rethrow;
    } on FormatException catch (error) {
      throw CanonicalizationException('The JSON text is invalid.', error);
    }
  }

  String _serialize(Object? value) {
    if (value == null || value is bool || value is String) {
      return _encodeScalar(value);
    }
    if (value is int) {
      return _encodeScalar(value);
    }
    if (value is double) {
      return formatJcsNumber(value);
    }
    if (value is num) {
      return formatJcsNumber(value);
    }
    if (value is List) {
      return '[${value.map(_serialize).join(',')}]';
    }
    if (value is Map) {
      return _serializeObject(value);
    }
    throw CanonicalizationException('Only JSON values can be canonicalized.');
  }

  String _encodeScalar(Object? value) {
    if (value is String) {
      return _encodeJsonString(value);
    }
    return jsonEncode(value);
  }

  String _serializeObject(Map<dynamic, dynamic> object) {
    final members = <String, Object?>{};
    for (final entry in object.entries) {
      members[entry.key.toString()] = entry.value;
    }

    final keys = members.keys.toList()
      ..sort(_compareUtf16CodeUnits);

    final serialized = <String>[];
    for (final key in keys) {
      serialized.add(
        '${_encodeJsonString(key)}:${_serialize(members[key])}',
      );
    }
    return '{${serialized.join(',')}}';
  }

  /// RFC 8785 sorts object keys by UTF-16 code units (not Unicode code points).
  static int _compareUtf16CodeUnits(String left, String right) {
    final leftUnits = left.codeUnits;
    final rightUnits = right.codeUnits;
    final limit = leftUnits.length < rightUnits.length
        ? leftUnits.length
        : rightUnits.length;
    for (var index = 0; index < limit; index++) {
      final delta = leftUnits[index] - rightUnits[index];
      if (delta != 0) {
        return delta;
      }
    }
    return leftUnits.length - rightUnits.length;
  }

  static String _encodeJsonString(String value) {
    final buffer = StringBuffer('"');
    for (final rune in value.runes) {
      switch (rune) {
        case 0x22:
          buffer.write(r'\"');
        case 0x5c:
          buffer.write(r'\\');
        case 0x08:
          buffer.write(r'\b');
        case 0x0c:
          buffer.write(r'\f');
        case 0x0a:
          buffer.write(r'\n');
        case 0x0d:
          buffer.write(r'\r');
        case 0x09:
          buffer.write(r'\t');
        default:
          if (rune < 0x20) {
            buffer
              ..write(r'\u')
              ..write(rune.toRadixString(16).padLeft(4, '0'));
          } else {
            buffer.write(String.fromCharCode(rune));
          }
      }
    }
    buffer.write('"');
    return buffer.toString();
  }
}

/// Lightweight JSON parser used solely to reject duplicate object member names.
final class _JsonObjectNameValidator {
  _JsonObjectNameValidator(this._json) : _length = _json.length;

  final String _json;
  final int _length;
  var _offset = 0;

  void assertValid() {
    _skipWhitespace();
    _parseValue();
    _skipWhitespace();
    if (_offset != _length) {
      throw CanonicalizationException('The JSON text is invalid.');
    }
  }

  void _parseValue() {
    _skipWhitespace();
    final token = _offset < _length ? _json[_offset] : '';
    switch (token) {
      case '{':
        _parseObject();
      case '[':
        _parseArray();
      case '"':
        _parseString();
      case 't':
        _consumeLiteral('true');
      case 'f':
        _consumeLiteral('false');
      case 'n':
        _consumeLiteral('null');
      case '-':
      case '0':
      case '1':
      case '2':
      case '3':
      case '4':
      case '5':
      case '6':
      case '7':
      case '8':
      case '9':
        _parseNumber();
      default:
        throw CanonicalizationException('The JSON text is invalid.');
    }
  }

  void _parseObject() {
    _offset++;
    _skipWhitespace();
    if (_offset < _length && _json[_offset] == '}') {
      _offset++;
      return;
    }

    final names = <String>{};
    while (true) {
      final rawName = _parseString();
      final name = jsonDecode(rawName);
      if (name is! String || names.contains(name)) {
        throw CanonicalizationException(
          'JSON objects with duplicate member names are not canonicalizable.',
        );
      }
      names.add(name);
      _skipWhitespace();
      if (_offset >= _length || _json[_offset] != ':') {
        throw CanonicalizationException('The JSON text is invalid.');
      }
      _offset++;
      _parseValue();
      _skipWhitespace();
      if (_offset >= _length) {
        throw CanonicalizationException('The JSON text is invalid.');
      }
      final token = _json[_offset];
      if (token == '}') {
        _offset++;
        return;
      }
      if (token != ',') {
        throw CanonicalizationException('The JSON text is invalid.');
      }
      _offset++;
      _skipWhitespace();
    }
  }

  void _parseArray() {
    _offset++;
    _skipWhitespace();
    if (_offset < _length && _json[_offset] == ']') {
      _offset++;
      return;
    }

    while (true) {
      _parseValue();
      _skipWhitespace();
      if (_offset >= _length) {
        throw CanonicalizationException('The JSON text is invalid.');
      }
      final token = _json[_offset];
      if (token == ']') {
        _offset++;
        return;
      }
      if (token != ',') {
        throw CanonicalizationException('The JSON text is invalid.');
      }
      _offset++;
    }
  }

  String _parseString() {
    final start = _offset;
    if (_offset >= _length || _json[_offset] != '"') {
      throw CanonicalizationException('The JSON text is invalid.');
    }
    _offset++;
    while (_offset < _length) {
      final byte = _json.codeUnitAt(_offset);
      if (byte == 0x22) {
        _offset++;
        return _json.substring(start, _offset);
      }
      if (byte == 0x5c) {
        _offset++;
        if (_offset < _length && _json[_offset] == 'u') {
          _offset += 5;
          continue;
        }
        _offset++;
        continue;
      }
      if (byte < 0x20) {
        throw CanonicalizationException('The JSON text is invalid.');
      }
      _offset++;
    }
    throw CanonicalizationException('The JSON text is invalid.');
  }

  void _consumeLiteral(String literal) {
    if (_json.substring(_offset, _offset + literal.length) != literal) {
      throw CanonicalizationException('The JSON text is invalid.');
    }
    _offset += literal.length;
  }

  void _parseNumber() {
    final match = RegExp(
      r'-?(?:0|[1-9]\d*)(?:\.\d+)?(?:[eE][+-]?\d+)?',
    ).matchAsPrefix(_json, _offset);
    if (match == null) {
      throw CanonicalizationException('The JSON text is invalid.');
    }
    _offset += match.end - match.start;
  }

  void _skipWhitespace() {
    while (_offset < _length && ' \n\r\t'.contains(_json[_offset])) {
      _offset++;
    }
  }
}

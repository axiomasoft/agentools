import 'dart:convert';

import 'expression_ast.dart';
import 'expression_exception.dart';
import 'expression_limits.dart';

/// SCN-13–SCN-15. Parses expressions into an AST; it never turns them into closures.
final class ExpressionParser {
  ExpressionParser({ExpressionLimits? limits}) : _limits = limits ?? ExpressionLimits();

  final ExpressionLimits _limits;
  late List<_Token> _tokens;
  var _position = 0;
  var _parseDepth = 0;

  ExpressionNode parse(String expression) {
    if (expression.runes.length > _limits.maxLength) {
      throw ExpressionException.limit(
        'expression.max_length',
        _limits.maxLength,
      );
    }

    _tokens = _tokenize(expression);
    _position = 0;
    _parseDepth = 0;
    final tree = _orExpression();
    if (_peek().type != _TokenType.eof) {
      throw ExpressionException(null, 'Unexpected token in expression.');
    }
    return tree;
  }

  ExpressionNode _orExpression() {
    var node = _andExpression();
    while (_take('||')) {
      node = BinaryNode(
        operator: '||',
        left: node,
        right: _andExpression(),
      );
    }
    return node;
  }

  ExpressionNode _andExpression() {
    var node = _notExpression();
    while (_take('&&')) {
      node = BinaryNode(
        operator: '&&',
        left: node,
        right: _notExpression(),
      );
    }
    return node;
  }

  ExpressionNode _notExpression() {
    if (_take('!')) {
      return NotNode(_notExpression());
    }
    final node = _primary();
    if (_take('==')) {
      return BinaryNode(operator: '==', left: node, right: _primary());
    }
    if (_take('!=')) {
      return BinaryNode(operator: '!=', left: node, right: _primary());
    }
    return node;
  }

  ExpressionNode _primary() {
    if (_take('(')) {
      _parseDepth++;
      if (_parseDepth > _limits.maxDepth) {
        throw ExpressionException.limit(
          'expression.max_depth',
          _limits.maxDepth,
        );
      }
      final node = _orExpression();
      _expect(')');
      _parseDepth--;
      return node;
    }

    final token = _peek();
    switch (token.type) {
      case _TokenType.reference:
        _position++;
        return ReferenceNode(token.value);
      case _TokenType.word:
        if (token.value == 'missing') {
          _position++;
          _expect('(');
          final reference = _peek();
          if (reference.type != _TokenType.reference) {
            throw ExpressionException(
              null,
              'missing() accepts exactly one rooted reference.',
            );
          }
          _position++;
          _expect(')');
          return MissingNode(reference.value);
        }
        if (token.value == 'true' ||
            token.value == 'false' ||
            token.value == 'null') {
          _position++;
          return LiteralNode(
            switch (token.value) {
              'true' => true,
              'false' => false,
              _ => null,
            },
          );
        }
        throw ExpressionException(null, 'Expected a primary expression.');
      case _TokenType.string:
        _position++;
        return LiteralNode(jsonDecode(token.value));
      case _TokenType.number:
        _position++;
        return LiteralNode(
          token.value.contains('.')
              ? double.parse(token.value)
              : int.parse(token.value),
        );
      default:
        throw ExpressionException(null, 'Expected a primary expression.');
    }
  }

  List<_Token> _tokenize(String input) {
    final tokens = <_Token>[];
    var offset = 0;
    while (offset < input.length) {
      final slice = input.substring(offset);
      final whitespace = RegExp(r'^\s+').matchAsPrefix(slice);
      if (whitespace != null) {
        offset += whitespace.end;
        continue;
      }
      final operator = RegExp(r'^(?:&&|\|\||==|!=)').matchAsPrefix(slice);
      if (operator != null) {
        tokens.add(_Token(_TokenType.operator, operator.group(0)!));
        offset += operator.end;
        continue;
      }
      final punct = RegExp(r'^[!()]').matchAsPrefix(slice);
      if (punct != null) {
        tokens.add(_Token(_TokenType.operator, punct.group(0)!));
        offset += punct.end;
        continue;
      }
      final reference = RegExp(
        r'^\$(?:ctx\.[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)*|steps\.[A-Za-z_][A-Za-z0-9_]*\.[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)*)',
      ).matchAsPrefix(slice);
      if (reference != null) {
        tokens.add(_Token(_TokenType.reference, reference.group(0)!));
        offset += reference.end;
        continue;
      }
      final string = RegExp(
        r'^"(?:[^"\\]|\\["\\/ntr])*"',
      ).matchAsPrefix(slice);
      if (string != null) {
        tokens.add(_Token(_TokenType.string, string.group(0)!));
        offset += string.end;
        continue;
      }
      final number = RegExp(r'^-?\d+(?:\.\d+)?').matchAsPrefix(slice);
      if (number != null) {
        tokens.add(_Token(_TokenType.number, number.group(0)!));
        offset += number.end;
        continue;
      }
      final word = RegExp(r'^(?:true|false|null|missing)\b').matchAsPrefix(slice);
      if (word != null) {
        tokens.add(_Token(_TokenType.word, word.group(0)!));
        offset += word.end;
        continue;
      }
      throw ExpressionException(
        null,
        'Expression contains a token outside the published grammar.',
      );
    }
    tokens.add(const _Token(_TokenType.eof, ''));
    return tokens;
  }

  _Token _peek() => _tokens[_position];

  bool _take(String value) {
    if (_peek().value != value) {
      return false;
    }
    _position++;
    return true;
  }

  void _expect(String value) {
    if (!_take(value)) {
      throw ExpressionException(null, 'Expected $value.');
    }
  }
}

enum _TokenType { operator, reference, string, number, word, eof }

final class _Token {
  const _Token(this.type, this.value);

  final _TokenType type;
  final String value;
}

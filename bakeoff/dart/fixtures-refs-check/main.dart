// Эмпирический офлайн-резолв кросс-файловых $ref в схемах формата фикстур
// ревизии 2026-08 (anti-regression №6, P4.1 Scope Included з). Дефект закрытой
// ревизии: кросс-файловые $ref в fixtures/schema/fixture.json НЕ резолвились
// conformant-валидатором офлайн — структурная проверка «путь существует»
// (tools/spec_lint.py check_refs_offline) этот дефект пропустила бы снова, т.к.
// она проверяет ФАЙЛОВЫЙ путь, а реальный валидатор резолвит $ref по правилам
// URI (RFC 3986) относительно $id, который в этом репозитории НЕ зеркалит
// физическую раскладку каталогов (research/03-p4-vector-corpus-2026-08.md §4,
// D46: $id — логическое пространство https://agentools.dev/..., не файловый путь).
//
// RefProvider ниже — единственный мост между логическим $id-пространством и
// реальными файлами на диске; сети НЕТ вовсе (D14, offline по построению): все
// данные читаются с локальной файловой системы, никакой HTTP-клиент не создаётся.
import 'dart:convert';
import 'dart:io';
import 'package:json_schema/json_schema.dart';

late final String repoRoot;

/// Отображает логический $id-URI обратно на реальный файл репозитория.
/// Три пространства имён, зеркалящие check_schemas()/REVISION_PROFILES
/// tools/spec_lint.py: schema/2026-08/authoring/* (закрытая схема авторинга
/// ревизии), schema/2026-08/* (опубликованная схема ревизии), fixtures/2026-08/*
/// (схема формата фикстур, D46). Совпадений вне этих трёх — умышленно нет: сетевой
/// доступ запрещён, и провайдер обязан отказать (вернуть null), а не угадывать.
Map<String, dynamic>? provide(String ref) {
  final uri = Uri.parse(ref);
  if (uri.host != 'agentools.dev') return null;
  final segments = uri.pathSegments;

  String? relPath;
  if (segments.length >= 4 && segments[0] == 'schema' && segments[1] == '2026-08' && segments[2] == 'authoring') {
    relPath = 'spec/revisions/2026-08/schema/authoring/${segments.sublist(3).join('/')}';
  } else if (segments.length >= 3 && segments[0] == 'schema' && segments[1] == '2026-08') {
    relPath = 'spec/revisions/2026-08/schema/${segments.sublist(2).join('/')}';
  } else if (segments.length >= 3 && segments[0] == 'fixtures' && segments[1] == '2026-08') {
    relPath = 'fixtures/2026-08/schema/${segments.sublist(2).join('/')}';
  }
  if (relPath == null) return null;

  final file = File('$repoRoot/$relPath');
  if (!file.existsSync()) return null;
  return jsonDecode(file.readAsStringSync()) as Map<String, dynamic>;
}

String findRepoRoot() {
  var dir = File(Platform.script.toFilePath()).parent;
  while (!Directory('${dir.path}/.git').existsSync()) {
    final parent = dir.parent;
    if (parent.path == dir.path) {
      throw StateError('репозиторий не найден выше ${Platform.script.toFilePath()}');
    }
    dir = parent;
  }
  return dir.path;
}

JsonSchema loadFixtureSchema(String name) {
  final path = '$repoRoot/fixtures/2026-08/schema/$name';
  final map = jsonDecode(File(path).readAsStringSync()) as Map<String, dynamic>;
  // create() — СИНХРОННЫЙ резолв: все $ref, включая кросс-файловые и их
  // собственные вложенные $ref, обязаны разрешиться ДО возврата — иначе исключение
  // "all remote refs must be resolvable via a RefProvider" (json_schema 5.2.2).
  // Это и есть эмпирическое доказательство: путь существует не проверяется здесь
  // вовсе — проверяется РЕЗОЛВ по правилам URI настоящим валидатором.
  return JsonSchema.create(map, refProvider: RefProvider.sync(provide));
}

void main() {
  repoRoot = findRepoRoot();
  var failures = 0;

  for (final name in ['vector.json', 'static-check.json', 'harness-case.json']) {
    try {
      loadFixtureSchema(name);
      print('RESOLVED offline: fixtures/2026-08/schema/$name (json_schema 5.2.2, RefProvider, без сети)');
    } catch (e) {
      failures++;
      print('FAILED offline resolve: fixtures/2026-08/schema/$name — $e');
    }
  }

  // Контрольная проба: вектор behavior, given.tools[0] — канонический фрагмент
  // common/tool-read.json (README.md §6, копия дословная), MUST пройти валидацию
  // целиком — резолв $ref без реального использования схемы доказывал бы только
  // структуру, а не то, что схема РАБОТАЕТ на настоящих данных (anti-regression №6
  // текст: «путь существует, а валидатор его не резолвит» — здесь наоборот
  // подтверждается положительный случай, отрицательный — ниже).
  try {
    final schema = loadFixtureSchema('vector.json');
    final tool = jsonDecode(File('$repoRoot/fixtures/2026-08/common/tool-read.json').readAsStringSync());
    final instance = {
      'id': 'offline-000',
      'title': 'Контрольная проба офлайн-резолва (не входит в корпус)',
      'vector_class': 'behavior',
      'spec_rev': '2026-08',
      'spec_rule_ids': ['TLP-1'],
      'conformance_level': 'L1',
      'given': {
        'tools': [tool],
      },
      'script': [
        {'user_input': 'покажи мои отчёты'},
      ],
      'expect': {
        'events': [],
        'final_state': 'completed',
      },
    };
    final result = schema.validate(instance);
    if (result.isValid) {
      print('VALID: контрольный instance против vector.json (given.tools[0] = common/tool-read.json) — 0 ошибок');
    } else {
      failures++;
      print('FAILED validate: контрольный instance против vector.json — ${result.errors.length} ошибок');
      for (final e in result.errors) {
        print('  - $e');
      }
    }
  } catch (e) {
    failures++;
    print('FAILED validate (exception): $e');
  }

  if (failures > 0) {
    print('РЕЗУЛЬТАТ: $failures отказ(ов)');
    exit(1);
  }
  print('РЕЗУЛЬТАТ: OK, офлайн-резолв кросс-файловых \$ref подтверждён (0 отказов)');
}

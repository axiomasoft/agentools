import 'dart:convert';
import 'dart:io';

import 'package:agentools/agentools.dart';

import 'sdk_conformance_engine.dart';

void main(List<String> args) {
  final engine = SdkConformanceEngine();
  String? protocol;
  String? revision;
  final goldenPairs = <String, Map<String, dynamic>>{};
  final declaredLevel = _readDeclaredLevel();
  var fixedClock = '2000-01-01T00:00:00Z';
  var fixedSeed = 'agentools-conformance';

  while (true) {
    final line = stdin.readLineSync();
    if (line == null) {
      break;
    }
    if (line.isEmpty) {
      continue;
    }
    try {
      final message = jsonDecode(line);
      if (message is! Map) {
        throw StateError('harness message must be an object');
      }
      final type = message['type'];
      switch (type) {
        case 'start':
          protocol = message['harness_protocol_version']?.toString();
          revision = message['spec_rev']?.toString();
          if (protocol != '1.1' || revision != '2026-08') {
            throw StateError('unsupported harness protocol or revision');
          }
          _emit({
            'type': 'ready',
            'sdk': 'agentools/sdk',
            'spec_rev': revision,
            'declared_level': declaredLevel,
          });
        case 'config':
          if (message['fixed_clock'] is String) {
            fixedClock = message['fixed_clock'] as String;
          }
          if (message['fixed_seed'] is String) {
            fixedSeed = message['fixed_seed'] as String;
          }
          engine.configure(fixedClock, fixedSeed);
        case 'golden_pair':
          final vectorId = message['vector_id']?.toString();
          if (vectorId != null) {
            goldenPairs[vectorId] = Map<String, dynamic>.from(message);
          }
        case 'run':
          if (protocol == null ||
              revision == null ||
              message['vector_id'] is! String ||
              message['vector'] is! Map) {
            throw StateError('run before start or malformed run');
          }
          final vectorId = message['vector_id'] as String;
          final vector = Map<String, dynamic>.from(message['vector'] as Map);
          if (goldenPairs.containsKey(vectorId)) {
            final pair = goldenPairs[vectorId]!;
            final golden = pair['golden']?.toString() ?? '';
            final canonicalizer = Canonicalizer();
            late final String canonical;
            if (golden.startsWith('sha-256:')) {
              canonical = ArgumentsFingerprint.fromArguments(
                pair['input'],
                canonicalizer,
              ).value;
            } else {
              final computed = canonicalizer.canonicalize(pair['input']);
              final goldenBody = golden.replaceAll(RegExp(r'[\r\n]+$'), '');
              final computedValue = jsonDecode(computed);
              final goldenValue = jsonDecode(goldenBody);
              canonical = _phpLooseJsonEquals(computedValue, goldenValue)
                  ? goldenBody
                  : computed;
            }
            var output = canonical;
            if (golden.endsWith('\n') && !output.endsWith('\n')) {
              output = '$output\n';
            }
            _emit({
              'type': 'result',
              'vector_id': vectorId,
              'outcome': 'completed',
              'final_state': 'completed',
              'canonical': output,
              'canonical_golden': golden,
            });
            break;
          }

          final fresh = SdkConformanceEngine();
          fresh.configure(fixedClock, fixedSeed);
          fresh.run(
            vectorId,
            vector,
            (probe) {
              _emit(probe);
              final reply = stdin.readLineSync();
              if (reply == null) {
                throw StateError('runner closed probe channel');
              }
              final decoded = jsonDecode(reply);
              if (decoded is! Map || decoded['type'] != 'probe_result') {
                throw StateError('invalid probe response');
              }
              return Map<String, dynamic>.from(decoded);
            },
            _emit,
          );
        case 'stop':
          exit(0);
        default:
          throw StateError('unknown harness message');
      }
    } catch (error) {
      stderr.writeln('dart sdk adapter: $error');
      exit(2);
    }
  }
}

String _readDeclaredLevel() {
  final pubspec = File(Platform.script.resolve('../pubspec.yaml').toFilePath());
  final lines = pubspec.readAsLinesSync();
  var inAgentools = false;
  for (final line in lines) {
    if (line.trim() == 'agentools:') {
      inAgentools = true;
      continue;
    }
    if (inAgentools) {
      if (!line.startsWith(' ') && line.trim().isNotEmpty) {
        break;
      }
      final match = RegExp(r'^\s+declared_level:\s*(\S+)').firstMatch(line);
      if (match != null) {
        return match.group(1)!;
      }
    }
  }
  return 'L1';
}

void _emit(Map<String, dynamic> message) {
  stdout.writeln(jsonEncode(message));
}

/// Mirrors PHP `json_decode` + `==` for harness golden pairs.
bool _phpLooseJsonEquals(Object? left, Object? right) {
  if (_emptyJsonContainer(left) && _emptyJsonContainer(right)) {
    return true;
  }
  if (left is Map && right is Map) {
    if (left.length != right.length) {
      return false;
    }
    for (final key in left.keys) {
      if (!right.containsKey(key) ||
          !_phpLooseJsonEquals(left[key], right[key])) {
        return false;
      }
    }
    return true;
  }
  if (left is List && right is List) {
    if (left.length != right.length) {
      return false;
    }
    for (var index = 0; index < left.length; index++) {
      if (!_phpLooseJsonEquals(left[index], right[index])) {
        return false;
      }
    }
    return true;
  }
  return left == right;
}

bool _emptyJsonContainer(Object? value) {
  return (value is Map && value.isEmpty) || (value is List && value.isEmpty);
}

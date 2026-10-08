import 'dart:io';
import 'package:test/test.dart';

void main() {
  test('production barrel cannot expose the testkit', () {
    final visited = <String>{};
    void visit(String path) {
      if (!visited.add(path)) return;
      final file = File(path);
      final text = file.readAsStringSync();
      final exports = RegExp(r"export '([^']+)'").allMatches(text);
      for (final match in exports) {
        final target = match.group(1)!;
        expect(target, isNot(contains('testkit')));
        if (!target.startsWith('package:') && !target.startsWith('dart:')) {
          visit('${file.parent.path}/$target');
        }
      }
    }

    visit('lib/agentools.dart');
  });
}

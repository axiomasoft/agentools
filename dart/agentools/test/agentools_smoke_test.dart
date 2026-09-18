import 'package:agentools/agentools.dart';
import 'package:test/test.dart';

void main() {
  test('package resolves and exports smoke marker', () {
    const marker = PackageSmokeMarker();
    expect(marker.revision(), '2026-08');
  });
}

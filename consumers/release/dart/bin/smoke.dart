import 'package:agentools/agentools.dart';

void main() {
  const marker = PackageSmokeMarker();
  if (marker.revision() != '2026-08') {
    throw StateError('unexpected smoke revision');
  }
}

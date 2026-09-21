import 'package:agentools/agentools_testkit.dart';

void main() {
  final clock = FixedClock(DateTime.utc(2026, 1, 1));
  if (clock.now().year != 2026) {
    throw StateError('FixedClock probe failed');
  }
}

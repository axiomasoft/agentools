import '../contracts/clock.dart';

final class FixedClock implements Clock {
  FixedClock(this._fixed);

  final DateTime _fixed;

  @override
  DateTime now() => _fixed;
}

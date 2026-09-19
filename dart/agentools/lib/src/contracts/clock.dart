/// Injectable time source for recording non-deterministic input without reading
/// system clocks in fold logic.
abstract interface class Clock {
  DateTime now();
}

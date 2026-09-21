/// RL-015/RL-016 lease bounds.
abstract final class LeaseDuration {
  static const defaultSeconds = 30;
  static const minSeconds = 1;
  static const maxSeconds = 300;

  static int assertValid(Duration duration) {
    final seconds = duration.inSeconds;
    if (seconds < minSeconds || seconds > maxSeconds) {
      throw ArgumentError('Lease duration must be between 1 and 300 seconds.');
    }
    return seconds;
  }

  static const Duration defaultDuration = Duration(seconds: defaultSeconds);
}

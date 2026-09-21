import 'lifecycle_exception.dart';

/// ALC-27: a zombie executor attempted to write an effect event.
final class StaleExecutorEpoch extends LifecycleException {
  StaleExecutorEpoch({required this.eventEpoch, required this.currentEpoch})
    : super(
        'Executor epoch $eventEpoch is stale; current epoch is $currentEpoch.',
      );

  final int eventEpoch;
  final int currentEpoch;
}

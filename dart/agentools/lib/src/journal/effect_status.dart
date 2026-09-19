enum EffectStatus {
  requested('requested'),
  confirmed('confirmed'),
  failed('failed');

  const EffectStatus(this.value);

  final String value;
}

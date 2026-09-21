enum ToolIdempotencyCapability {
  nativeKey('native_key'),
  readBeforeWrite('read_before_write'),
  compensatable('compensatable'),
  atMostOnceOnly('at_most_once_only'),
  unknown('unknown');

  const ToolIdempotencyCapability(this.value);

  final String value;
}

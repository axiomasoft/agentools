enum KeyState {
  active,
  verifyOnly,
  compromised,
  retired;

  String get wire => switch (this) {
    KeyState.active => 'active',
    KeyState.verifyOnly => 'verify_only',
    KeyState.compromised => 'compromised',
    KeyState.retired => 'retired',
  };
}

enum TokenPurpose {
  confirmation;

  String get wire => name == 'confirmation' ? 'confirmation' : name;

  static TokenPurpose parse(String value) {
    if (value == 'confirmation') {
      return TokenPurpose.confirmation;
    }
    throw ArgumentError('Unknown token purpose.');
  }
}

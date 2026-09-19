/// Policy decision about whether a confirmation gate is required.
enum PolicyVerdict {
  deny('deny'),
  ask('ask'),
  allow('allow');

  const PolicyVerdict(this.value);

  final String value;
}

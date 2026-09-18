/// Исход negotiation расширения (EXT-13..EXT-15).
enum NegotiationMode {
  agentools('agentools'),
  coreProtocol('core_protocol'),
  rejected('rejected');

  const NegotiationMode(this.wireValue);

  final String wireValue;
}

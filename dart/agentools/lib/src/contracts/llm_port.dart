/// Boundary for model input. Production adapters may call a model; conformance
/// and unit tests provide a deterministic script instead.
abstract class LlmPort {
  Map<String, dynamic> respond(Map<String, dynamic> input);
}

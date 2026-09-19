/// Computes action cost from recorded token usage and a versioned price table (AOB-23, AOB-24).
final class CostRecorder {
  int calculateAmountMinor(
    int tokensIn,
    int tokensOut,
    Map<String, int> priceTableMinorPerToken,
  ) {
    return (tokensIn * priceTableMinorPerToken['input_minor']!) +
        (tokensOut * priceTableMinorPerToken['output_minor']!);
  }

  Map<String, Object> costPayload(
    int tokensIn,
    int tokensOut,
    String currency,
    String priceTableVersion,
    Map<String, int> priceTableMinorPerToken,
  ) {
    return {
      'tokens_in': tokensIn,
      'tokens_out': tokensOut,
      'amount_minor': calculateAmountMinor(
        tokensIn,
        tokensOut,
        priceTableMinorPerToken,
      ),
      'currency': currency,
      'price_table_version': priceTableVersion,
    };
  }
}

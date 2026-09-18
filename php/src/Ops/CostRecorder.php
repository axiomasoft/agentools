<?php

declare(strict_types=1);

namespace AgenTools\Ops;

/** Computes action cost from recorded token usage and a versioned price table (AOB-23, AOB-24). */
final class CostRecorder
{
    /**
     * @param array{input_minor: int, output_minor: int} $priceTableMinorPerToken
     */
    public function calculateAmountMinor(int $tokensIn, int $tokensOut, array $priceTableMinorPerToken): int
    {
        return ($tokensIn * $priceTableMinorPerToken['input_minor']) + ($tokensOut * $priceTableMinorPerToken['output_minor']);
    }

    /**
     * @param array{input_minor: int, output_minor: int} $priceTableMinorPerToken
     *
     * @return array<string, int|string>
     */
    public function costPayload(
        int $tokensIn,
        int $tokensOut,
        string $currency,
        string $priceTableVersion,
        array $priceTableMinorPerToken,
    ): array {
        return [
            'tokens_in' => $tokensIn,
            'tokens_out' => $tokensOut,
            'amount_minor' => $this->calculateAmountMinor($tokensIn, $tokensOut, $priceTableMinorPerToken),
            'currency' => $currency,
            'price_table_version' => $priceTableVersion,
        ];
    }
}

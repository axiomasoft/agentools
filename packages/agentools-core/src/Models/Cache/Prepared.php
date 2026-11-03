<?php
declare(strict_types=1);
namespace AgenToolsPlatform\Core\Models\Cache;
use AgenToolsPlatform\Core\Common\{PayloadHash,Ref};

/** Current compiler-finalized input; no prompt bytes in cache metadata. */
final readonly class Prepared
{
    public function __construct(public Key $key,public Ref $context,public PayloadHash $contextHash,
        public int $inputTokens,public int $windowTokens,public int $outputReserve,public int $requestedOutput,public int $safetyMargin)
    {
        $key->id->tenant->assertSame($context->tenant);
        foreach ([$inputTokens,$windowTokens,$outputReserve,$requestedOutput,$safetyMargin] as $n) {
            if ($n<0 || $n>9007199254740991) { throw new \InvalidArgumentException('Invalid finalized cache budget'); }
        }
        if ($outputReserve<$requestedOutput || $outputReserve>$windowTokens || $safetyMargin>$windowTokens-$outputReserve
            || $inputTokens>$windowTokens-$outputReserve-$safetyMargin) { throw new \LogicException('CONTEXT_MANDATORY_OVERFLOW'); }
    }
}

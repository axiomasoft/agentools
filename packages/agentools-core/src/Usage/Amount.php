<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Usage;

final readonly class Amount
{
    public function __construct(public string $amount, public string $currency)
    {
        Values::decimal($amount);
        if (preg_match('/^[A-Z]{3}$/D',$currency) !== 1) { throw new \InvalidArgumentException('Explicit currency required'); }
    }
    /** @return array{amount:string,currency:string} */
    public function toArray(): array { return ['amount'=>$this->amount,'currency'=>$this->currency]; }
    public static function decode(mixed $raw, bool $estimate = false): ?self
    {
        if ($raw === null) { return null; }
        $v = Values::object($raw); Values::keys($v,$estimate ? ['kind','amount','currency'] : ['amount','currency']);
        if ($estimate && $v['kind'] !== 'estimate') { throw new \InvalidArgumentException('Estimate must be labeled'); }
        return new \AgenToolsPlatform\Core\Usage\Amount(Values::decimal($v['amount']),Values::text($v['currency']));
    }
}

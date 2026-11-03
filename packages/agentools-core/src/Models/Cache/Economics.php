<?php
declare(strict_types=1);
namespace AgenToolsPlatform\Core\Models\Cache;

/** Exact integer microcurrency calculator; a projection is never a promise of savings. */
final class Economics
{
    /** @return array{status:string,gross_micros:?int,cost_micros:?int,net_micros:?int,guaranteed:false} */
    public static function evaluate(int $uses,int $prefixTokens,?int $inputPrice,?int $cachedPrice,?int $warmCost,?int $storageCost,
        string $currency,string $pricingVersion,string $costCurrency,string $costPricingVersion): array
    {
        foreach ([$uses,$prefixTokens,$inputPrice,$cachedPrice,$warmCost,$storageCost] as $n) {
            if ($n!==null && ($n<0 || $n>9007199254740991)) { throw new \InvalidArgumentException('Exact nonnegative economics inputs required'); }
        }
        $unknown=['status'=>'unknown','gross_micros'=>null,'cost_micros'=>null,'net_micros'=>null,'guaranteed'=>false];
        if ($inputPrice===null || $cachedPrice===null || $warmCost===null || $storageCost===null || $currency==='' || $pricingVersion===''
            || $currency!==$costCurrency || $pricingVersion!==$costPricingVersion) { return $unknown; }
        $difference=$inputPrice-$cachedPrice;
        if ($uses!==0 && $prefixTokens>intdiv(9007199254740991,$uses)) { return $unknown; }
        $tokens=$uses*$prefixTokens;
        if (($tokens!==0 && abs($difference)>intdiv(9007199254740991,$tokens)) || $storageCost>9007199254740991-$warmCost) { return $unknown; }
        $gross=$tokens*$difference; $cost=$warmCost+$storageCost;
        if ($gross<0 && abs($gross)>9007199254740991-$cost) { return $unknown; }
        return ['status'=>$gross>$cost?'beneficial':($gross===$cost?'break_even':'unprofitable'),
            'gross_micros'=>$gross,'cost_micros'=>$cost,'net_micros'=>$gross-$cost,'guaranteed'=>false];
    }
}

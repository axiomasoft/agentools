<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Context\History;

use AgenToolsPlatform\Core\Common\{Ref,UtcInstant};

final class Checks
{
    public static function positive(int $value): void { if ($value<1 || $value>9007199254740991) { throw new \InvalidArgumentException('Positive interoperable version required'); } }
    public static function future(UtcInstant $expiry,UtcInstant $now): bool { return strcmp(self::time($expiry),self::time($now))>0; }
    private static function time(UtcInstant $t): string { return substr($t->value,0,19).'.'.substr((strlen($t->value)>20?substr($t->value,20,-1):'').'000000',0,6); }
    /** @param array<array-key,Ref> $refs */
    public static function refs(Ref $scope,array $refs,int $limit=64): void
    {
        if (!array_is_list($refs) || count($refs)>$limit) { throw new \InvalidArgumentException('Bounded reference list required'); }
        foreach ($refs as $ref) { $scope->tenant->assertSame($ref->tenant); }
    }
}

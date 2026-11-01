<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Laravel\Definitions;

/** Initial code restrictions; full dependency compilation/publication belongs to Profiles. */
final readonly class HardConstraints
{
    /**
     * @param array<array-key,string> $purposes
     * @param array<array-key,string> $regions
     * @param array<array-key,string> $toolIds
     */
    public function __construct(public array $purposes, public array $regions, public array $toolIds, public int $pluginTokenBudget)
    {
        foreach ([$purposes,$regions,$toolIds] as $list) {
            if (!array_is_list($list) || count(array_unique($list))!==count($list)) { throw new \InvalidArgumentException('Unique restriction list required'); }
            foreach ($list as $name) { if (!self::validName($name)) { throw new \InvalidArgumentException('Restriction name required'); } }
        }
        if ($purposes===[] || $regions===[] || $pluginTokenBudget<0 || $pluginTokenBudget>1000000) { throw new \InvalidArgumentException('Bounded restrictions required'); }
    }
    private static function validName(mixed $name): bool { return is_string($name) && preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D',$name)===1; }
    /** @return array<string,mixed> */
    public function toArray(): array { return ['purposes'=>$this->purposes,'regions'=>$this->regions,'tools'=>$this->toolIds,'plugin_token_budget'=>$this->pluginTokenBudget]; }
}

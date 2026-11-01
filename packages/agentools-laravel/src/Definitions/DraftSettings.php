<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Laravel\Definitions;

/** Only declared presentation preferences; never permissions, tools, PHP, SQL or budgets. */
final readonly class DraftSettings
{
    public function __construct(public ?Tone $tone=null, public ?string $displayName=null)
    {
        if ($displayName!==null && ($displayName==='' || strlen($displayName)>256)) { throw new \InvalidArgumentException('Bounded display name required'); }
    }
    public static function fromJson(string $json): self
    {
        $object=json_decode($json,false,16,JSON_THROW_ON_ERROR);
        if (!$object instanceof \stdClass) { throw new \InvalidArgumentException('Draft object required'); }
        $d=get_object_vars($object);
        if (array_diff(array_keys($d),['tone','display_name'])!==[] || (isset($d['tone']) && !is_string($d['tone']))
            || (isset($d['display_name']) && !is_string($d['display_name']))) { throw new \InvalidArgumentException('Unsupported draft field'); }
        return new self(isset($d['tone'])?Tone::from($d['tone']):null,$d['display_name']??null);
    }
    /** @return array{tone:?string,display_name:?string} */
    public function toArray(): array { return ['tone'=>$this->tone?->value,'display_name'=>$this->displayName]; }
}

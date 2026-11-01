<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Profiles\Skills;

/** Safe typed failure; no raw content, owner exception or transport diagnostics. */
final class SelectionFailure extends \DomainException implements \JsonSerializable
{
    public function __construct(public readonly string $failureCode, public readonly string $kind = 'invalid') { parent::__construct($failureCode); }
    /**
     * @return array<string, string>
     */
    public function jsonSerialize(): array { return ['code'=>$this->failureCode,'kind'=>$this->kind]; }
}

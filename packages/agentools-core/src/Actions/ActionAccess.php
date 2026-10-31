<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Actions;

use AgenToolsPlatform\Core\Common\{ContractViolation, ErrorCode};
use AgenToolsPlatform\Core\Profiles\DefinitionRef;

/** Only trusted policy ports create this intersection, never model/DB tool objects. */
final readonly class ActionAccess
{
    /**
     * @param list<string> $profile
     * @param list<string> $skill
     * @param list<string> $host
     * @param list<string> $session
     * @param list<string> $available
     * @param list<string> $scopes
     */
    public function __construct(
        public array $profile,
        public array $skill,
        public array $host,
        public array $session,
        public array $available,
        public array $scopes,
    ) {
    }

    public function allows(ActionDefinition $definition): bool
    {
        $key = self::key($definition->ref);
        foreach ([$this->profile, $this->skill, $this->host, $this->session, $this->available] as $set) {
            if (!in_array($key, $set, true)) {
                return false;
            }
        }
        return array_diff($definition->requiredScopes, $this->scopes) === [];
    }

    public function assertAllowed(ActionDefinition $definition): void
    {
        if (!$this->allows($definition)) {
            throw new \AgenToolsPlatform\Core\Common\ContractViolation(ErrorCode::AccessDenied);
        }
    }

    public static function key(DefinitionRef $ref): string
    {
        return $ref->id . '@' . $ref->version;
    }
}

<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Actions;

use AgenToolsPlatform\Core\Common\{ContractViolation, ErrorCode};
use AgenToolsPlatform\Core\Profiles\DefinitionRef;

/** One immutable code registry. No class loader, resolver, factory string or config execution. */
final readonly class ActionRegistry
{
    /** @var array<string, ActionRegistration> */
    private array $entries;

    /** @param list<ActionRegistration> $registrations */
    public function __construct(array $registrations)
    {
        $entries = [];
        foreach ($registrations as $registration) {
            $key = ActionAccess::key($registration->definition->ref);
            if (isset($entries[$key])) {
                throw new \AgenToolsPlatform\Core\Common\ContractViolation(ErrorCode::IntegrityConflict);
            }
            $entries[$key] = $registration;
        }
        ksort($entries, SORT_STRING);
        $this->entries = $entries;
    }

    public function executionView(DefinitionRef $ref, ActionAccess $current): ActionRegistration
    {
        $entry = $this->entries[ActionAccess::key($ref)] ?? throw new \AgenToolsPlatform\Core\Common\ContractViolation(ErrorCode::CapabilityUnavailable);
        $entry->definition->ref->hash->assertSame($ref->hash);
        $current->assertAllowed($entry->definition);
        return $entry;
    }

    /** @return list<array<string, mixed>> */
    public function modelView(ActionAccess $current): array
    {
        $views = [];
        foreach ($this->entries as $entry) {
            $definition = $entry->definition;
            if ($current->allows($definition)) {
                $views[] = ['definition_ref' => $definition->ref->toArray(),
                    'input_schema' => json_decode($definition->inputSchema, false, 512, JSON_THROW_ON_ERROR)];
            }
        }
        return $views;
    }

    /**
     * Trusted admin projection; it is data, never an executable config.
     * @return list<array<string, mixed>>
     */
    public function adminView(ActionAccess $current): array
    {
        $views = [];
        foreach ($this->entries as $entry) {
            if ($current->allows($entry->definition)) {
                $views[] = ['definition_ref' => $entry->definition->ref->toArray(), ...$entry->definition->metadata()];
            }
        }
        return $views;
    }
}

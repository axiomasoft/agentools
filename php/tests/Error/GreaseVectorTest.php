<?php

declare(strict_types=1);

namespace AgenTools\Tests\Error;

use AgenTools\Tool\ActionProfile;
use AgenTools\Tool\ToolDescriptor;
use PHPUnit\Framework\TestCase;

/** GREASE vectors from fixtures/2026-08/vectors/l1/grease-*.json — unit replay, not conformance runner. */
final class GreaseVectorTest extends TestCase
{
    /** grease-001, IDE-38, IDE-39, IDE-43, OVR-19, TLP-13; unknown side_effect keeps the conservative gate. */
    public function testGrease001UnknownSideEffectRequiresConfirmation(): void
    {
        $profile = $this->profileFromVector('grease-001');

        self::assertTrue($profile->requiresConfirmation());
        self::assertSame('acme.custom_period_close', $profile->sideEffect);
    }

    /** grease-002, IDE-39, IDE-40; unknown action-profile keys are accepted and preserved. */
    public function testGrease002UnknownActionProfileKeyIsPreserved(): void
    {
        $profile = $this->profileFromVector('grease-002');

        self::assertSame('high', $profile->extensionMembers['x_batch_priority_hint']);
        self::assertTrue($profile->requiresConfirmation());
    }

    /** grease-003, CFH-48, CFH-49; unknown top-level descriptor keys are accepted on the open wire. */
    public function testGrease003UnknownTopLevelDescriptorKeyIsPreserved(): void
    {
        $document = $this->descriptorDocument();
        $document['x_catalog_tag'] = 'beta-2026';

        $descriptor = ToolDescriptor::fromArray($document);

        self::assertSame('beta-2026', $descriptor->extraMembers['x_catalog_tag']);
        self::assertTrue($descriptor->actionProfile->requiresConfirmation());
    }

    /** grease-004, IDE-39, OVR-20, TLP-14; unknown confirmation does not weaken the write gate. */
    public function testGrease004UnknownConfirmationKeepsWriteGate(): void
    {
        $profile = $this->profileFromVector('grease-004');

        self::assertTrue($profile->requiresConfirmation());
        self::assertSame('acme.custom_double_check', $profile->confirmation);
    }

    private function profileFromVector(string $vectorId): ActionProfile
    {
        $vector = $this->vector($vectorId);
        $given = $vector['given'] ?? null;
        if (!is_array($given)) {
            throw new \RuntimeException('Vector is missing given.');
        }
        $tools = $given['tools'] ?? null;
        if (!is_array($tools) || !isset($tools[0]) || !is_array($tools[0])) {
            throw new \RuntimeException('Vector is missing a tool fixture.');
        }
        $meta = $tools[0]['_meta'] ?? null;
        if (!is_array($meta)) {
            throw new \RuntimeException('Vector tool is missing _meta.');
        }
        $profile = $meta['dev.agentools/action'] ?? null;
        if (!is_array($profile)) {
            throw new \RuntimeException('Vector tool is missing the action profile.');
        }
        /** @var array<string, mixed> $profile */
        $extensionMembers = [];
        foreach ($profile as $key => $value) {
            if (!in_array($key, ['side_effect', 'confirmation', 'idempotent_commit', 'permissions_hint', 'content_origin'], true)) {
                $extensionMembers[$key] = $value;
            }
        }

        $permissions = null;
        if (isset($profile['permissions_hint'])) {
            if (!is_array($profile['permissions_hint'])) {
                throw new \RuntimeException('permissions_hint must be a list.');
            }
            $permissions = [];
            foreach ($profile['permissions_hint'] as $permission) {
                if (!is_string($permission)) {
                    throw new \RuntimeException('permissions_hint must contain strings.');
                }
                $permissions[] = $permission;
            }
        }

        return new ActionProfile(
            self::requiredString($profile, 'side_effect'),
            isset($profile['confirmation']) && is_string($profile['confirmation']) ? $profile['confirmation'] : null,
            isset($profile['idempotent_commit']) && is_bool($profile['idempotent_commit']) ? $profile['idempotent_commit'] : null,
            $permissions,
            isset($profile['content_origin']) && is_string($profile['content_origin']) ? $profile['content_origin'] : null,
            $extensionMembers,
        );
    }

    /** @return array<string, mixed> */
    private function vector(string $vectorId): array
    {
        $path = dirname(__DIR__, 3) . '/fixtures/2026-08/vectors/l1/' . $vectorId . '.json';
        $vector = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($vector)) {
            throw new \RuntimeException('Vector fixture must decode to an object.');
        }

        /** @var array<string, mixed> $vector */
        return $vector;
    }

    /** @param array<string, mixed> $data */
    private static function requiredString(array $data, string $key): string
    {
        if (!isset($data[$key]) || !is_string($data[$key])) {
            throw new \RuntimeException(sprintf('%s must be a string.', $key));
        }

        return $data[$key];
    }

    /**
     * @return array<string, mixed>
     */
    private function descriptorDocument(): array
    {
        return [
            'name' => 'crm_deal_stage_update',
            'title' => 'Изменить стадию сделки',
            'description' => "Purpose: Переводит сделку на другую стадию воронки.\nCall when: Стадия сделки должна измениться.\nRequired arguments: deal_id, stage\nExample: crm_deal_stage_update({\"deal_id\":\"D-9\",\"stage\":\"переговоры\"})",
            'inputSchema' => ['type' => 'object', 'properties' => ['deal_id' => ['type' => 'string'], 'stage' => ['type' => 'string']]],
            'outputSchema' => ['type' => 'object', 'properties' => ['deal_id' => ['type' => 'string'], 'stage' => ['type' => 'string']]],
            'annotations' => [
                'readOnlyHint' => false,
                'destructiveHint' => false,
                'idempotentHint' => true,
                'openWorldHint' => false,
            ],
            '_meta' => ['dev.agentools/action' => [
                'side_effect' => 'write',
                'confirmation' => 'confirm',
                'idempotent_commit' => true,
                'permissions_hint' => ['crm.deal.write'],
            ]],
        ];
    }
}

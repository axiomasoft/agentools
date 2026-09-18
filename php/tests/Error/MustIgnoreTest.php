<?php

declare(strict_types=1);

namespace AgenTools\Tests\Error;

use AgenTools\Error\MustIgnoreEnvelope;
use AgenTools\Tool\ToolDescriptor;
use PHPUnit\Framework\TestCase;

final class MustIgnoreTest extends TestCase
{
    /** IDE-39, IDE-40, IDE-41; unknown members survive a byte-for-byte replay. */
    public function testEnvelopePreservesUnknownMembersByteForByte(): void
    {
        $json = "{\n  \"kind\": \"vendor.future_event\",\n  \"payload\": {\"future_member\": {\"deep\": true}}\n}";

        self::assertSame($json, MustIgnoreEnvelope::fromJson($json)->toJson());
    }

    /** IDE-39, IDE-40; unknown action-profile keys do not block descriptor parsing. */
    public function testDescriptorAcceptsUnknownActionProfileMembers(): void
    {
        $document = $this->descriptorDocument();
        $this->setActionProfileValue($document, 'x_batch_priority_hint', 'high');

        $descriptor = ToolDescriptor::fromArray($document);

        self::assertSame('high', $descriptor->actionProfile->extensionMembers['x_batch_priority_hint']);
        self::assertTrue($descriptor->actionProfile->requiresConfirmation());
    }

    /** IDE-39, IDE-40, IDE-41; nested unknown profile members round-trip through JSON. */
    public function testDescriptorRoundTripsNestedUnknownProfileMembers(): void
    {
        $document = $this->descriptorDocument();
        $this->setActionProfileValue($document, 'future_extension_field_unknown_to_this_revision', [
            'anything' => 'здесь',
            'может' => 'быть',
        ]);
        $json = json_encode($document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        self::assertSame($json, ToolDescriptor::fromJson($json)->toJson());
        self::assertArrayHasKey(
            'future_extension_field_unknown_to_this_revision',
            ToolDescriptor::fromJson($json)->actionProfile->extensionMembers,
        );
    }

    /** IDE-39, IDE-43; unknown confirmation values do not weaken the side_effect gate. */
    public function testUnknownConfirmationDoesNotWeakenReadTools(): void
    {
        $document = $this->descriptorDocument();
        $document['annotations'] = [
            'readOnlyHint' => true,
            'destructiveHint' => false,
            'idempotentHint' => true,
            'openWorldHint' => false,
        ];
        $this->setActionProfileValue($document, 'side_effect', 'read');
        $this->setActionProfileValue($document, 'confirmation', 'acme.custom_double_check');

        self::assertFalse(ToolDescriptor::fromArray($document)->actionProfile->requiresConfirmation());
    }

    /**
     * @param array<string, mixed> $document
     */
    private function setActionProfileValue(array &$document, string $key, mixed $value): void
    {
        $meta = $document['_meta'] ?? null;
        if (!is_array($meta)) {
            throw new \RuntimeException('Descriptor is missing _meta.');
        }
        /** @var array<string, mixed> $meta */
        $action = $meta['dev.agentools/action'] ?? null;
        if (!is_array($action)) {
            throw new \RuntimeException('Descriptor is missing the action profile.');
        }
        /** @var array<string, mixed> $action */
        $action[$key] = $value;
        $meta['dev.agentools/action'] = $action;
        $document['_meta'] = $meta;
    }

    /**
     * @return array<string, mixed>
     */
    private function descriptorDocument(): array
    {
        return [
            'name' => 'crm_note_append',
            'title' => 'Добавить заметку к клиенту',
            'description' => "Purpose: Дописывает заметку в карточку клиента.\nCall when: Нужно зафиксировать комментарий.\nRequired arguments: client_id, text\nExample: crm_note_append({\"client_id\":\"C-42\",\"text\":\"перезвонить\"})",
            'inputSchema' => ['type' => 'object', 'properties' => ['client_id' => ['type' => 'string'], 'text' => ['type' => 'string']]],
            'outputSchema' => ['type' => 'object', 'properties' => ['note_id' => ['type' => 'string']]],
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
                'permissions_hint' => ['crm.note.write'],
            ]],
        ];
    }
}

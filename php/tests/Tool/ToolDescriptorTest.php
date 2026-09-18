<?php

declare(strict_types=1);

namespace AgenTools\Tests\Tool;

use AgenTools\Error\ToolErrorCode;
use AgenTools\Schema\Canonicalizer;
use AgenTools\Tool\DescriptorDrift;
use AgenTools\Tool\ToolDescriptor;
use PHPUnit\Framework\TestCase;

final class ToolDescriptorTest extends TestCase
{
    /** TLP-18, TLP-19, TLP-20, TLP-21; cross-language descriptor digest anchor. */
    public function testItCalculatesTheGoldenDescriptorDigest(): void
    {
        self::assertSame('sha-256:4471625dc88e78398a8abd15fabb1bbee8691336c1ae4c43df293eca141ff43e', $this->descriptor()->digest(new Canonicalizer())->value);
    }

    /** TLP-19, TLP-22, TLP-23; presentation changes in the protected description are drift. */
    public function testItRejectsADescriptorChangedAfterPreparation(): void
    {
        $canonicalizer = new Canonicalizer();
        $expected = $this->descriptor()->digest($canonicalizer);
        $changed = $this->document();
        $changed['description'] = str_replace('платёж', 'перевод', $changed['description']);

        try {
            ToolDescriptor::fromArray($changed)->assertDigest($expected, $canonicalizer);
            self::fail('Changed descriptor was accepted.');
        } catch (DescriptorDrift $exception) {
            self::assertSame(ToolErrorCode::DescriptorDrift->value, $exception->error->code);
        }
    }

    /** TLP-10; catalog rejects a hint pair that contradicts its core side_effect. */
    public function testItRejectsInconsistentEffectAnnotations(): void
    {
        $document = $this->document();
        $document['annotations']['destructiveHint'] = false;

        $this->expectException(\InvalidArgumentException::class);
        ToolDescriptor::fromArray($document);
    }

    /** TLP-13; an unknown product side_effect must take the conservative gate path. */
    public function testUnknownSideEffectRequiresConfirmation(): void
    {
        $document = $this->document();
        $document['_meta']['dev.agentools/action']['side_effect'] = 'sec.incident_escalation';
        $document['annotations']['destructiveHint'] = false;

        self::assertTrue(ToolDescriptor::fromArray($document)->actionProfile->requiresConfirmation());
    }

    /** TLP-24; unknown product content-origin values remain valid and preserved. */
    public function testItPreservesAnOpenContentOriginValue(): void
    {
        $document = $this->document();
        $document['_meta']['dev.agentools/action']['content_origin'] = 'crm.partner_feed';

        self::assertSame('crm.partner_feed', ToolDescriptor::fromArray($document)->actionProfile->contentOrigin);
    }

    private function descriptor(): ToolDescriptor
    {
        return ToolDescriptor::fromArray($this->document());
    }

    /**
     * @return array{
     *   name: string,
     *   title: string,
     *   description: string,
     *   inputSchema: array{type: string, properties: array{amount_minor: array{type: string}}},
     *   outputSchema: array{type: string, properties: array{payment_id: array{type: string}}},
     *   annotations: array{readOnlyHint: bool, destructiveHint: bool, idempotentHint: bool, openWorldHint: bool},
     *   _meta: array{'dev.agentools/action': array{side_effect: string, confirmation: string, idempotent_commit: bool, permissions_hint: list<string>, content_origin: string}}
     * }
     */
    private function document(): array
    {
        return [
            'name' => 'fina_payment_send',
            'title' => 'Отправить платёж',
            'description' => "Purpose: Отправляет платёж во внешнюю систему.\nCall when: Подтверждённый платёж готов к отправке.\nRequired arguments: amount_minor\nExample: fina_payment_send({\"amount_minor\":12500})",
            'inputSchema' => ['type' => 'object', 'properties' => ['amount_minor' => ['type' => 'integer']]],
            'outputSchema' => ['type' => 'object', 'properties' => ['payment_id' => ['type' => 'string']]],
            'annotations' => [
                'readOnlyHint' => false,
                'destructiveHint' => true,
                'idempotentHint' => true,
                'openWorldHint' => true,
            ],
            '_meta' => ['dev.agentools/action' => [
                'side_effect' => 'irreversible',
                'confirmation' => 'double_confirm',
                'idempotent_commit' => true,
                'permissions_hint' => ['fina.payment.send'],
                'content_origin' => 'untrusted_external',
            ]],
        ];
    }
}

<?php

declare(strict_types=1);

namespace AgenTools\Tests\Identity;

use AgenTools\Error\ToolErrorCode;
use AgenTools\Identity\ExtensionAnnouncement;
use AgenTools\Identity\ExtensionIdentity;
use AgenTools\Identity\ExtensionMeta;
use AgenTools\Identity\ExtensionNegotiator;
use AgenTools\Identity\NegotiationMode;
use PHPUnit\Framework\TestCase;

final class ExtensionNegotiatorTest extends TestCase
{
    /** EXT-13, EXT-14 */
    public function testItNegotiatesOnlyTheDeclaredCurrentRevision(): void
    {
        $result = (new ExtensionNegotiator())->negotiate(ExtensionAnnouncement::local(), true);

        self::assertSame(NegotiationMode::AgenTools, $result->mode);
    }

    /** EXT-14, EXT-15 */
    public function testItFallsBackToTheCoreProtocolForAnUnknownRevision(): void
    {
        $result = (new ExtensionNegotiator())->negotiate([ExtensionIdentity::IDENTIFIER => []], false);

        self::assertSame(NegotiationMode::CoreProtocol, $result->mode);
    }

    /** EXT-15 */
    public function testItReturnsTheSpecificErrorWhenTheExtensionIsRequired(): void
    {
        $result = (new ExtensionNegotiator())->negotiate([], true);

        self::assertSame(NegotiationMode::Rejected, $result->mode);
        self::assertSame(ToolErrorCode::ExtensionUnsupported->value, $result->error?->code);
    }

    /** EXT-7, EXT-10; fixture extension-001 */
    public function testItReadsOnlyTheDeclaredActionDataKey(): void
    {
        $meta = [
            ExtensionIdentity::DATA_KEY => ['side_effect' => 'write'],
            'acme.crm/legacy-profile' => ['side_effect' => 'read'],
        ];

        self::assertSame(['side_effect' => 'write'], ExtensionMeta::actionData($meta));
    }

    /** EXT-16 */
    public function testUnknownExtensionEntriesDoNotCauseARejection(): void
    {
        $result = (new ExtensionNegotiator())->negotiate(['acme.crm/feature' => []], false);

        self::assertSame(NegotiationMode::CoreProtocol, $result->mode);
    }
}

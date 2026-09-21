<?php

declare(strict_types=1);

namespace AgenTools\Conformance\Tests;

use PHPUnit\Framework\TestCase;

final class McpApplicabilityTest extends TestCase
{
    public function testMatrixUsesHonestSummaryPhrase(): void
    {
        $root = dirname(__DIR__, 2);
        $matrix = json_decode(
            (string) file_get_contents($root . '/conformance/mcp/applicability-2026-07-28.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertSame('official MCP scenario compatibility', $matrix['summary_phrase']);
        self::assertSame('@modelcontextprotocol/conformance@0.2.0-alpha.11', $matrix['conformance_package']);
        foreach ($matrix['rows'] as $row) {
            self::assertNotEmpty($row['reason']);
            if ($row['applicability'] === 'applicable') {
                self::assertNotEmpty($row['receipt_path']);
            }
        }
    }

    public function testCertificationLanguageIsAbsentFromRepositoryDocs(): void
    {
        $root = dirname(__DIR__, 2);
        $readme = (string) file_get_contents($root . '/conformance/mcp/README.md');
        self::assertStringContainsString('official MCP scenario compatibility', $readme);
        self::assertDoesNotMatchRegularExpression('/\\bTier\\s*1\\b/i', $readme);
        self::assertDoesNotMatchRegularExpression('/\\bMCP certification\\b/i', $readme);
    }
}

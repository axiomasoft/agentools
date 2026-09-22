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
        self::assertSame('mcp-applicability/v2', $matrix['schema_version']);
        self::assertSame('official MCP scenario compatibility', $matrix['summary_phrase']);
        self::assertSame('@modelcontextprotocol/conformance@0.2.0-alpha.11', $matrix['conformance_package']);
        self::assertSame(32, $matrix['frozen_required_client_total']);
        $applicable = [];
        foreach ($matrix['rows'] as $row) {
            self::assertNotEmpty($row['reason']);
            self::assertContains($row['applicability'], ['applicable', 'non_applicable', 'blocked_dependency']);
            self::assertContains($row['execution_status'], ['pending', 'passed', 'failed', 'not_run']);
            if ($row['applicability'] === 'applicable') {
                self::assertNotEmpty($row['receipt_path']);
                $applicable[] = $row['scenario_id'];
            }
        }
        sort($applicable);
        self::assertSame([
            'http-custom-headers',
            'http-invalid-tool-headers',
            'http-standard-headers',
            'json-schema-ref-no-deref',
            'request-metadata',
            'sep-2322-client-request-state',
            'tools_call',
        ], $applicable);
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

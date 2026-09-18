<?php

declare(strict_types=1);

namespace AgenTools\Tests\Schema;

use AgenTools\Schema\PublishedSchemaValidator;
use AgenTools\Schema\SchemaValidationException;
use PHPUnit\Framework\TestCase;

final class PublishedSchemaValidatorTest extends TestCase
{
    /** IDE-12; the idempotency schema resolves error.json locally without network access. */
    public function testItValidatesAValidPublishedIdempotencyDocument(): void
    {
        $validator = new PublishedSchemaValidator(__DIR__ . '/../../../spec/revisions/2026-08/schema');
        $validator->assertValid([
            'key' => 'at1:Abcdefgh',
            'state' => 'duplicate_completed',
            'args_fingerprint' => 'sha-256:' . str_repeat('a', 64),
            'stored_error' => [
                'code' => 'agentools.validation.idempotency_key_missing',
                'category' => 'validation',
                'retryable' => false,
                'title' => 'Ключ не передан',
            ],
            'created_at' => '2026-08-03T09:15:01.118Z',
            'expires_at' => '2026-08-10T09:15:01.118Z',
        ], 'https://agentools.dev/schema/2026-08/idempotency.json');

        self::addToAssertionCount(1);
    }

    /** IDE-23; invalid schema data exposes both the failed rule and the data path. */
    public function testItReportsThePathAndRuleForInvalidPublishedData(): void
    {
        $validator = new PublishedSchemaValidator(__DIR__ . '/../../../spec/revisions/2026-08/schema');

        try {
            $validator->assertValid([
                'key' => 'wrong',
                'state' => 'new',
                'args_fingerprint' => 'sha-256:' . str_repeat('a', 64),
                'created_at' => '2026-08-03T09:15:01.118Z',
                'expires_at' => '2026-08-10T09:15:01.118Z',
            ], 'https://agentools.dev/schema/2026-08/idempotency.json');
            self::fail('Invalid data was accepted.');
        } catch (SchemaValidationException $exception) {
            self::assertSame(['key'], $exception->path);
            self::assertSame('pattern', $exception->rule);
        }
    }
}

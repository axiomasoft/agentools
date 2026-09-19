<?php

declare(strict_types=1);

namespace AgenTools\Tests\Reference\Adapters;

use AgenTools\Error\ToolExecutionResult;
use AgenTools\Reference\Adapters\RegistryToolPort;
use AgenTools\Tool\ToolDescriptor;
use PHPUnit\Framework\TestCase;

final class RegistryToolPortTest extends TestCase
{
    /** INT-002 §2.2; discover and invoke succeed for permitted tools. */
    public function testItDiscoversAndInvokesPermittedTools(): void
    {
        $port = new RegistryToolPort();
        $port->register($this->descriptor(['demo.read']), static fn (): ToolExecutionResult => ToolExecutionResult::success(['ok' => true]));
        $port->bindSubject($this->principals(), $this->authorization(['demo.read']));

        self::assertCount(1, $port->discover());
        $result = $port->call('demo_read_note', [], 'idem-1');

        self::assertFalse($result->isError());
        self::assertSame(['ok' => true], $result->value);
    }

    /** Negative 2/4: tools outside the permission set are absent from the catalog. */
    public function testItHidesToolsFromCatalogWhenPermissionMissing(): void
    {
        $port = new RegistryToolPort();
        $port->register($this->descriptor(['demo.write']), static fn (): ToolExecutionResult => ToolExecutionResult::success([]));
        $port->bindSubject($this->principals(), $this->authorization(['demo.read']));

        self::assertSame([], $port->discover());
    }

    /** Negative 3/4: direct invoke is rejected even when the tool name is known. */
    public function testItRejectsDirectInvokeWithoutPermission(): void
    {
        $port = new RegistryToolPort();
        $port->register($this->descriptor(['demo.write']), static fn (): ToolExecutionResult => ToolExecutionResult::success([]));
        $port->bindSubject($this->principals(), $this->authorization(['demo.read']));

        $result = $port->call('demo_read_note', [], 'idem-2');

        self::assertTrue($result->isError());
        self::assertSame('agentools.permission.tool_not_allowed', $result->error?->code);
    }

    /** INT-002 §2.2; subject binding is mandatory before invocation. */
    public function testItRejectsInvocationWithoutSubject(): void
    {
        $port = new RegistryToolPort();
        $port->register($this->descriptor(['demo.read']), static fn (): ToolExecutionResult => ToolExecutionResult::success([]));

        $result = $port->call('demo_read_note', [], 'idem-3');

        self::assertTrue($result->isError());
        self::assertSame('agentools.permission.missing_subject', $result->error?->code);
    }

    /**
     * @param list<string> $permissionsHint
     */
    private function descriptor(array $permissionsHint): ToolDescriptor
    {
        return ToolDescriptor::fromArray([
            'name' => 'demo_read_note',
            'description' => "Purpose: Reads a note.\nCall when: A note id is known.\nRequired arguments: note_id\nExample: demo_read_note({\"note_id\":\"n-1\"})",
            'inputSchema' => ['type' => 'object', 'properties' => ['note_id' => ['type' => 'string']]],
            'annotations' => [
                'readOnlyHint' => true,
                'destructiveHint' => false,
                'idempotentHint' => true,
                'openWorldHint' => false,
            ],
            '_meta' => ['dev.agentools/action' => [
                'side_effect' => 'read',
                'permissions_hint' => $permissionsHint,
            ]],
        ]);
    }

    /** @return array<string, mixed> */
    private function principals(): array
    {
        return [
            'initiator' => ['id' => 'user-1'],
            'agent' => ['id' => 'agent-9'],
        ];
    }

    /**
     * @param list<string> $permissions
     *
     * @return array<string, mixed>
     */
    private function authorization(array $permissions): array
    {
        return [
            'resource' => 'tenant-a',
            'tool' => 'demo_read_note',
            'permissions' => $permissions,
        ];
    }
}

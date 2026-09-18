<?php

declare(strict_types=1);

namespace AgenTools\Tests\Ops;

use AgenTools\Action\Actor;
use AgenTools\Ops\PrincipalTriple;
use PHPUnit\Framework\TestCase;

final class PrincipalTripleTest extends TestCase
{
    /** SEC-13; merged user and agent principals are rejected. */
    public function testItRejectsMergedUserAndAgentPrincipals(): void
    {
        $failure = (new PrincipalTriple())->validate(
            [
                'initiator' => ['id' => 'user-7'],
                'agent' => ['id' => 'user-7'],
                'authorizer' => ['id' => 'approver-7'],
            ],
            ['resource' => 'report-7', 'tool' => 'demo_probe', 'permissions' => ['demo.read']],
        );

        self::assertSame('merged_user_and_agent', $failure);
    }

    /** SEC-14, SEC-16; valid triple is carried by actor plus payload scope fields. */
    public function testItBuildsDistinctPrincipalTriple(): void
    {
        $record = (new PrincipalTriple())->buildInvocationRecord(
            [
                'initiator' => ['id' => 'user-7'],
                'agent' => ['id' => 'agent-7'],
                'authorizer' => ['id' => 'approver-7'],
            ],
            ['resource' => 'report-7', 'tool' => 'demo_probe', 'permissions' => ['demo.read']],
        );

        self::assertSame('agent', $record['actor']->type);
        self::assertSame('agent-7', $record['actor']->id);
        self::assertSame('user-7', $record['payload']['end_user_id']);
        self::assertSame('report-7', $record['payload']['authorization_scope']);
    }

    /** SEC-15; runtime accounts must not stand in for the end user. */
    public function testItRejectsRuntimeAccountAsEndUser(): void
    {
        $failure = (new PrincipalTriple())->validate(
            [
                'initiator' => ['id' => 'runtime-worker-1'],
                'agent' => ['id' => 'agent-7'],
                'authorizer' => ['id' => 'approver-7'],
            ],
            ['resource' => 'report-7', 'tool' => 'demo_probe', 'permissions' => ['demo.read']],
        );

        self::assertSame('runtime_account_as_end_user', $failure);
    }

    /** SEC-14; agent principal must use actor.type = agent. */
    public function testItRequiresAgentActorCarrier(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new PrincipalTriple())->assertAgentActor(new Actor('user', 'user-7'));
    }
}

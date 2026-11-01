<?php

declare(strict_types=1);

namespace AgenToolsExamples\History;

use AgenToolsPlatform\Core\Common\Ref;
use AgenToolsPlatform\Core\Context\History\{HistoryPage,HistorySource,MessageRead,MessageRef,SourceManifest};
use AgenToolsPlatform\Core\Security\{ExecutionContext,HostAuthorization};

/** Host Messenger/Chatom composition supplies exact permission-filtered source views. */
interface MessengerHistoryPort
{
    public function recent(ExecutionContext $context,Ref $branch,int $afterTurn,int $limit): HistoryPage;
    public function manifest(ExecutionContext $context,Ref $branch,int $throughTurn): SourceManifest;
    public function read(ExecutionContext $context,MessageRef $ref): MessageRead;
}

/** Example only. No Chatom implementation, transcript cache or external readiness claim. */
final readonly class ExternalHistoryAdapter implements HistorySource
{
    public function __construct(private MessengerHistoryPort $owner,private HostAuthorization $authorization) {}
    private function guard(ExecutionContext $context,Ref $branch): void
    { $context->session->tenant->assertSame($branch->tenant);$context->assertCurrent($this->authorization); }
    public function recent(ExecutionContext $context,Ref $branch,int $afterTurn,int $limit): HistoryPage
    {
        $this->guard($context,$branch);
        if ($limit<1 || $limit>32) { throw new \InvalidArgumentException('Bounded owner query required'); }
        return $this->owner->recent($context,$branch,$afterTurn,$limit);
    }
    public function manifest(ExecutionContext $context,Ref $branch,int $throughTurn): SourceManifest
    { $this->guard($context,$branch);return $this->owner->manifest($context,$branch,$throughTurn); }
    public function read(ExecutionContext $context,MessageRef $ref): MessageRead
    { $this->guard($context,$ref->branch);return $this->owner->read($context,$ref); }
}

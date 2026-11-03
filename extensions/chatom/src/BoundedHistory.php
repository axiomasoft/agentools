<?php

declare(strict_types=1);

namespace AgenToolsPlatform\ChatomBridge;

use AgenToolsPlatform\Core\Common\{ContractViolation, ErrorCode, Ref};
use AgenToolsPlatform\Core\Context\History\{ExactMessage, HistoryPage, HistorySource, MessageRead, MessageRef, SourceManifest};
use AgenToolsPlatform\Core\Security\{BoundaryClock, ExecutionContext, HostAuthorization};

/** Decorates the existing NativeHistory (or another host's public HistorySource); no transcript store. */
final readonly class BoundedHistory implements HistorySource
{
    public function __construct(private HistorySource $owner, private HostAuthorization $authorization, private BoundaryClock $clock) {}
    public function recent(ExecutionContext $context, Ref $branch, int $afterTurn, int $limit): HistoryPage
    {
        $this->authorize($context, $branch);
        if ($afterTurn < 0 || $limit < 1 || $limit > 32) { throw new ContractViolation(ErrorCode::InvalidInput); }
        $page = $this->owner->recent($context, $branch, $afterTurn, $limit);
        $this->authorize($context, $branch);
        $this->validateMessages($page->messages, $limit);
        $previous = $afterTurn; $seen = [];
        foreach ($page->messages as $message) {
            $this->exact($branch, $message);
            if ($message->source->turn <= $previous || isset($seen[$message->source->ref->value])) {
                throw new ContractViolation(ErrorCode::IntegrityConflict);
            }
            $seen[$message->source->ref->value] = true; $previous = $message->source->turn;
        }
        return $page;
    }
    public function manifest(ExecutionContext $context, Ref $branch, int $throughTurn): SourceManifest
    {
        $this->authorize($context, $branch);
        if ($throughTurn < 0) { throw new ContractViolation(ErrorCode::InvalidInput); }
        $manifest = $this->owner->manifest($context, $branch, $throughTurn);
        $this->authorize($context, $branch); $branch->assertSame($manifest->branch);
        if ($manifest->throughTurn !== $throughTurn) { throw new ContractViolation(ErrorCode::IntegrityConflict); }
        return $manifest;
    }
    public function read(ExecutionContext $context, MessageRef $ref): MessageRead
    {
        $this->authorize($context, $ref->branch);
        $read = $this->owner->read($context, $ref);
        $this->authorize($context, $ref->branch);
        if ($read->message !== null) {
            $this->exact($ref->branch, $read->message);
            if ($read->message->source->toArray() !== $ref->toArray()) { throw new ContractViolation(ErrorCode::StaleContext); }
        }
        return $read;
    }
    private function authorize(ExecutionContext $context, Ref $branch): void
    {
        $context->identity->tenant->assertSame($branch->tenant);
        $context->assertCurrent($this->authorization);
    }
    /** @param array<array-key, ExactMessage> $messages */
    private function validateMessages(array $messages, int $limit): void
    {
        if (count($messages) > $limit || !array_is_list($messages)) { throw new ContractViolation(ErrorCode::IntegrityConflict); }
    }
    private function exact(Ref $branch, ExactMessage $message): void
    {
        $branch->assertSame($message->source->branch);
        if (strlen($message->text) > 65536
            || new \DateTimeImmutable($message->expiresAt->value) <= new \DateTimeImmutable($this->clock->now()->value)) {
            throw new ContractViolation(ErrorCode::AccessDenied);
        }
    }
}

<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Chatom;

use AgenToolsPlatform\Core\Common\CanonicalHasher;
use AgenToolsPlatform\Core\Common\ContractViolation;
use AgenToolsPlatform\Core\Common\ErrorCode;
use AgenToolsPlatform\Core\Common\Ref;
use AgenToolsPlatform\Core\Common\UtcInstant;
use AgenToolsPlatform\Core\Context\Classification;
use AgenToolsPlatform\Core\Context\History\ExactMessage;
use AgenToolsPlatform\Core\Context\History\HistoryPage;
use AgenToolsPlatform\Core\Context\History\HistorySource;
use AgenToolsPlatform\Core\Context\History\MessageRead;
use AgenToolsPlatform\Core\Context\History\MessageRef;
use AgenToolsPlatform\Core\Context\History\SourceManifest;
use AgenToolsPlatform\Core\Context\History\SourceStatus;
use AgenToolsPlatform\Core\Security\ExecutionContext;
use AgenToolsPlatform\Core\Security\HostAuthorization;
use Chatom\Messages\Contracts\MessageSnapshotReader;
use Chatom\Messages\Contracts\ViewerMessageReader;
use Chatom\Messages\Data\MessageId;
use Chatom\Messages\Data\MessageView;
use Chatom\Messages\Data\ViewerMessageHistory;
use Chatom\Messages\Enums\MessageHistoryOrder;
use Chatom\Messages\Enums\MessageType;
use Closure;
use InvalidArgumentException;

/** Reads the real viewer projection; raw snapshots only recheck an already authorized version. */
final readonly class NativeHistory implements HistorySource
{
    /** @param Closure(ExecutionContext):TranscriptBinding $binding */
    public function __construct(private ViewerMessageReader $reader, private MessageSnapshotReader $snapshots,
        private HostAuthorization $authorization, private CanonicalHasher $hasher, private Closure $binding,
        private UtcInstant $expiresAt, private int $maximum = 4096)
    {
        if ($maximum < 1 || $maximum > 4096) {
            throw new InvalidArgumentException('Bounded owner coverage required');
        }
    }

    public function recent(ExecutionContext $context, Ref $branch, int $afterTurn, int $limit): HistoryPage
    {
        if ($afterTurn < 0 || $limit < 1 || $limit > 32) {
            throw new ContractViolation(ErrorCode::InvalidInput);
        }
        [$messages, $complete] = $this->scan($context, $branch, $afterTurn, $limit + 1);
        $more = count($messages) > $limit || ! $complete;
        $messages = array_slice($messages, 0, $limit);
        usort($messages, static fn (ExactMessage $a, ExactMessage $b): int => $a->source->turn <=> $b->source->turn);

        return new HistoryPage(SourceStatus::Available, $messages, $more);
    }

    public function manifest(ExecutionContext $context, Ref $branch, int $throughTurn): SourceManifest
    {
        if ($throughTurn < 0) {
            throw new ContractViolation(ErrorCode::InvalidInput);
        }
        [$messages, $complete] = $this->scan($context, $branch, 0, $this->maximum);
        $refs = array_map(static fn (ExactMessage $m): MessageRef => $m->source,
            array_values(array_filter($messages, static fn (ExactMessage $m): bool => $m->source->turn <= $throughTurn)));
        usort($refs, static fn (MessageRef $a, MessageRef $b): int => $a->turn <=> $b->turn);

        return new SourceManifest($branch, $throughTurn, SourceStatus::Available, $complete, $refs, $this->hasher);
    }

    public function read(ExecutionContext $context, MessageRef $ref): MessageRead
    {
        [$messages] = $this->scan($context, $ref->branch, 0, $this->maximum);
        foreach ($messages as $message) {
            if ($message->source->ref->value !== $ref->ref->value) {
                continue;
            }

            if ($message->source->toArray() !== $ref->toArray()) {
                return new MessageRead(SourceStatus::Revoked);
            }

            return new MessageRead(SourceStatus::Available, $message);
        }

        return new MessageRead(SourceStatus::Unavailable);
    }

    public function current(ExecutionContext $context, Ref $branch, string $message, int $revision): ExactMessage
    {
        return $this->currentMany($context, $branch, [$message => (string) $revision])[$message];
    }

    /** @param array<string,string> $versions Native message ID => exact version.
     * @return array<string,ExactMessage> Every version is rechecked after the final owner IO of this batch.
     */
    public function currentMany(ExecutionContext $context, Ref $branch, array $versions): array
    {
        [$messages] = $this->scan($context, $branch, 0, $this->maximum);
        $found = [];
        foreach ($messages as $message) {
            $id = substr($message->source->ref->value, strlen('chatom:message:'));

            if (isset($versions[$id]) && $versions[$id] === $message->source->version) {
                $found[$id] = $message;
            }
        }

        if (count($found) !== count($versions)) {
            throw new ContractViolation(ErrorCode::StaleContext);
        }

        return $found;
    }

    /** @return array{list<ExactMessage>, bool} newest first; complete covers the requested lower boundary */
    private function scan(ExecutionContext $context, Ref $branch, int $after, int $maximum): array
    {
        $binding = $this->currentBinding($context, $branch);
        $cursor = null;
        $messages = [];
        $read = 0;
        do {
            $page = $this->reader->history(new ViewerMessageHistory($binding->space, $binding->channel,
                $binding->viewer, MessageHistoryOrder::PositionDescending, $cursor, min(100, $this->maximum - $read)));
            $this->assertBinding($binding, $this->currentBinding($context, $branch));
            foreach ($page->items as $index => $view) {
                $read++;

                if ($view->position <= $after) {
                    $complete = true;

                    break 2;
                }

                if ($view->type !== MessageType::Regular || $view->status->isHiddenFromStream()) {
                    continue;
                }
                $messages[] = $this->exact($binding, $view);

                if (count($messages) >= $maximum || $read >= $this->maximum) {
                    $complete = ! $page->hasMore() && $index === count($page->items) - 1;

                    break 2;
                }
            }
            $cursor = $page->nextCursor;
            $complete = $cursor === null;
        } while (! $complete && $read < $this->maximum);
        // Each page already performed its post-IO binding check; only pure mapping happened since then.
        // Remote host checks above may overlap hide/edit/delete. Recheck every exact version after that IO.
        foreach ($messages as $message) {
            $id = substr($message->source->ref->value, strlen('chatom:message:'));
            $saved = $this->snapshots->find($binding->space, MessageId::from($id));

            if ($saved === null || $saved->channel->value !== $binding->channel->value
                || $saved->revision !== (int) $message->source->version || $saved->position !== $message->source->turn
                || $saved->type !== MessageType::Regular || $saved->status->isHiddenFromStream()) {
                throw new ContractViolation(ErrorCode::StaleContext);
            }
        }

        return [$messages, $complete];
    }

    private function currentBinding(ExecutionContext $context, Ref $branch): TranscriptBinding
    {
        $context->assertCurrent($this->authorization);
        $binding = ($this->binding)($context);
        $branch->assertSame($binding->branch);
        $context->session->tenant->assertSame($binding->branch->tenant);

        if ($context->session->value !== $binding->branch->value) {
            throw new ContractViolation(ErrorCode::AccessDenied);
        }

        if ($binding->viewer->id !== $context->identity->actorRef->value || $binding->viewer->type !== 'account') {
            throw new ContractViolation(ErrorCode::AccessDenied);
        }

        return $binding;
    }

    private function assertBinding(TranscriptBinding $a, TranscriptBinding $b): void
    {
        if ($a->branch->toArray() !== $b->branch->toArray() || $a->channel->value !== $b->channel->value
            || $a->space->value !== $b->space->value || ! $a->viewer->equals($b->viewer)
            || $a->assistant !== $b->assistant || $a->nativeTenant !== $b->nativeTenant) {
            throw new ContractViolation(ErrorCode::StaleContext);
        }
    }

    private function exact(TranscriptBinding $binding, MessageView $view): ExactMessage
    {
        if ($view->channel->value !== $binding->channel->value || $view->position < 1) {
            throw new ContractViolation(ErrorCode::IntegrityConflict);
        }
        $ref = new MessageRef(new Ref($binding->branch->tenant, 'chatom:message:'.$view->id->value),
            $binding->branch, $view->position, (string) $view->revision,
            $this->hasher->hash($binding->branch->tenant, 'history-message', $view->body));

        return new ExactMessage($ref, $binding->branch, $view->body, Classification::Private, $this->expiresAt, $this->hasher);
    }
}

<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Loqum\Delivery;

use AgenToolsPlatform\Core\Common\Ref;
use AgenToolsPlatform\Core\Security\{DisclosurePayload, DisclosurePolicy, HostAuthorization};
use AgenToolsPlatform\Loqum\Ingress\MediaState;

/** Authenticated host/media ports. Every IO rechecks its own current ACL, consent and epoch fence. */
interface Owner extends HostAuthorization
{
    public function assertCurrent(Response $response, string $operation): void;
    /** Authenticate current scoped termination authority even if ordinary purpose/consent grants were revoked. */
    public function authorizeStop(Response $response): void;
    public function media(Response $response): MediaState;
    public function policy(Response $response): DisclosurePolicy;
    /** Only a completed DisclosureStream checked payload or explicitly approved live payload. */
    public function payload(Segment $segment, string $channel): DisclosurePayload;
    /** Idempotent on attempt key+segment hash. Synthesize only the suffix starting at offset. */
    public function synthesize(Attempt $attempt): Ref;
    public function play(Attempt $attempt, Ref $artifact): ?PlayoutReceipt;
    public function status(Attempt $attempt): Lookup;
    /** Authenticated receipt lookup; the caller supplies only an opaque handle. */
    public function receipt(Segment $segment,string $handle): PlayoutReceipt;
    /** Same segment key+hash on retry; null is unknown, never evidence of zero display. */
    public function display(Segment $segment): ?DisplayReceipt;
    /** Closed command, current checks and same-ID dedup at the actual client boundary. */
    public function command(UiCommand $command): Ref;
    /** Idempotent and capability-aware: stop media/TTS/generation only, never cancel Actions. */
    public function stop(Response $response, int $epoch): void;
    /** Stop prohibited recording/memory/export and apply owner retention policy; return its durable receipt. */
    public function revoke(Response $response): Ref;
}

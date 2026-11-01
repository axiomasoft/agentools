<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Laravel\Managed;

use AgenToolsPlatform\Core\Actions\ActionView;
use AgenToolsPlatform\Core\Actions\Confirmation\Preview;
use AgenToolsPlatform\Core\Common\Ref;
use AgenToolsPlatform\Core\Profiles\BundleRef;
use AgenToolsPlatform\Core\Runtime\InvocationCheckpoint;
use AgenToolsPlatform\Core\Security\TrustedInput;

/** Trusted code-only host ports. No method accepts an allow/skip/classifier/plugin grant. */
interface HostAdmission
{
    /** Authenticate the current request or job principal before resolving the handle. */
    public function authenticate(Input $input): Ref;
    /** Rehydrate current tenant/session/actor/purpose from host-owned state. */
    public function trusted(Input $input): TrustedInput;
    /** Resolve immutable profile pins and check CURRENT data/source ACL, consent and purpose. */
    public function profileData(TrustedInput $input): BundleRef;
    /** Persist/recover intake and compile bounded work with stable source/hash identities. */
    public function compile(TrustedInput $input, BundleRef $bundle, Entry $entry): Work;
    /** Check current deployment/emergency policy; direct Actions apply their owner policy. */
    public function deployment(TrustedInput $input, Work $work): void;
    /** Full buffered disclosure/revocation check; return only an authorized immutable payload ref. */
    public function disclose(TrustedInput $input, InvocationCheckpoint|ActionView|Preview $result): Ref;
    /** Durable same-key delivery/outbox; CURRENT disclosure authority must be checked at actual IO. */
    public function deliver(TrustedInput $input, Ref $checked, Entry $entry): DeliveryReceipt;
    /** Record ids/hashes/codes and host/Turn/Run trace refs, never payloads or exception messages. */
    public function decision(TrustedInput $input, string $stage, string $code): Ref;
    /** Clear actor, credentials, profile, policy, retrieval/diagnostic caches and payload handles. */
    public function reset(): void;
}

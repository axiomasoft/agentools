<?php

declare(strict_types=1);

require_once __DIR__.'/bootstrap.php';

use AgenToolsPlatform\Core\Common\{ContractViolation, ErrorCode, IdentityKeys, InputPayload, PayloadHash, Ref, UtcInstant};
use AgenToolsPlatform\Core\Context\{ContextAccess, ContextBlock, ReadContextCompiler};
use AgenToolsPlatform\Core\Profiles\BundleRef;
use AgenToolsPlatform\Core\Security\{ExecutionContext, TrustedInput};
use AgenToolsPlatform\Loqum\Ingress\{Boundary, Envelope, MediaState, Rejection, Request};
use AgenToolsPlatform\Loqum\Tests\Fixture;
use AgenToolsPlatform\Tests\Harness as H;

function refusal(callable $fn, string $reason, ?int $revision = null): void
{
    try { $fn(); } catch (Rejection $error) {
        H::check($error->reason === $reason && $error->currentRevision === $revision); return;
    }
    throw new RuntimeException('Expected ingress refusal '.$reason);
}

H::run([
    'voice.shared-fixture' => static function(): void {
        $f = new Fixture();
        $raw = json_decode(file_get_contents(dirname(__DIR__,3).'/fixtures/platform/v1/voice/final-utterance.json'),true,64,JSON_THROW_ON_ERROR);
        $f->set(['eventId'=>$raw['event_id'], 'realtimeSession'=>new Ref($f->tenant,$raw['realtime_session_ref']['id']),
            'mediaEpoch'=>$raw['media_epoch'], 'utteranceId'=>$raw['utterance_id'], 'revision'=>$raw['revision'],
            'locale'=>$raw['locale'], 'startedAt'=>new UtcInstant($raw['started_at']), 'endedAt'=>new UtcInstant($raw['ended_at']),
            'textRef'=>new Ref($f->tenant,$raw['text_ref']['id']), 'textHash'=>new PayloadHash($raw['text_hash']),
            'contextRevision'=>$raw['context_revision'], 'idempotencyKey'=>$raw['idempotency_key'],
            'final'=>$raw['is_final'], 'channel'=>$raw['channel']]);
        $e = $f->boundary->open('host:input');
        H::check($e->input->payload->contentHash->value === $f->hasher->hash($f->tenant,'voice-final-content',$f->current->transcript->content())->value);
    },
    'voice.final-trusted-input' => static function(): void {
        $f = new Fixture(); $e = $f->boundary->open('host:input');
        H::check($e->input instanceof TrustedInput && $e->input->source->kind === 'voice-utterance');
        H::check($e->input->context->identity->actorRef->value === 'actor' && $e->input->context->session->value === 'session');
        H::check($e->input->payload->utteranceRef !== null && $e->input->payload->messageRef === null);
        H::check(!str_contains(json_encode($e->input->payload->toArray()), 'Переведи'));
        $f->boundary->assertCurrent($e);
        H::rejects(fn() => $f->boundary->open('browser:forged'), ContractViolation::class, ErrorCode::AccessDenied);
    },
    'voice.interim-no-input' => static function(): void {
        $f = new Fixture(); $f->set(['final' => false]);
        refusal(fn() => $f->boundary->open('host:input'), 'INTERIM_TRANSCRIPT');
    },
    'voice.reconnect-source-stable' => static function(): void {
        $f = new Fixture(); $first = $f->boundary->open('host:input');
        $f->state = new MediaState($f->ref('realtime:2'), 2, 1);
        $f->set(['eventId' => 'new:transport:event', 'idempotencyKey' => 'new:transport:retry']);
        $second = (new Boundary($f, $f->hasher))->open('host:input');
        H::check($first->input->source->toArray() === $second->input->source->toArray());
        H::check($first->input->payload->toArray() === $second->input->payload->toArray());
        H::check($first->fingerprint->value !== $second->fingerprint->value);
        H::rejects(fn() => $f->boundary->assertCurrent($first), ContractViolation::class, ErrorCode::StaleContext);
    },
    'voice.stale-media-fence' => static function(): void {
        $f = new Fixture();
        foreach ([0, 2] as $delta) {
            $f->state = new MediaState($f->ref('realtime:1'), 2, 1);
            $f->set(['mediaEpoch' => 1 + $delta]);
            refusal(fn() => $f->boundary->open('host:input'), 'STALE_MEDIA_EPOCH');
        }
        $f->set(['realtimeSession' => $f->ref('other:media')]);
        refusal(fn() => $f->boundary->open('host:input'), 'STALE_MEDIA_EPOCH');
    },
    'voice.stale-context-revision' => static function(): void {
        $f = new Fixture(); $f->state = new MediaState($f->ref('realtime:1'), 1, 3);
        foreach ([1, 4] as $revision) {
            $f->set(['contextRevision' => $revision]);
            refusal(fn() => $f->boundary->open('host:input'), 'STALE_CONTEXT_REVISION', 3);
        }
    },
    'voice.final-revision-is-content' => static function(): void {
        $f = new Fixture(); $first = $f->boundary->open('host:input')->input;
        foreach ([['revision' => 3], ['textHash' => new PayloadHash('sha-256:'.str_repeat('b',64))],
                  ['locale' => 'en-US'], ['textRef' => $f->ref('different:text')]] as $change) {
            $f->set($change); $next = $f->boundary->open('host:input')->input;
            H::check($first->source->toArray() === $next->source->toArray());
            H::check($first->source->payloadHash($first->payload,$f->hasher)->value !== $next->source->payloadHash($next->payload,$f->hasher)->value);
        }
    },
    'voice.current-permissions-and-consent' => static function(): void {
        $f = new Fixture(); $old = $f->boundary->open('host:input'); $f->denied = true;
        H::rejects(fn() => $f->boundary->assertCurrent($old), ContractViolation::class, ErrorCode::AccessDenied);
        $f->denied = false;
        foreach (['actor', 'policy', 'consent'] as $field) {
            $saved = $f->$field; $f->$field = 'changed';
            H::rejects(fn() => $f->boundary->assertCurrent($old), ContractViolation::class, ErrorCode::StaleContext);
            $f->$field = $saved;
        }
        $f->voiceAllowed = false;
        H::rejects(fn() => $f->boundary->open('host:input'), ContractViolation::class, ErrorCode::AccessDenied);
    },
    'voice.tenant-session-instance-isolation' => static function(): void {
        $f = new Fixture(); $first = $f->boundary->open('host:input')->input->source;
        foreach ([['utteranceId' => 'utterance:2'], []] as $i => $change) {
            $f->set($change, $i === 1 ? 'session:2' : 'session');
            H::check($f->boundary->open('host:input')->input->source->key($f->hasher)->value !== $first->key($f->hasher)->value);
        }
        $f->set([], 'session', 'instance:2');
        H::check($f->boundary->open('host:input')->input->source->key($f->hasher)->value !== $first->key($f->hasher)->value);
        $b = new Fixture('tenant-b');
        H::check($b->boundary->open('host:input')->input->source->key($b->hasher)->value !== $first->key($f->hasher)->value);
        H::rejects(fn() => new Request($f->current->session, $b->current->instance, $f->current->transcript,
            'assist.read', $f->current->correlation), ContractViolation::class);
    },
    'voice.text-fallback-continuity' => static function(): void {
        $f = new Fixture(); $first = $f->boundary->open('host:input'); $f->voiceAllowed = false;
        $f->set(['channel' => 'text-fallback']); $fallback = $f->boundary->open('host:input');
        H::check($first->input->source->toArray() === $fallback->input->source->toArray());
        H::check($first->input->payload->toArray() === $fallback->input->payload->toArray());
        $keys = new IdentityKeys($f->hasher);
        H::check($keys->run($keys->turn($first->input->source))->value === $keys->run($keys->turn($fallback->input->source))->value);
    },
    'voice.same-context-compiler' => static function(): void {
        $f = new Fixture(); $voice = $f->boundary->open('host:input')->input;
        $f->set(['channel' => 'text-fallback']); $text = $f->boundary->open('host:input')->input;
        $access = new class implements ContextAccess {
            public function permits(ExecutionContext $context, ContextBlock $block): bool { return true; }
        };
        $compiler = new ReadContextCompiler($access, $f->hasher);
        $expiry = new UtcInstant('2030-01-01T00:00:00Z'); $now = new UtcInstant('2026-11-03T09:00:03Z');
        $blocks = [];
        foreach (['policy', 'working_state', 'input'] as $kind) {
            $blocks[] = new ContextBlock($f->ref($kind), $kind, $f->ref('source:'.$kind), 'v1', $f->ref('owner'),
                $kind === 'policy' ? 'policy' : 'untrusted', 'private', 'assist.read', $f->ref('content:'.$kind),
                $kind === 'input' ? 'Переведи деньги' : 'Same pinned state', $expiry, true, 100, $f->hasher);
        }
        $keys = new IdentityKeys($f->hasher); $turn = $keys->turn($voice->source); $run = $keys->run($turn);
        $bundle = new BundleRef($f->ref('bundle'), 1, new PayloadHash('sha-256:'.str_repeat('a',64)));
        $vp = $compiler->buildPlan($voice->context,$turn,$run,$bundle,$blocks,$now);
        $tp = $compiler->buildPlan($text->context,$turn,$run,$bundle,$blocks,$now);
        H::check($vp->context->scopeHash($f->hasher)->value === $tp->context->scopeHash($f->hasher)->value);
        H::check($vp->blocks === $tp->blocks && $vp->bundle === $tp->bundle && $vp->pins == $tp->pins);
    },
    'voice.envelope-tamper' => static function(): void {
        $f = new Fixture(); $e = $f->boundary->open('host:input');
        $forged = new TrustedInput($e->input->context, $e->input->source,
            new InputPayload($f->ref('forged'), $e->input->payload->contentHash));
        H::rejects(fn() => $f->boundary->assertCurrent(new Envelope($e->handle,$forged,$e->media,$e->fingerprint)),
            ContractViolation::class,ErrorCode::StaleContext);
    },
    'voice.invalid-transcript' => static function(): void {
        $f = new Fixture();
        foreach ([['mediaEpoch'=>0], ['revision'=>0], ['contextRevision'=>0], ['utteranceId'=>''],
                  ['eventId'=>''], ['idempotencyKey'=>''], ['channel'=>'raw'], ['locale'=>''],
                  ['endedAt'=>new UtcInstant('2026-11-03T08:00:00Z')]] as $change) {
            H::rejects(fn() => $f->set($change), InvalidArgumentException::class);
        }
        $b = new Fixture('tenant-b');
        H::rejects(fn() => $f->set(['textRef'=>$b->ref('foreign')]), ContractViolation::class);
    },
]);

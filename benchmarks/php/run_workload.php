#!/usr/bin/env php
<?php

declare(strict_types=1);

use AgenTools\Confirmation\ConfirmationFailure;
use AgenTools\Confirmation\KeyId;
use AgenTools\Confirmation\SigningKey;
use AgenTools\Confirmation\TokenEnvelope;
use AgenTools\Contracts\ToolPort;
use AgenTools\Action\ActionReducer;
use AgenTools\Action\Actor;
use AgenTools\Error\EventStoreFailure;
use AgenTools\Error\ToolExecutionResult;
use AgenTools\Idempotency\LeaseDuration;
use AgenTools\Ops\BaggageLimitException;
use AgenTools\Ops\BaggageRefs;
use AgenTools\Persistence\EventStoreSupport;
use AgenTools\Persistence\UncommittedEvent;
use AgenTools\Scenario\ExpressionEvaluator;
use AgenTools\Scenario\ExpressionException;
use AgenTools\Scenario\ScenarioRunner;
use AgenTools\Schema\CanonicalizationException;
use AgenTools\Schema\Canonicalizer;
use AgenTools\TestKit\InMemoryEventStore;
use AgenTools\TestKit\StaticTokenKeyProvider;
use AgenTools\Tool\ToolDescriptor;

$phpRoot = getenv('AGENTOOLS_PHP_ROOT');
$repoPhp = dirname(__DIR__, 2) . '/php';
$autoload = $repoPhp . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "missing php/vendor/autoload.php\n");
    exit(2);
}
require $autoload;
if (is_string($phpRoot) && $phpRoot !== '') {
    $override = rtrim($phpRoot, '/');
    spl_autoload_register(static function (string $class) use ($override): void {
        $prefixes = [
            'AgenTools\\TestKit\\' => $override . '/testkit/',
            'AgenTools\\Reference\\' => $override . '/reference/',
            'AgenTools\\' => $override . '/src/',
        ];
        foreach ($prefixes as $prefix => $base) {
            if (!str_starts_with($class, $prefix)) {
                continue;
            }
            $path = $base . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($path)) {
                require $path;
            }
            return;
        }
    }, true, true);
}

function read_request(): array
{
    global $argv;
    if (isset($argv[1]) && $argv[1] !== '') {
        return json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR);
    }
    $raw = stream_get_contents(STDIN);
    if ($raw === false || trim($raw) === '') {
        throw new RuntimeException('empty request');
    }

    return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
}

function emit(array $payload): void
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
}

function internal_failure(Throwable $exception): array
{
    return ['ok' => false, 'class' => 'error.internal', 'diagnostic_class' => get_debug_type($exception)];
}

function descriptor_document(): array
{
    return [
        'name' => 'fina_payment_send',
        'title' => 'Отправить платёж',
        'description' => "Purpose: Отправляет платёж во внешнюю систему.\nCall when: Подтверждённый платёж готов к отправке.\nRequired arguments: amount_minor\nExample: fina_payment_send({\"amount_minor\":12500})",
        'inputSchema' => ['type' => 'object', 'properties' => ['amount_minor' => ['type' => 'integer']]],
        'outputSchema' => ['type' => 'object', 'properties' => ['payment_id' => ['type' => 'string']]],
        'annotations' => [
            'readOnlyHint' => false,
            'destructiveHint' => true,
            'idempotentHint' => true,
            'openWorldHint' => true,
        ],
        '_meta' => ['dev.agentools/action' => [
            'side_effect' => 'irreversible',
            'confirmation' => 'double_confirm',
            'idempotent_commit' => true,
            'permissions_hint' => ['fina.payment.send'],
            'content_origin' => 'untrusted_external',
        ]],
    ];
}

function dummy_tools(): ToolPort
{
    return new class implements ToolPort {
        public function call(string $tool, array $arguments, string $idempotencyKey): ToolExecutionResult
        {
            return ToolExecutionResult::success(['ok' => true]);
        }
    };
}

function eval_expression(string $source, array $context = [], array $request = []): array
{
    try {
        $limits = new \AgenTools\Scenario\ExpressionLimits(
            maxDepth: (int) ($request['max_depth'] ?? 32),
            maxLength: (int) ($request['max_length'] ?? 512),
            maxCollectionSize: (int) ($request['max_collection'] ?? 1000),
            maxVisitedNodes: (int) ($request['max_visited'] ?? 10000),
        );
        $value = (new ExpressionEvaluator($limits))->evaluate($source, $context);
        return ['ok' => true, 'class' => 'accept', 'value' => $value];
    } catch (ExpressionException $exception) {
        return $exception->limitName === null
            ? ['ok' => false, 'class' => 'reject.syntax', 'code' => 'expression.syntax']
            : ['ok' => false, 'class' => 'reject.limit', 'code' => $exception->limitName];
    } catch (Throwable $exception) {
        return internal_failure($exception);
    }
}

function eval_baggage(array $ids): array
{
    try {
        $baggage = new BaggageRefs();
        foreach ($ids as $id) {
            if (!is_string($id)) {
                throw new InvalidArgumentException('id');
            }
            $baggage = $baggage->add($id);
        }
        return ['ok' => true, 'class' => 'accept', 'count' => count($baggage->refs())];
    } catch (BaggageLimitException) {
        return ['ok' => false, 'class' => 'reject.limit', 'code' => 'baggage'];
    } catch (InvalidArgumentException) {
        return ['ok' => false, 'class' => 'reject.claim', 'code' => 'baggage.id'];
    } catch (Throwable $exception) {
        return internal_failure($exception);
    }
}

function eval_token(string $token): array
{
    $keys = new StaticTokenKeyProvider(new SigningKey(new KeyId('k1'), 'HS256', str_repeat('k', 32)));
    try {
        TokenEnvelope::parse($token, $keys, new DateTimeImmutable('2026-09-20T11:00:00+00:00'), 'agentools', 'confirmation');
        return ['ok' => true, 'class' => 'accept'];
    } catch (ConfirmationFailure $failure) {
        $code = $failure->error->code ?? 'token';
        if (strlen($token) > TokenEnvelope::MAX_RAW_BYTES) {
            return ['ok' => false, 'class' => 'reject.limit', 'code' => $code];
        }
        if (!str_starts_with($token, 'at1.') || substr_count($token, '.') !== 2) {
            return ['ok' => false, 'class' => 'reject.syntax', 'code' => $code];
        }
        $class = str_contains((string) $code, 'key_denied') || str_contains((string) $code, 'binding_mismatch')
            || str_contains((string) $code, 'token_invalid')
            ? 'reject.signature'
            : 'reject.claim';
        return ['ok' => false, 'class' => $class, 'code' => $code];
    } catch (Throwable $exception) {
        return internal_failure($exception);
    }
}

function eval_jcs(string $json): array
{
    try {
        $bytes = (new Canonicalizer())->canonicalizeJson($json);
        return ['ok' => true, 'class' => 'accept', 'bytes' => $bytes, 'output_bytes' => strlen($bytes)];
    } catch (JsonException|CanonicalizationException|InvalidArgumentException) {
        return ['ok' => false, 'class' => 'reject.syntax', 'code' => 'json.syntax'];
    } catch (Throwable $exception) {
        return internal_failure($exception);
    }
}

function eval_descriptor(): array
{
    try {
        $descriptor = ToolDescriptor::fromArray(descriptor_document());
        $digest = $descriptor->digest(new Canonicalizer());
        return ['ok' => true, 'class' => 'accept', 'digest' => $digest->value];
    } catch (InvalidArgumentException) {
        return ['ok' => false, 'class' => 'reject.claim', 'code' => 'descriptor.claim'];
    } catch (Throwable $exception) {
        return internal_failure($exception);
    }
}

function eval_event_page(int $limit): array
{
    try {
        (new InMemoryEventStore())->load('t', 'a', 0, $limit);
        return ['ok' => true, 'class' => 'accept', 'limit' => $limit];
    } catch (EventStoreFailure $failure) {
        return ['ok' => false, 'class' => 'reject.limit', 'code' => $failure->error->code ?? 'page'];
    } catch (Throwable $exception) {
        return internal_failure($exception);
    }
}

function eval_lease(int $seconds): array
{
    try {
        LeaseDuration::assertValid(new DateInterval('PT' . $seconds . 'S'));
        return ['ok' => true, 'class' => 'accept', 'seconds' => $seconds];
    } catch (InvalidArgumentException) {
        return ['ok' => false, 'class' => 'reject.limit', 'code' => 'lease'];
    } catch (Throwable $exception) {
        return internal_failure($exception);
    }
}

function eval_scenario_steps(int $count): array
{
    $steps = [];
    for ($i = 0; $i < $count; $i++) {
        $steps[] = ['id' => 's' . $i, 'tool' => 'demo_tool', 'input_map' => []];
    }
    try {
        (new ScenarioRunner())->run(
            ['spec_rev' => '2026-09', 'scenario_id' => 'bench', 'steps' => $steps],
            [],
            dummy_tools(),
        );
        return ['ok' => true, 'class' => 'accept', 'count' => $count];
    } catch (ExpressionException $exception) {
        return ['ok' => false, 'class' => 'reject.limit', 'code' => $exception->limitName ?? 'scenario.max_steps'];
    } catch (InvalidArgumentException) {
        return ['ok' => false, 'class' => 'reject.claim', 'code' => 'scenario.claim'];
    } catch (Throwable $exception) {
        return internal_failure($exception);
    }
}

function eval_event_fold(int $events, bool $injectInternal = false): array
{
    try {
        if ($injectInternal) {
            throw new RuntimeException('injected benchmark failure');
        }
        if ($events < 1) {
            return ['ok' => false, 'class' => 'reject.claim', 'code' => 'events.minimum'];
        }

        $store = new InMemoryEventStore();
        $actor = new Actor('system', 'resource-gate');
        $pending = [];
        for ($index = 1; $index <= $events; $index++) {
            $pending[] = new UncommittedEvent(
                eventId: sprintf('benchid-%08d', $index),
                kind: $index === 1 ? 'action_created' : 'benchmark_tick',
                eventVersion: 1,
                specRevision: '2026-09',
                actor: $actor,
                timestamp: '2026-09-22T00:00:00+00:00',
                payload: $index === 1 ? [] : ['index' => $index],
            );
        }

        $appendStarted = hrtime(true);
        $expected = 0;
        foreach (array_chunk($pending, EventStoreSupport::MAX_BATCH) as $chunk) {
            $result = $store->append('tenant', 'benchmark-action', $expected, $chunk);
            $expected = $result->lastSeq;
        }
        $appendNs = hrtime(true) - $appendStarted;

        $loadStarted = hrtime(true);
        $loaded = [];
        $after = 0;
        do {
            $page = $store->load('tenant', 'benchmark-action', $after, EventStoreSupport::MAX_PAGE);
            array_push($loaded, ...$page->events);
            $after = $page->lastSeq;
        } while ($page->hasMore);
        $loadNs = hrtime(true) - $loadStarted;

        $foldStarted = hrtime(true);
        $state = (new ActionReducer('2026-09'))->fold($loaded);
        $foldNs = hrtime(true) - $foldStarted;
        $stateDocument = [
            'action_id' => $state->actionId,
            'status' => $state->status?->value,
            'seq' => $state->seq,
            'epoch' => $state->epoch,
            'inputs_count' => count($state->inputs),
            'effects_count' => count($state->effects),
            'cancellation_requested' => $state->cancellationRequested,
        ];
        $bytes = (new Canonicalizer())->canonicalize($stateDocument);

        return [
            'ok' => true,
            'class' => 'accept',
            'appended_count' => $expected,
            'loaded_count' => count($loaded),
            'folded_count' => $state->seq,
            'final_seq' => $state->seq,
            'digest' => hash('sha256', $bytes),
            'output_bytes' => strlen($bytes),
            'timing_ns' => ['append' => $appendNs, 'load' => $loadNs, 'fold' => $foldNs],
        ];
    } catch (Throwable $exception) {
        return internal_failure($exception);
    }
}

function constants(): array
{
    return [
        'ok' => true,
        'class' => 'accept',
        'constants' => [
            'expression.max_depth' => 32,
            'expression.max_length' => 512,
            'expression.max_collection_size' => 1000,
            'expression.max_visited_nodes' => 10000,
            'scenario.max_steps' => 64,
            'baggage.max_items' => 64,
            'baggage.max_bytes' => 8192,
            'token.max_raw_bytes' => TokenEnvelope::MAX_RAW_BYTES,
            'token.default_skew_seconds' => TokenEnvelope::DEFAULT_SKEW_SECONDS,
            'token.default_max_lifetime_seconds' => TokenEnvelope::DEFAULT_MAX_LIFETIME_SECONDS,
            'token.min_retention_seconds' => TokenEnvelope::MIN_RETENTION_SECONDS,
            'token.max_skew_seconds' => TokenEnvelope::MAX_SKEW_SECONDS,
            'token.json_decode_depth' => 2,
            'canonicalizer.json_decode_depth' => 512,
            'event.default_page' => EventStoreSupport::DEFAULT_PAGE,
            'event.max_page' => EventStoreSupport::MAX_PAGE,
            'event.max_batch' => EventStoreSupport::MAX_BATCH,
            'lease.default_seconds' => LeaseDuration::DEFAULT_SECONDS,
            'lease.max_seconds' => LeaseDuration::MAX_SECONDS,
        ],
    ];
}

function dispatch(array $request): array
{
    $op = $request['op'] ?? '';
    return match ($op) {
        'constants' => constants(),
        'expression' => eval_expression((string) ($request['source'] ?? ''), is_array($request['context'] ?? null) ? $request['context'] : [], $request),
        'baggage' => eval_baggage(is_array($request['ids'] ?? null) ? $request['ids'] : []),
        'token_parse' => eval_token((string) ($request['token'] ?? '')),
        'jcs' => eval_jcs((string) ($request['json'] ?? '')),
        'descriptor' => eval_descriptor(),
        'event_page' => eval_event_page((int) ($request['limit'] ?? EventStoreSupport::DEFAULT_PAGE)),
        'lease' => eval_lease((int) ($request['seconds'] ?? LeaseDuration::DEFAULT_SECONDS)),
        'scenario_steps' => eval_scenario_steps((int) ($request['count'] ?? 1)),
        'event_fold' => eval_event_fold((int) ($request['events'] ?? 1), ($request['inject_internal'] ?? false) === true),
        'timed' => timed($request),
        default => ['ok' => false, 'class' => 'reject.unsupported', 'code' => 'operation.unsupported'],
    };
}

function timed(array $request): array
{
    $inner = $request['inner'] ?? [];
    if (!is_array($inner)) {
        return ['ok' => false, 'class' => 'reject.claim', 'code' => 'timed.inner'];
    }
    $warmup = max(0, (int) ($request['warmup'] ?? 0));
    $samples = max(1, (int) ($request['samples'] ?? 1));
    for ($i = 0; $i < $warmup; $i++) {
        dispatch($inner);
    }
    $raw = [];
    $last = null;
    for ($i = 0; $i < $samples; $i++) {
        $start = hrtime(true);
        $last = dispatch($inner);
        $raw[] = hrtime(true) - $start;
    }
    return [
        'ok' => (bool) ($last['ok'] ?? false),
        'class' => $last['class'] ?? 'reject',
        'inner' => $last,
        'raw_ns' => $raw,
        'one_call' => true,
    ];
}

try {
    emit(dispatch(read_request()));
} catch (JsonException) {
    emit(['ok' => false, 'class' => 'reject.syntax', 'code' => 'request.json']);
} catch (Throwable $exception) {
    emit(internal_failure($exception));
}

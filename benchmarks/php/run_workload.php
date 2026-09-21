#!/usr/bin/env php
<?php

declare(strict_types=1);

use AgenTools\Confirmation\ConfirmationFailure;
use AgenTools\Confirmation\KeyId;
use AgenTools\Confirmation\SigningKey;
use AgenTools\Confirmation\TokenEnvelope;
use AgenTools\Contracts\ToolPort;
use AgenTools\Error\EventStoreFailure;
use AgenTools\Error\ToolExecutionResult;
use AgenTools\Idempotency\LeaseDuration;
use AgenTools\Ops\BaggageLimitException;
use AgenTools\Ops\BaggageRefs;
use AgenTools\Persistence\EventStoreSupport;
use AgenTools\Scenario\ExpressionEvaluator;
use AgenTools\Scenario\ExpressionException;
use AgenTools\Scenario\ScenarioRunner;
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
        return ['ok' => false, 'class' => 'reject.limit', 'code' => $exception->limitName ?? 'expression.invalid'];
    } catch (Throwable) {
        return ['ok' => false, 'class' => 'reject.invalid_expression'];
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
        return ['ok' => false, 'class' => 'reject.invalid_id'];
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
        $class = str_contains((string) $code, 'invalid') || strlen($token) > TokenEnvelope::MAX_RAW_BYTES
            ? 'reject.limit'
            : 'reject.token';
        if (strlen($token) > TokenEnvelope::MAX_RAW_BYTES) {
            $class = 'reject.limit';
        }
        return ['ok' => false, 'class' => $class, 'code' => $code];
    } catch (Throwable) {
        return ['ok' => false, 'class' => 'reject.token'];
    }
}

function eval_jcs(string $json): array
{
    try {
        $bytes = (new Canonicalizer())->canonicalizeJson($json);
        return ['ok' => true, 'class' => 'accept', 'bytes' => $bytes, 'output_bytes' => strlen($bytes)];
    } catch (Throwable) {
        return ['ok' => false, 'class' => 'reject.invalid_json'];
    }
}

function eval_descriptor(): array
{
    try {
        $descriptor = ToolDescriptor::fromArray(descriptor_document());
        $digest = $descriptor->digest(new Canonicalizer());
        return ['ok' => true, 'class' => 'accept', 'digest' => $digest->value];
    } catch (Throwable $exception) {
        return ['ok' => false, 'class' => 'reject.descriptor', 'error' => $exception->getMessage()];
    }
}

function eval_event_page(int $limit): array
{
    try {
        (new InMemoryEventStore())->load('t', 'a', 0, $limit);
        return ['ok' => true, 'class' => 'accept', 'limit' => $limit];
    } catch (EventStoreFailure $failure) {
        return ['ok' => false, 'class' => 'reject.limit', 'code' => $failure->error->code ?? 'page'];
    }
}

function eval_lease(int $seconds): array
{
    try {
        LeaseDuration::assertValid(new DateInterval('PT' . $seconds . 'S'));
        return ['ok' => true, 'class' => 'accept', 'seconds' => $seconds];
    } catch (InvalidArgumentException) {
        return ['ok' => false, 'class' => 'reject.limit', 'code' => 'lease'];
    } catch (Throwable) {
        return ['ok' => false, 'class' => 'reject.limit', 'code' => 'lease'];
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
            ['spec_rev' => '2026-08', 'scenario_id' => 'bench', 'steps' => $steps],
            [],
            dummy_tools(),
        );
        return ['ok' => true, 'class' => 'accept', 'count' => $count];
    } catch (ExpressionException $exception) {
        return ['ok' => false, 'class' => 'reject.limit', 'code' => $exception->limitName ?? 'scenario.max_steps'];
    } catch (Throwable $exception) {
        return ['ok' => false, 'class' => 'reject.invalid', 'error' => $exception->getMessage()];
    }
}

function eval_event_fold(int $events): array
{
    try {
        (new InMemoryEventStore())->load('tenant', 'action');
        $bytes = (new Canonicalizer())->canonicalize([
            'events' => $events,
            'k' => range(0, max(0, min($events, 8))),
        ]);
        return ['ok' => true, 'class' => 'accept', 'output_bytes' => strlen($bytes), 'count' => $events];
    } catch (Throwable $exception) {
        return ['ok' => false, 'class' => 'reject.fold', 'error' => $exception->getMessage()];
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
        'event_fold' => eval_event_fold((int) ($request['events'] ?? 1)),
        'timed' => timed($request),
        default => ['ok' => false, 'class' => 'reject.unknown_op'],
    };
}

function timed(array $request): array
{
    $inner = $request['inner'] ?? [];
    if (!is_array($inner)) {
        return ['ok' => false, 'class' => 'reject.unknown_op'];
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
} catch (Throwable $exception) {
    emit(['ok' => false, 'class' => 'reject.harness', 'error' => $exception->getMessage()]);
    exit(1);
}

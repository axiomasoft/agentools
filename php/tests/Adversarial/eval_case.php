#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = dirname(__DIR__, 3);
require $root . '/php/vendor/autoload.php';

use AgenTools\Confirmation\ConfirmationFailure;
use AgenTools\Confirmation\KeyId;
use AgenTools\Confirmation\SigningKey;
use AgenTools\Confirmation\TokenEnvelope;
use AgenTools\Ops\BaggageLimitException;
use AgenTools\Ops\BaggageRefs;
use AgenTools\Scenario\ExpressionEvaluator;
use AgenTools\Scenario\ExpressionException;
use AgenTools\Schema\CanonicalizationException;
use AgenTools\Schema\Canonicalizer;
use AgenTools\TestKit\StaticTokenKeyProvider;

function require_string(mixed $value, string $field): string
{
    if (!is_string($value)) {
        throw new \InvalidArgumentException($field . ' must be a string');
    }
    return $value;
}

/**
 * @return list<mixed>
 */
function require_list(mixed $value, string $field): array
{
    if (!is_array($value) || !array_is_list($value)) {
        throw new \InvalidArgumentException($field . ' must be a list');
    }
    return $value;
}

/**
 * @param array<string, mixed> $request
 * @return array<string, mixed>
 */
function evaluate_case(array $request): array
{
    $op = require_string($request['op'] ?? '', 'op');
    $inject = is_string($request['inject'] ?? null) ? $request['inject'] : '';
    return match ($op) {
        'jcs' => eval_jcs(require_string($request['json'] ?? null, 'json'), $inject),
        'expression' => eval_expression(require_string($request['source'] ?? null, 'source')),
        'baggage' => eval_baggage(require_list($request['ids'] ?? [], 'ids')),
        'token_parse' => eval_token(
            require_string($request['token'] ?? null, 'token'),
            require_string($request['now'] ?? '2026-09-20T11:00:00+00:00', 'now'),
        ),
        default => ['ok' => false, 'class' => 'reject.unknown_op'],
    };
}

/** @return array<string, mixed> */
function eval_jcs(string $json, string $inject): array
{
    try {
        if ($inject === 'php-ascii-sort') {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            $bytes = ascii_canonicalize($decoded);
        } else {
            $bytes = (new Canonicalizer())->canonicalizeJson($json);
        }
        if ($inject === '' ) {
            $again = (new Canonicalizer())->canonicalizeJson($bytes);
            if ($again !== $bytes) {
                return ['ok' => false, 'class' => 'reject.not_idempotent', 'bytes' => $bytes];
            }
        }
        return ['ok' => true, 'class' => 'accept', 'bytes' => $bytes];
    } catch (CanonicalizationException $exception) {
        $message = $exception->getMessage();
        $class = str_contains($message, 'duplicate') ? 'reject.duplicate_name' : 'reject.invalid_json';
        return ['ok' => false, 'class' => $class];
    } catch (\Throwable) {
        return ['ok' => false, 'class' => 'reject.invalid_json'];
    }
}

function ascii_canonicalize(mixed $value): string
{
    if ($value === null || is_bool($value) || is_string($value) || is_int($value)) {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
    if (is_float($value)) {
        return json_encode($value, JSON_THROW_ON_ERROR);
    }
    if (is_array($value) && array_is_list($value)) {
        return '[' . implode(',', array_map(ascii_canonicalize(...), $value)) . ']';
    }
    if (is_array($value)) {
        ksort($value, SORT_STRING);
        $parts = [];
        foreach ($value as $key => $item) {
            $parts[] = json_encode((string) $key, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ':' . ascii_canonicalize($item);
        }
        return '{' . implode(',', $parts) . '}';
    }
    throw new \RuntimeException('unsupported');
}

/** @return array<string, mixed> */
function eval_expression(string $source): array
{
    try {
        $value = (new ExpressionEvaluator())->evaluate($source, []);
        return ['ok' => true, 'class' => 'accept', 'value' => $value];
    } catch (ExpressionException $exception) {
        return ['ok' => false, 'class' => 'reject.limit', 'code' => $exception->limitName ?? 'expression.invalid'];
    } catch (\Throwable) {
        return ['ok' => false, 'class' => 'reject.invalid_expression'];
    }
}

/**
 * @param list<mixed> $ids
 * @return array<string, mixed>
 */
function eval_baggage(array $ids): array
{
    try {
        $baggage = new BaggageRefs();
        foreach ($ids as $id) {
            $baggage = $baggage->add(require_string($id, 'id'));
        }
        return ['ok' => true, 'class' => 'accept', 'count' => count($baggage->refs())];
    } catch (BaggageLimitException) {
        return ['ok' => false, 'class' => 'reject.limit', 'code' => 'agentools.budget.baggage_limit_exceeded'];
    } catch (\InvalidArgumentException) {
        return ['ok' => false, 'class' => 'reject.invalid_id'];
    }
}

/** @return array<string, mixed> */
function eval_token(string $token, string $now): array
{
    $keys = new StaticTokenKeyProvider(new SigningKey(new KeyId('k1'), 'HS256', str_repeat('k', 32)));
    try {
        TokenEnvelope::parse($token, $keys, new \DateTimeImmutable($now), 'agentools', 'confirmation');
        return ['ok' => true, 'class' => 'accept'];
    } catch (ConfirmationFailure $failure) {
        return ['ok' => false, 'class' => 'reject.token', 'code' => $failure->error->code];
    } catch (\Throwable) {
        return ['ok' => false, 'class' => 'reject.token', 'code' => 'agentools.validation.confirmation_token_invalid'];
    }
}

$stdin = STDIN;
while (($line = fgets($stdin)) !== false) {
    if (trim($line) === '') {
        continue;
    }
    /** @var array<string, mixed> $request */
    $request = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
    $result = evaluate_case($request);
    $result['case_id'] = $request['case_id'] ?? null;
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    fflush(STDOUT);
}

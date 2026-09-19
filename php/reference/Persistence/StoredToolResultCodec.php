<?php

declare(strict_types=1);

namespace AgenTools\Reference\Persistence;

use AgenTools\Error\ToolError;
use AgenTools\Idempotency\StoredResultDigest;
use AgenTools\Idempotency\StoredToolResult;

final class StoredToolResultCodec
{
    /** @return array{result_json: string, digest: string} */
    public static function encode(StoredToolResult $result, StoredResultDigest $digest): array
    {
        return [
            'result_json' => json_encode(self::resultPayload($result), JSON_THROW_ON_ERROR),
            'digest' => $digest->value,
        ];
    }

    /** @return array{result: StoredToolResult, digest: StoredResultDigest} */
    public static function decode(string $resultJson, string $digest): array
    {
        $payload = json_decode($resultJson, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($payload)) {
            throw new \InvalidArgumentException('Stored tool result must decode to an object.');
        }

        if (isset($payload['error']) && is_array($payload['error'])) {
            /** @var array<string, mixed> $error */
            $error = $payload['error'];
            return [
                'result' => new StoredToolResult(error: ToolError::fromArray($error)),
                'digest' => new StoredResultDigest($digest),
            ];
        }
        if (array_key_exists('value', $payload) && is_array($payload['value'])) {
            /** @var array<string, mixed> $value */
            $value = $payload['value'];
            return [
                'result' => new StoredToolResult(value: $value),
                'digest' => new StoredResultDigest($digest),
            ];
        }

        throw new \InvalidArgumentException('Stored tool result must contain value or error.');
    }

    /** @return array<string, mixed> */
    private static function resultPayload(StoredToolResult $result): array
    {
        if ($result->error !== null) {
            return ['error' => $result->error->toArray()];
        }

        return ['value' => $result->value];
    }
}

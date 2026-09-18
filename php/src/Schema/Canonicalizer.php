<?php

declare(strict_types=1);

namespace AgenTools\Schema;

/**
 * RFC 8785 JSON Canonicalization Scheme facade.
 *
 * PHP arrays cannot express an empty JSON object. Pass an stdClass where that
 * distinction matters, or use canonicalizeJson() for received JSON text.
 */
final class Canonicalizer
{
    public function canonicalize(mixed $value): string
    {
        try {
            return $this->serialize($value);
        } catch (CanonicalizationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new CanonicalizationException('The value cannot be canonicalized as JSON.', previous: $exception);
        }
    }

    /**
     * Canonicalizes JSON text while retaining the JSON distinction between {}
     * and [] and rejecting duplicate object names before PHP decodes them.
     */
    public function canonicalizeJson(string $json): string
    {
        try {
            (new JsonObjectNameValidator($json))->assertValid();
            $value = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        } catch (CanonicalizationException $exception) {
            throw $exception;
        } catch (\JsonException $exception) {
            throw new CanonicalizationException('The JSON text is invalid.', previous: $exception);
        }

        return $this->canonicalize($value);
    }

    private function serialize(mixed $value): string
    {
        return match (true) {
            $value === null, is_bool($value), is_string($value), is_int($value) => $this->encodeScalar($value),
            is_float($value) => $this->formatNumber($value),
            is_array($value) => array_is_list($value)
                ? '[' . implode(',', array_map($this->serialize(...), $value)) . ']'
                : $this->serializeObject($value),
            $value instanceof \stdClass => $this->serializeObject(get_object_vars($value)),
            default => throw new CanonicalizationException('Only JSON values can be canonicalized.'),
        };
    }

    private function encodeScalar(null|bool|string|int $value): string
    {
        return json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
    }

    /** @param array<array-key, mixed> $object */
    private function serializeObject(array $object): string
    {
        $members = [];
        foreach ($object as $key => $value) {
            $members[(string) $key] = $value;
        }

        uksort($members, static function (string $left, string $right): int {
            return strcmp(
                mb_convert_encoding($left, 'UTF-16BE', 'UTF-8'),
                mb_convert_encoding($right, 'UTF-16BE', 'UTF-8'),
            );
        });

        $serialized = [];
        foreach ($members as $key => $value) {
            $serialized[] = $this->encodeScalar((string) $key) . ':' . $this->serialize($value);
        }

        return '{' . implode(',', $serialized) . '}';
    }

    private function formatNumber(float $number): string
    {
        if (!is_finite($number)) {
            throw new CanonicalizationException('NaN and infinity are not JSON values.');
        }
        if ($number === 0.0) {
            return '0';
        }

        $encoded = json_encode($number, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        if (!str_contains($encoded, 'e')) {
            return str_ends_with($encoded, '.0') ? substr($encoded, 0, -2) : $encoded;
        }

        if (preg_match('/^(-?)(\d+)(?:\.(\d+))?e([+-]?\d+)$/', $encoded, $match) !== 1) {
            throw new CanonicalizationException('The number cannot be represented in JCS form.');
        }

        $sign = $match[1];
        $digits = $match[2] . $match[3];
        $digits = rtrim($digits, '0');
        $digits = $digits === '' ? '0' : $digits;
        $exponent = (int) $match[4];

        // ECMAScript uses decimal notation in this inclusive exponent range.
        if ($exponent >= -6 && $exponent < 21) {
            $decimalPosition = strlen($match[2]) + $exponent;
            if ($decimalPosition <= 0) {
                return $sign . '0.' . str_repeat('0', -$decimalPosition) . $digits;
            }
            if ($decimalPosition >= strlen($digits)) {
                return $sign . $digits . str_repeat('0', $decimalPosition - strlen($digits));
            }

            return $sign . substr($digits, 0, $decimalPosition) . '.' . substr($digits, $decimalPosition);
        }

        $mantissa = $match[2];
        $fraction = rtrim($match[3], '0');
        if ($fraction !== '') {
            $mantissa .= '.' . $fraction;
        }

        return $sign . $mantissa . 'e' . ($exponent >= 0 ? '+' : '') . $exponent;
    }
}

/** Lightweight JSON parser used solely to reject duplicate object member names. */
final class JsonObjectNameValidator
{
    private int $offset = 0;

    private int $length;

    public function __construct(private readonly string $json)
    {
        $this->length = strlen($json);
    }

    public function assertValid(): void
    {
        $this->skipWhitespace();
        $this->parseValue();
        $this->skipWhitespace();
        if ($this->offset !== $this->length) {
            throw new CanonicalizationException('The JSON text is invalid.');
        }
    }

    private function parseValue(): void
    {
        $this->skipWhitespace();
        $token = $this->json[$this->offset] ?? '';
        match ($token) {
            '{' => $this->parseObject(),
            '[' => $this->parseArray(),
            '"' => $this->parseString(),
            't' => $this->consumeLiteral('true'),
            'f' => $this->consumeLiteral('false'),
            'n' => $this->consumeLiteral('null'),
            '-', '0', '1', '2', '3', '4', '5', '6', '7', '8', '9' => $this->parseNumber(),
            default => throw new CanonicalizationException('The JSON text is invalid.'),
        };
    }

    private function parseObject(): void
    {
        ++$this->offset;
        $this->skipWhitespace();
        if (($this->json[$this->offset] ?? '') === '}') {
            ++$this->offset;
            return;
        }

        $names = [];
        while (true) {
            $rawName = $this->parseString();
            try {
                $name = json_decode($rawName, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $exception) {
                throw new CanonicalizationException('The JSON text is invalid.', previous: $exception);
            }
            if (!is_string($name) || array_key_exists($name, $names)) {
                throw new CanonicalizationException('JSON objects with duplicate member names are not canonicalizable.');
            }
            $names[$name] = true;
            $this->skipWhitespace();
            if (($this->json[$this->offset] ?? '') !== ':') {
                throw new CanonicalizationException('The JSON text is invalid.');
            }
            ++$this->offset;
            $this->parseValue();
            $this->skipWhitespace();
            $token = $this->json[$this->offset] ?? '';
            if ($token === '}') {
                ++$this->offset;
                return;
            }
            if ($token !== ',') {
                throw new CanonicalizationException('The JSON text is invalid.');
            }
            ++$this->offset;
            $this->skipWhitespace();
        }
    }

    private function parseArray(): void
    {
        ++$this->offset;
        $this->skipWhitespace();
        if (($this->json[$this->offset] ?? '') === ']') {
            ++$this->offset;
            return;
        }

        while (true) {
            $this->parseValue();
            $this->skipWhitespace();
            $token = $this->json[$this->offset] ?? '';
            if ($token === ']') {
                ++$this->offset;
                return;
            }
            if ($token !== ',') {
                throw new CanonicalizationException('The JSON text is invalid.');
            }
            ++$this->offset;
        }
    }

    private function parseString(): string
    {
        $start = $this->offset;
        if (($this->json[$this->offset] ?? '') !== '"') {
            throw new CanonicalizationException('The JSON text is invalid.');
        }
        ++$this->offset;
        while ($this->offset < $this->length) {
            $byte = ord($this->json[$this->offset]);
            if ($byte === 0x22) {
                ++$this->offset;
                return substr($this->json, $start, $this->offset - $start);
            }
            if ($byte === 0x5c) {
                ++$this->offset;
                $escape = $this->json[$this->offset] ?? '';
                if ($escape === 'u') {
                    $this->offset += 5;
                    continue;
                }
                ++$this->offset;
                continue;
            }
            if ($byte < 0x20) {
                throw new CanonicalizationException('The JSON text is invalid.');
            }
            ++$this->offset;
        }

        throw new CanonicalizationException('The JSON text is invalid.');
    }

    private function consumeLiteral(string $literal): void
    {
        if (substr($this->json, $this->offset, strlen($literal)) !== $literal) {
            throw new CanonicalizationException('The JSON text is invalid.');
        }
        $this->offset += strlen($literal);
    }

    private function parseNumber(): void
    {
        if (preg_match('/\G-?(?:0|[1-9]\d*)(?:\.\d+)?(?:[eE][+-]?\d+)?/A', $this->json, $match, 0, $this->offset) !== 1) {
            throw new CanonicalizationException('The JSON text is invalid.');
        }
        $this->offset += strlen($match[0]);
    }

    private function skipWhitespace(): void
    {
        while ($this->offset < $this->length && str_contains(" \n\r\t", $this->json[$this->offset])) {
            ++$this->offset;
        }
    }
}

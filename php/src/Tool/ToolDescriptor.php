<?php

declare(strict_types=1);

namespace AgenTools\Tool;

use AgenTools\Identity\ExtensionIdentity;
use AgenTools\Schema\Canonicalizer;

/** Manual DTO for the published tool-descriptor schema. */
final readonly class ToolDescriptor
{
    /**
     * @param array<string, mixed> $inputSchema
     * @param array<string, mixed>|null $outputSchema
     * @param array<string, mixed> $otherMeta
     */
    public function __construct(
        public string $name,
        public string $description,
        public array $inputSchema,
        public ActionProfile $actionProfile,
        public ToolAnnotations $annotations,
        public ?string $title = null,
        public ?array $outputSchema = null,
        public array $otherMeta = [],
    ) {
        if (preg_match('/^[a-z][a-z0-9]*(_[a-z0-9]+)+$/', $name) !== 1 || strlen($name) > 64) {
            throw new \InvalidArgumentException('Tool name must use the published AgenTools grammar.');
        }
        if (preg_match('/^Purpose: [^\r\n]+\nCall when: [^\r\n]+\nRequired arguments: [^\r\n]+\nExample: [^\r\n]+$/', $description) !== 1) {
            throw new \InvalidArgumentException('Tool description must have the four actionable lines.');
        }
        $this->assertAnnotationsMatchEffect();
    }

    /** @param array<string, mixed> $document */
    public static function fromArray(array $document): self
    {
        foreach ($document as $key => $_) {
            if (!in_array($key, ['name', 'title', 'description', 'inputSchema', 'outputSchema', 'annotations', '_meta'], true)) {
                throw new \InvalidArgumentException(sprintf('Unexpected descriptor member %s.', $key));
            }
        }
        $meta = $document['_meta'] ?? null;
        $profile = is_array($meta) ? ($meta[ExtensionIdentity::DATA_KEY] ?? null) : null;
        if (!is_array($profile)) {
            throw new \InvalidArgumentException('A descriptor must carry _meta["dev.agentools/action"].');
        }
        $annotations = $document['annotations'] ?? null;
        if (!is_array($annotations) || array_keys($annotations) !== ['readOnlyHint', 'destructiveHint', 'idempotentHint', 'openWorldHint']) {
            throw new \InvalidArgumentException('All four MCP annotation hints must be explicit.');
        }
        foreach (['readOnlyHint', 'destructiveHint', 'idempotentHint', 'openWorldHint'] as $key) {
            if (!is_bool($annotations[$key])) {
                throw new \InvalidArgumentException('Tool annotations must be boolean.');
            }
        }
        foreach ($profile as $key => $_) {
            if (!in_array($key, ['side_effect', 'confirmation', 'idempotent_commit', 'permissions_hint', 'content_origin'], true)) {
                throw new \InvalidArgumentException(sprintf('Unexpected action profile member %s.', $key));
            }
        }
        if (!isset($profile['side_effect']) || !is_string($profile['side_effect'])) {
            throw new \InvalidArgumentException('The action profile requires side_effect.');
        }
        $permissions = self::optionalStringList($profile, 'permissions_hint');
        $otherMeta = [];
        foreach ($meta as $key => $value) {
            if (!is_string($key)) {
                throw new \InvalidArgumentException('_meta keys must be strings.');
            }
            if ($key !== ExtensionIdentity::DATA_KEY) {
                $otherMeta[$key] = $value;
            }
        }

        return new self(
            self::requiredString($document, 'name'),
            self::requiredString($document, 'description'),
            self::requiredArray($document, 'inputSchema'),
            new ActionProfile(
                $profile['side_effect'],
                self::optionalString($profile, 'confirmation'),
                self::optionalBool($profile, 'idempotent_commit'),
                $permissions,
                self::optionalString($profile, 'content_origin'),
            ),
            new ToolAnnotations($annotations['readOnlyHint'], $annotations['destructiveHint'], $annotations['idempotentHint'], $annotations['openWorldHint']),
            self::optionalString($document, 'title'),
            self::optionalArray($document, 'outputSchema'),
            $otherMeta,
        );
    }

    /** @return array<string, mixed> */
    public function digestInput(): array
    {
        return array_filter([
            'name' => $this->name,
            'description' => $this->description,
            'inputSchema' => $this->inputSchema,
            'outputSchema' => $this->outputSchema,
            'extension' => $this->actionProfile->toArray(),
        ], static fn (mixed $value): bool => $value !== null);
    }

    public function digest(Canonicalizer $canonicalizer): DescriptorDigest
    {
        return DescriptorDigest::for($this, $canonicalizer);
    }

    public function assertDigest(DescriptorDigest $expected, Canonicalizer $canonicalizer): void
    {
        $actual = $this->digest($canonicalizer);
        if ($actual->value !== $expected->value) {
            throw new DescriptorDrift($expected, $actual);
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $meta = $this->otherMeta;
        $meta[ExtensionIdentity::DATA_KEY] = $this->actionProfile->toArray();
        return array_filter([
            'name' => $this->name,
            'title' => $this->title,
            'description' => $this->description,
            'inputSchema' => $this->inputSchema,
            'outputSchema' => $this->outputSchema,
            'annotations' => $this->annotations->toArray(),
            '_meta' => $meta,
        ], static fn (mixed $value): bool => $value !== null);
    }

    private function assertAnnotationsMatchEffect(): void
    {
        $expected = match ($this->actionProfile->sideEffect) {
            'read' => [true, false],
            'draft', 'write' => [false, false],
            'irreversible' => [false, true],
            default => null,
        };
        if ($expected !== null && [$this->annotations->readOnlyHint, $this->annotations->destructiveHint] !== $expected) {
            throw new \InvalidArgumentException('Tool annotations do not match side_effect.');
        }
    }

    /** @param array<string, mixed> $data */
    private static function requiredString(array $data, string $key): string
    {
        if (!isset($data[$key]) || !is_string($data[$key])) {
            throw new \InvalidArgumentException(sprintf('%s must be a string.', $key));
        }
        return $data[$key];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function requiredArray(array $data, string $key): array
    {
        if (!isset($data[$key]) || !is_array($data[$key])) {
            throw new \InvalidArgumentException(sprintf('%s must be an object.', $key));
        }
        return self::object($data[$key], $key);
    }

    /** @param array<string, mixed> $data */
    private static function optionalString(array $data, string $key): ?string
    {
        if (!array_key_exists($key, $data)) {
            return null;
        }
        if (!is_string($data[$key])) {
            throw new \InvalidArgumentException(sprintf('%s must be a string.', $key));
        }
        return $data[$key];
    }

    /** @param array<string, mixed> $data */
    private static function optionalBool(array $data, string $key): ?bool
    {
        if (!array_key_exists($key, $data)) {
            return null;
        }
        if (!is_bool($data[$key])) {
            throw new \InvalidArgumentException(sprintf('%s must be a boolean.', $key));
        }
        return $data[$key];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    private static function optionalArray(array $data, string $key): ?array
    {
        if (!array_key_exists($key, $data)) {
            return null;
        }
        if (!is_array($data[$key])) {
            throw new \InvalidArgumentException(sprintf('%s must be an object.', $key));
        }
        return self::object($data[$key], $key);
    }

    /**
     * @param array<string, mixed> $data
     * @return list<string>|null
     */
    private static function optionalStringList(array $data, string $key): ?array
    {
        if (!array_key_exists($key, $data)) {
            return null;
        }
        if (!is_array($data[$key]) || !array_is_list($data[$key])) {
            throw new \InvalidArgumentException(sprintf('%s must be a list.', $key));
        }
        $list = [];
        foreach ($data[$key] as $value) {
            if (!is_string($value)) {
                throw new \InvalidArgumentException(sprintf('%s must contain strings.', $key));
            }
            $list[] = $value;
        }
        return $list;
    }

    /** @return array<string, mixed> */
    private static function object(mixed $value, string $key): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new \InvalidArgumentException(sprintf('%s must be an object.', $key));
        }
        $object = [];
        foreach ($value as $member => $memberValue) {
            if (!is_string($member)) {
                throw new \InvalidArgumentException(sprintf('%s must be an object.', $key));
            }
            $object[$member] = $memberValue;
        }
        return $object;
    }
}

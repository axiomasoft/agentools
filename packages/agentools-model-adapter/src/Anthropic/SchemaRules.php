<?php

declare(strict_types=1);

namespace AgenToolsPlatform\ModelAdapter\Anthropic;

use AgenToolsPlatform\Core\Common\{ContractViolation, ErrorCode};
use AgenToolsPlatform\Core\Models\JsonObject;
use Opis\JsonSchema\Validator;

/** Real JSON Schema validation; no remote resolver or executable custom keywords. */
final class SchemaRules
{
    private Validator $validator;
    private Validator $meta;
    public function __construct()
    {
        $this->validator = new Validator();
        $this->validator->setResolver(null);
        $this->validator->setParser((new \Opis\JsonSchema\Parsers\SchemaParser())->setOption('allowDefaults', false));
        $resolver = new \Opis\JsonSchema\Resolvers\SchemaResolver();
        foreach (['schema','meta-core','meta-applicator','meta-content','meta-format-annotation','meta-format-assertion','meta-meta-data','meta-unevaluated','meta-validation'] as $name) {
            $path = dirname(__DIR__, 2) . '/resources/json-schema/' . $name . '.json';
            $raw = file_get_contents($path);
            if (!is_string($raw) || !$resolver->registerRaw($raw)) { throw new \RuntimeException('Required bundled metaschema missing'); }
        }
        $this->meta = new Validator(new \Opis\JsonSchema\SchemaLoader((new \Opis\JsonSchema\Parsers\SchemaParser())->setOption('allowDefaults', false), $resolver, true));
    }
    public function prepare(JsonObject $schema, bool $native): \stdClass
    {
        $wire = Json::schema($schema);
        try {
            if (!$this->meta->validate($wire, 'https://json-schema.org/draft/2020-12/schema')->isValid()) { throw new ContractViolation(ErrorCode::InvalidInput); }
            $this->inspect($wire, $wire, [], $native);
            $this->validator->loader()->loadObjectSchema($wire);
        } catch (ContractViolation $error) { throw $error; }
        catch (\Throwable) { throw new ContractViolation(ErrorCode::InvalidInput); }
        return $wire;
    }
    public function accepts(mixed $value, \stdClass $schema): bool
    {
        try { return $this->validator->validate($value, $schema)->isValid(); }
        catch (\Throwable) { return false; }
    }
    /** @param list<string> $refs */
    private function inspect(mixed $node, \stdClass $root, array $refs, bool $native, int $depth = 0): void
    {
        if ($depth > 64) { throw new ContractViolation(ErrorCode::CapabilityUnavailable); }
        if (is_bool($node)) { return; }
        if (!$node instanceof \stdClass) { throw new ContractViolation(ErrorCode::InvalidInput); }
        foreach (get_object_vars($node) as $key => $value) {
            if (str_starts_with($key, '$') && !in_array($key, ['$schema', '$ref', '$defs'], true)) {
                throw new ContractViolation(ErrorCode::CapabilityUnavailable);
            }
            if ($native && !in_array($key, ['type','properties','required','additionalProperties','items','enum','const','anyOf','allOf','$ref','$defs','definitions','description','title','default','format','minItems','$schema'], true)) {
                throw new ContractViolation(ErrorCode::CapabilityUnavailable);
            }
            if ($key === '$schema' && $value !== 'https://json-schema.org/draft/2020-12/schema') { throw new ContractViolation(ErrorCode::CapabilityUnavailable); }
            if ($key === '$ref') {
                if (!is_string($value) || !str_starts_with($value, '#')) { throw new ContractViolation(ErrorCode::CapabilityUnavailable); }
                if (in_array($value, $refs, true)) {
                    if ($native) { throw new ContractViolation(ErrorCode::CapabilityUnavailable); }
                    continue;
                }
                $resolved = $this->pointer($root, $value);
                $this->inspect($resolved, $root, [...$refs, $value], $native, $depth + 1);
            }
            if ($key === 'allOf' && $native && $this->hasRef($value)) { throw new ContractViolation(ErrorCode::CapabilityUnavailable); }
            if ($native && $key === 'enum' && is_array($value)) {
                foreach ($value as $entry) { if (is_object($entry) || is_array($entry)) { throw new ContractViolation(ErrorCode::CapabilityUnavailable); } }
            }
            if ($native && $key === 'minItems' && !in_array($value, [0, 1], true)) { throw new ContractViolation(ErrorCode::CapabilityUnavailable); }
            if ($native && $key === 'format' && !in_array($value, ['date-time','time','date','duration','email','hostname','uri','ipv4','ipv6','uuid'], true)) { throw new ContractViolation(ErrorCode::CapabilityUnavailable); }
            if (in_array($key, ['properties','$defs','definitions','patternProperties','dependentSchemas'], true)) {
                if (!$value instanceof \stdClass) { throw new ContractViolation(ErrorCode::InvalidInput); }
                foreach (get_object_vars($value) as $entry) { $this->inspect($entry, $root, $refs, $native, $depth + 1); }
            } elseif (in_array($key, ['anyOf','allOf','oneOf','prefixItems'], true)) {
                if (!is_array($value) || $value === []) { throw new ContractViolation(ErrorCode::InvalidInput); }
                foreach ($value as $entry) { $this->inspect($entry, $root, $refs, $native, $depth + 1); }
            } elseif (in_array($key, ['items','additionalProperties','not','if','then','else','contains','propertyNames'], true)) {
                $this->inspect($value, $root, $refs, $native, $depth + 1);
            }
        }
        if ($native && (($node->type ?? null) === 'object' || isset($node->properties)) && ($node->additionalProperties ?? null) !== false) {
            throw new ContractViolation(ErrorCode::CapabilityUnavailable);
        }
    }
    private function pointer(\stdClass $root, string $ref): mixed
    {
        if ($ref === '#') { return $root; }
        if (!str_starts_with($ref, '#/')) { throw new ContractViolation(ErrorCode::InvalidInput); }
        $node = $root;
        foreach (explode('/', substr($ref, 2)) as $part) {
            $key = str_replace(['~1','~0'], ['/','~'], rawurldecode($part));
            if (!$node instanceof \stdClass || !property_exists($node, $key)) { throw new ContractViolation(ErrorCode::InvalidInput); }
            $node = $node->{$key};
        }
        return $node;
    }
    private function hasRef(mixed $node): bool
    {
        if ($node instanceof \stdClass) {
            if (property_exists($node, '$ref')) { return true; }
            $node = get_object_vars($node);
        }
        if (is_array($node)) { foreach ($node as $value) { if ($this->hasRef($value)) { return true; } } }
        return false;
    }
}
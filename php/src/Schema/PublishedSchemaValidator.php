<?php

declare(strict_types=1);

namespace AgenTools\Schema;

use Opis\JsonSchema\Parsers\SchemaParser;
use Opis\JsonSchema\Resolvers\SchemaResolver;
use Opis\JsonSchema\SchemaLoader;
use Opis\JsonSchema\Validator;
use Opis\JsonSchema\Errors\ValidationError;

/** Validates documents only against the revision's locally published schemas. */
final class PublishedSchemaValidator
{
    private Validator $validator;

    public function __construct(string $schemaDirectory)
    {
        $resolver = new SchemaResolver();
        $files = glob(rtrim($schemaDirectory, '/') . '/*.json');
        if ($files === false || $files === []) {
            throw new \InvalidArgumentException('The published schema directory is empty.');
        }

        foreach ($files as $file) {
            $json = file_get_contents($file);
            if (!is_string($json)) {
                throw new \RuntimeException(sprintf('Cannot read schema file %s.', $file));
            }
            $schema = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
            if (!is_object($schema)) {
                throw new \RuntimeException(sprintf('Schema file %s does not contain an object.', $file));
            }
            if (!$resolver->registerRaw($schema)) {
                throw new \RuntimeException(sprintf('Cannot register schema file %s.', $file));
            }
        }

        $this->validator = new Validator(new SchemaLoader(new SchemaParser(), $resolver, true));
    }

    /** @param array<string, mixed> $document */
    public function assertValid(array $document, string $schemaId): void
    {
        $data = json_decode(json_encode($document, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
        $result = $this->validator->validate($data, $schemaId);
        if (!$result->hasError()) {
            return;
        }

        $error = $result->error();
        if ($error === null) {
            throw new \LogicException('A failed schema validation did not provide an error.');
        }
        $error = $this->leafError($error);
        throw new SchemaValidationException(
            array_values($error->data()->fullPath()),
            $error->keyword(),
            sprintf('Schema validation failed at /%s (%s): %s', implode('/', $error->data()->fullPath()), $error->keyword(), $error->message()),
        );
    }

    private function leafError(ValidationError $error): ValidationError
    {
        $first = $error->subErrors()[0] ?? null;
        return $first instanceof ValidationError ? $this->leafError($first) : $error;
    }
}

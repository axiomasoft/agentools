<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Actions;

interface InputSchemaValidator
{
    /** Local validation only; throw on invalid JSON/schema, unresolved refs or invalid input. */
    public function assertValid(string $schemaJson, string $inputJson): void;
}

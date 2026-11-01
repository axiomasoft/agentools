<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Examples\DirectAction;

use AgenToolsPlatform\Core\Actions\InputSchemaValidator;
use AgenToolsPlatform\Core\Common\{ContractViolation, ErrorCode};

/** Deliberately implements only the one code-owned example schema; unknown schemas fail closed. */
final readonly class InputSchema implements InputSchemaValidator
{
    public const JSON = '{"type":"object","required":["query"],"properties":{"query":{"type":"string"}},"additionalProperties":false}';

    public function assertValid(string $schemaJson, string $inputJson): void
    {
        $value = json_decode($inputJson, false, 512, JSON_THROW_ON_ERROR);
        if ($schemaJson !== (new Jcs())->canonicalizeJson(self::JSON) || !$value instanceof \stdClass
            || array_keys(get_object_vars($value)) !== ['query'] || !is_string($value->query)) {
            throw new ContractViolation(ErrorCode::InvalidInput);
        }
    }
}

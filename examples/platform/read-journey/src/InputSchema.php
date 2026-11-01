<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Examples\ReadJourney;

use AgenToolsPlatform\Core\Actions\InputSchemaValidator;
use AgenToolsPlatform\Core\Common\{ContractViolation, ErrorCode};

/** The single code-pinned example schema; never claims to be a general JSON Schema engine. */
final readonly class InputSchema implements InputSchemaValidator
{
    public function __construct(private string $pinnedSchema) {}
    public function assertValid(string $schemaJson, string $inputJson): void
    {
        if($schemaJson!==$this->pinnedSchema){throw new ContractViolation(ErrorCode::IntegrityConflict);}
        $object=json_decode($inputJson,false,32,JSON_THROW_ON_ERROR);
        if(!$object instanceof \stdClass || get_object_vars($object)!==['ticket_ref'=>'ST-17']){throw new ContractViolation(ErrorCode::InvalidInput);}
    }
}

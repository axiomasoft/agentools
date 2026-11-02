<?php

declare(strict_types=1);
namespace AgenToolsPlatform\Core\Budget;
final class AdmissionConflict extends \RuntimeException
{
    public function __construct() { parent::__construct('ADMISSION_PAYLOAD_CONFLICT'); }
}

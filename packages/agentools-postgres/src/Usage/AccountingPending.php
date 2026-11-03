<?php

declare(strict_types=1);
namespace AgenToolsPlatform\Postgres\Usage;

/** Redacted technical error; never wrap raw owner exceptions or their messages. */
final class AccountingPending extends \RuntimeException
{
    public function __construct() { parent::__construct('ACCOUNTING_PENDING_RECONCILIATION'); }
}

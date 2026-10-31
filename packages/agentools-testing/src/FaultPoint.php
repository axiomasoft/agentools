<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Testing;

enum FaultPoint: string
{
    case BeforeCommit = 'before_commit';
    case AfterCommit = 'after_commit';
    case BeforeExternal = 'before_external';
    case AfterExternal = 'after_external';
    case BeforeDisclosure = 'before_disclosure';
    case AfterDisclosure = 'after_disclosure';
}
